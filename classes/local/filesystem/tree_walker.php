<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_moodleclone\local\filesystem;

use tool_moodleclone\local\backup\backup_exception;

/**
 * Walks a directory tree without ever following a symbolic link.
 *
 * Guarantees for every yielded entry:
 *  - the relative path passes path_validator::is_safe_relative_path() and is valid UTF-8;
 *  - every directory descended into resolves (realpath) to exactly root/relative,
 *    so no parent component is a symlink;
 *  - symlinks are yielded as links (never followed) and only when their target,
 *    resolved lexically and, if it exists, physically, stays inside the root.
 *
 * Anything else stops the walk with a backup_exception: a clone with silently
 * missing content is worse than a clear failure. Entries that disappear while
 * walking (normal on a live site) and special files (sockets, FIFOs, devices)
 * are reported through the warning callback and skipped.
 *
 * Entries are yielded in sorted, depth-first order, so the same tree always
 * produces the same archive order.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tree_walker {
    /** @var string Real path of the root, forward slashes, no trailing slash. */
    private $root;

    /** @var string|null Root as configured, when it differs from the real path (absolute link targets may use either). */
    private $configuredroot;

    /** @var callable|null fn(string $relative): bool, false prunes the entry (and its subtree). */
    private $filter;

    /** @var callable|null fn(string $code, string $relative): void. */
    private $onwarning;

    /**
     * Constructor.
     *
     * @param string $root Existing directory.
     * @param callable|null $filter Inclusion filter on relative paths.
     * @param callable|null $onwarning Receives ("vanished"|"special", relative path).
     */
    public function __construct(string $root, ?callable $filter = null, ?callable $onwarning = null) {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new backup_exception('sourcemissing', basename($root));
        }
        $this->root = rtrim(str_replace('\\', '/', $real), '/');
        $configured = rtrim(str_replace('\\', '/', $root), '/');
        $this->configuredroot = $configured !== $this->root ? $configured : null;
        $this->filter = $filter;
        $this->onwarning = $onwarning;
    }

    /**
     * Real path of the root.
     *
     * @return string
     */
    public function get_root(): string {
        return $this->root;
    }

    /**
     * Walk the tree.
     *
     * @return \Generator|tree_entry[]
     */
    public function walk(): \Generator {
        yield from $this->walk_directory('');
    }

    /**
     * Walk one directory.
     *
     * @param string $relative
     * @return \Generator
     */
    private function walk_directory(string $relative): \Generator {
        $absolute = $relative === '' ? $this->root : $this->root . '/' . $relative;

        // No component of the path may be a symlink (the entry itself was lstat()ed
        // as a directory, but a parent could have been swapped since).
        $real = realpath($absolute);
        if ($real === false) {
            $this->warn('vanished', $relative);
            return;
        }
        if (rtrim(str_replace('\\', '/', $real), '/') !== $absolute) {
            throw new backup_exception('pathescape', $relative);
        }

        $names = @scandir($absolute);
        if ($names === false) {
            clearstatcache(true, $absolute);
            if (!file_exists($absolute)) {
                $this->warn('vanished', $relative);
                return;
            }
            throw new backup_exception('unreadable', $relative === '' ? '.' : $relative);
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $childrelative = $relative === '' ? $name : $relative . '/' . $name;
            if (!path_validator::is_safe_relative_path($childrelative) || !preg_match('//u', $name)) {
                throw new backup_exception('unsafename', $childrelative);
            }
            if ($this->filter !== null && !($this->filter)($childrelative)) {
                continue;
            }

            $childabsolute = $absolute . '/' . $name;
            $stat = @lstat($childabsolute);
            if ($stat === false) {
                $this->warn('vanished', $childrelative);
                continue;
            }

            $entry = new tree_entry();
            $entry->relative = $childrelative;
            $entry->path = $childabsolute;
            $entry->mode = $stat['mode'] & 07777;
            $entry->mtime = (int) $stat['mtime'];
            $entry->ctime = (int) $stat['ctime'];
            $entry->dev = (int) $stat['dev'];
            $entry->ino = (int) $stat['ino'];

            switch ($stat['mode'] & 0170000) {
                case 0040000:
                    $entry->type = tree_entry::DIRECTORY;
                    yield $entry;
                    yield from $this->walk_directory($childrelative);
                    break;

                case 0100000:
                    $entry->type = tree_entry::FILE;
                    $entry->size = (int) $stat['size'];
                    yield $entry;
                    break;

                case 0120000:
                    $entry->type = tree_entry::SYMLINK;
                    $entry->linktarget = $this->resolve_link($childrelative, $childabsolute);
                    yield $entry;
                    break;

                default:
                    $this->warn('special', $childrelative);
            }
        }
    }

    /**
     * Validate a symlink and return its target relative to the link's directory.
     *
     * @param string $relative Link path relative to the root.
     * @param string $absolute Link absolute path.
     * @return string
     * @throws backup_exception When the target leaves the root.
     */
    private function resolve_link(string $relative, string $absolute): string {
        $target = @readlink($absolute);
        if ($target === false) {
            throw new backup_exception('unreadable', $relative);
        }
        $targetrelative = path_validator::resolve_link_target($this->root, $relative, $target, $this->configuredroot);
        if ($targetrelative === null) {
            throw new backup_exception('externalsymlink', $relative);
        }
        // Lexically inside; if the target exists, following it (including any
        // chain of further links) must also end inside the root.
        $real = realpath($absolute);
        if ($real !== false && !path_validator::is_within($this->root, $real)) {
            throw new backup_exception('externalsymlink', $relative);
        }
        $linkdir = strpos($relative, '/') === false ? '' : substr($relative, 0, strrpos($relative, '/'));
        return path_validator::relative_path($linkdir, $targetrelative);
    }

    /**
     * Report a skipped entry.
     *
     * @param string $code
     * @param string $relative
     * @return void
     */
    private function warn(string $code, string $relative): void {
        if ($this->onwarning !== null) {
            ($this->onwarning)($code, $relative);
        }
    }
}
