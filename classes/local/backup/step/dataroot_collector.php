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
use tool_moodleclone\local\backup\content_policy;
use tool_moodleclone\local\backup\options;
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\filesystem\tree_entry;
use tool_moodleclone\local\filesystem\tree_walker;
use tool_moodleclone\local\package\layout;
use tool_moodleclone\local\package\manifest;
use tool_moodleclone\local\package\vanished_file_exception;

/**
 * Adds persistent moodledata under moodledata/.
 *
 * Uses content_policy::for_site_dataroot() (Phase 1 exclusions plus
 * directories config.php relocates inside dataroot). When $CFG->filedir
 * points elsewhere, that file pool is collected as moodledata/filedir.
 *
 * Snapshot-consistent file pool: this step runs after the database dump.
 * Moodle moves a file's content to the trash directory (rename) as soon as
 * the last reference is deleted. Content moved there after the database
 * snapshot started is still referenced by the dumped database, so it is
 * taken from the trash and stored at its normal filedir location. Only files
 * whose name is a valid content hash, whose SHA-1 matches that name and whose
 * inode change time is after the snapshot (minus a 60 s margin) are used.
 * The trash directory itself is never packaged.
 *
 * Compression: filedir content is stored (mostly already-compressed media),
 * everything else is deflated.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dataroot_collector extends tree_collector {
    /** @var int Seconds of margin before the snapshot time for trash recovery. */
    public const TRASH_MARGIN = 60;

    /**
     * The stage this step performs.
     *
     * @return string
     */
    public function get_stage(): string {
        return stage::DATAROOT;
    }

    /**
     * Included when moodledata is selected.
     *
     * @param options $options
     * @return bool
     */
    public function is_included(options $options): bool {
        return (bool) $options->includedataroot;
    }

    /**
     * Collect.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        $sources = $state->sources;
        $stats = self::empty_stats() + ['recovered_from_trash' => 0];
        $done = 0;
        $total = (int) ($state->estimate[manifest::CONTENT_MOODLEDATA]['bytes'] ?? 0);
        $policy = content_policy::for_site_dataroot($sources);
        $isfiledir = function (string $relative): bool {
            return $relative === 'filedir' || strpos($relative, 'filedir/') === 0;
        };

        $this->collect_tree(
            $state,
            $sources->dataroot,
            layout::DATA_DIR,
            $policy->get_filter(),
            function (string $relative) use ($isfiledir) {
                return !$isfiledir($relative);
            },
            $stats,
            $total,
            $done
        );

        if ($sources->customfiledir) {
            $state->zip->add_directory(layout::DATA_DIR . '/filedir', 0700, time());
            $stats['directories']++;
            $this->collect_tree(
                $state,
                $sources->filedir,
                layout::DATA_DIR . '/filedir',
                null,
                function () {
                    return false;
                },
                $stats,
                $total,
                $done
            );
        }

        if ($state->snapshottime !== null && $sources->trashdir !== null) {
            $stats['recovered_from_trash'] = $this->recover_from_trash($state, $stats);
        }

        $state->statistics[manifest::CONTENT_MOODLEDATA] = $stats;
    }

    /**
     * Store trashed content that the dumped database may still reference.
     *
     * @param backup_state $state
     * @param array $stats Updated with the added files.
     * @return int Number of files recovered.
     */
    private function recover_from_trash(backup_state $state, array &$stats): int {
        $sources = $state->sources;
        if (!is_dir($sources->trashdir)) {
            return 0;
        }
        $threshold = $state->snapshottime - self::TRASH_MARGIN;
        $recovered = 0;
        $walker = new tree_walker($sources->trashdir, null, function () {
            // Trash entries vanish when Moodle recovers or purges them; nothing to report.
        });
        foreach ($walker->walk() as $entry) {
            $hash = basename($entry->relative);
            if ($entry->type !== tree_entry::FILE || $entry->ctime < $threshold || !preg_match('/^[0-9a-f]{40}$/', $hash)) {
                continue;
            }
            $relative = 'filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
            // The pool may not exist yet (e.g. every file was deleted); Moodle then uses dataroot/filedir.
            $pool = $sources->filedir ?? $sources->dataroot . '/filedir';
            if (file_exists($pool . '/' . substr($relative, strlen('filedir/')))) {
                // Present in the pool again, so it was archived by the main walk.
                continue;
            }
            if (@sha1_file($entry->path) !== $hash) {
                // Same check Moodle applies before recovering from trash.
                continue;
            }
            try {
                $result = $state->zip->add_file(layout::DATA_DIR . '/' . $relative, $entry->path, false, null, $entry);
            } catch (vanished_file_exception $e) {
                continue;
            }
            $state->checksums->add(layout::DATA_DIR . '/' . $relative, $result['sha256']);
            $stats['files']++;
            $stats['bytes'] += $result['size'];
            $recovered++;
            $state->check_cancelled();
        }
        return $recovered;
    }
}
