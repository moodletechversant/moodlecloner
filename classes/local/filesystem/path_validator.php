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

/**
 * Path validation used everywhere a path crosses a trust boundary.
 *
 * Two kinds of path are handled:
 *  - package-relative paths (archive entries, checksum lines, manifest values),
 *    which must never be absolute or contain "..";
 *  - absolute paths on this server, which must resolve (after following
 *    symlinks) to a location inside an allowed base directory.
 *
 * Paths are always derived from $CFG or from package constants, never taken
 * directly from a request. This class is the second line of defence.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_validator {

    /**
     * Whether a package-relative path is safe.
     *
     * Safe means: not empty, forward slashes only, not absolute, no drive
     * letter, no "." / ".." / empty segments, no control characters.
     *
     * @param string $path
     * @return bool
     */
    public static function is_safe_relative_path(string $path): bool {
        if ($path === '' || strlen($path) > 4096) {
            return false;
        }
        // Control characters, including NUL, which truncates paths in some C APIs.
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }
        // Backslashes are separators on Windows and would bypass the segment checks below.
        if (strpos($path, '\\') !== false) {
            return false;
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Return the path unchanged if it is a safe package-relative path.
     *
     * @param string $path
     * @return string
     * @throws invalid_path_exception
     */
    public static function require_safe_relative_path(string $path): string {
        if (!self::is_safe_relative_path($path)) {
            throw new invalid_path_exception('unsaferelativepath');
        }
        return $path;
    }

    /**
     * Whether $path is $base itself or lies inside it, once symlinks are resolved.
     *
     * Both paths must exist; a non-existent path is never considered inside.
     *
     * @param string $base Absolute directory path.
     * @param string $path Absolute path to test.
     * @return bool
     */
    public static function is_within(string $base, string $path): bool {
        $realbase = self::real($base);
        $realpath = self::real($path);
        if ($realbase === null || $realpath === null) {
            return false;
        }
        return $realpath === $realbase || strpos($realpath, rtrim($realbase, '/') . '/') === 0;
    }

    /**
     * Join a base directory and a package-relative path, guaranteeing the result stays inside the base.
     *
     * The target does not need to exist (so it can be used for files about to be
     * written), but its deepest existing ancestor must resolve inside the base,
     * which prevents escaping through a symlinked directory.
     *
     * @param string $base Existing absolute directory.
     * @param string $relative Package-relative path.
     * @return string Absolute path using forward slashes.
     * @throws invalid_path_exception
     */
    public static function resolve_within(string $base, string $relative): string {
        self::require_safe_relative_path($relative);

        $realbase = self::real($base);
        if ($realbase === null || !is_dir($realbase)) {
            throw new invalid_path_exception('basedirmissing');
        }

        $target = rtrim($realbase, '/') . '/' . $relative;

        $existing = $target;
        while (!file_exists($existing) && !is_link($existing)) {
            $existing = dirname($existing);
        }
        if (!self::is_within($realbase, $existing)) {
            throw new invalid_path_exception('pathescapesbase');
        }
        return $target;
    }

    /**
     * Resolve a symlink target lexically (without touching the filesystem).
     *
     * @param string $root Real path of the tree root, forward slashes.
     * @param string $linkrelative Path of the link relative to the root.
     * @param string $target Raw readlink() value.
     * @param string|null $altroot Configured root when it differs from the real path.
     * @return string|null Target relative to the root ('' for the root itself), or null when it leaves the root.
     */
    public static function resolve_link_target(string $root, string $linkrelative, string $target,
            ?string $altroot = null): ?string {
        if ($target === '' || strpos($target, "\0") !== false) {
            return null;
        }
        if ($target[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $target)) {
            $target = str_replace('\\', '/', $target);
            foreach ([$root, $altroot] as $candidate) {
                if ($candidate === null || $candidate === '') {
                    continue;
                }
                $candidate = rtrim($candidate, '/');
                if ($target === $candidate) {
                    return '';
                }
                if (strpos($target, $candidate . '/') === 0) {
                    return self::normalise_segments(substr($target, strlen($candidate) + 1));
                }
            }
            return null;
        }
        $linkdir = strpos($linkrelative, '/') === false ? '' : substr($linkrelative, 0, strrpos($linkrelative, '/'));
        return self::normalise_segments($linkdir === '' ? $target : $linkdir . '/' . $target);
    }

    /**
     * Relative path from one directory to another path, both relative to the same root.
     *
     * @param string $fromdir Directory relative to the root ('' for the root).
     * @param string $to Target relative to the root ('' for the root).
     * @return string e.g. "../phpunit/phpunit", or "." for the same directory.
     */
    public static function relative_path(string $fromdir, string $to): string {
        $from = $fromdir === '' ? [] : explode('/', $fromdir);
        $dest = $to === '' ? [] : explode('/', $to);
        $common = 0;
        while ($common < count($from) && $common < count($dest) && $from[$common] === $dest[$common]) {
            $common++;
        }
        $parts = array_merge(array_fill(0, count($from) - $common, '..'), array_slice($dest, $common));
        return $parts ? implode('/', $parts) : '.';
    }

    /**
     * Resolve "." and ".." in a relative path without touching the filesystem.
     *
     * @param string $path
     * @return string|null Normalised path ('' for the root), or null when ".." climbs above the root.
     */
    public static function normalise_segments(string $path): ?string {
        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!$stack) {
                    return null;
                }
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }
        $normalised = implode('/', $stack);
        if ($normalised !== '' && !self::is_safe_relative_path($normalised)) {
            return null;
        }
        return $normalised;
    }

    /**
     * realpath() normalised to forward slashes, or null when the path does not exist.
     *
     * @param string $path
     * @return string|null
     */
    private static function real(string $path): ?string {
        if ($path === '' || strpos($path, "\0") !== false) {
            return null;
        }
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        return str_replace('\\', '/', $real);
    }
}
