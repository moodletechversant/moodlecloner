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

namespace tool_moodleclone\local\backup\step;

use tool_moodleclone\local\backup\backup_state;
use tool_moodleclone\local\filesystem\tree_entry;
use tool_moodleclone\local\filesystem\tree_walker;
use tool_moodleclone\local\package\vanished_file_exception;

/**
 * Shared logic for steps that archive a directory tree.
 *
 * Files are streamed straight into the archive (never staged), their SHA-256
 * is taken from the same bytes and appended to checksums.sha256, and
 * symlinks are archived as links only after tree_walker confirmed their
 * target stays inside the root.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class tree_collector implements optional_step {

    /**
     * Implemented.
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Archive a tree.
     *
     * @param backup_state $state
     * @param string $root Real source directory.
     * @param string $entryprefix Archive directory, e.g. "moodle" or "moodledata/filedir".
     * @param callable|null $filter Inclusion filter on paths relative to $root.
     * @param callable $compress fn(string $relative): bool
     * @param array $stats Statistics array, updated.
     * @param int $totalbytes Expected bytes for progress (0 if unknown).
     * @param int $donebytes Bytes processed so far, updated.
     * @return void
     */
    protected function collect_tree(backup_state $state, string $root, string $entryprefix, ?callable $filter,
            callable $compress, array &$stats, int $totalbytes, int &$donebytes): void {
        $walker = new tree_walker($root, $filter, function(string $code, string $relative) use ($state, $entryprefix) {
            $state->warn($code, $entryprefix . '/' . $relative);
        });
        $report = function(int $bytes) use ($state, &$donebytes, $totalbytes) {
            $donebytes += $bytes;
            if ($totalbytes > 0) {
                $state->progress(min(0.99, $donebytes / $totalbytes));
            } else {
                $state->check_cancelled();
            }
        };

        foreach ($walker->walk() as $entry) {
            $name = $entryprefix . '/' . $entry->relative;
            switch ($entry->type) {
                case tree_entry::DIRECTORY:
                    $state->zip->add_directory($name, $entry->mode, $entry->mtime);
                    $stats['directories']++;
                    break;

                case tree_entry::SYMLINK:
                    $state->zip->add_symlink($name, $entry->linktarget, $entry->mtime);
                    $stats['symlinks']++;
                    break;

                case tree_entry::FILE:
                    try {
                        $result = $state->zip->add_file($name, $entry->path, $compress($entry->relative), $report, $entry);
                    } catch (vanished_file_exception $e) {
                        // Deleted between listing and reading: it is no longer part of the site.
                        $state->warn('vanished', $name);
                        break;
                    }
                    $state->checksums->add($name, $result['sha256']);
                    $stats['files']++;
                    $stats['bytes'] += $result['size'];
                    break;
            }
            if ($entry->type !== tree_entry::FILE) {
                $state->check_cancelled();
            }
        }
    }

    /**
     * Empty statistics.
     *
     * @return array
     */
    protected static function empty_stats(): array {
        return ['files' => 0, 'directories' => 0, 'symlinks' => 0, 'bytes' => 0];
    }
}
