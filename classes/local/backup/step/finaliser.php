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

use tool_moodleclone\local\backup\backup_exception;
use tool_moodleclone\local\backup\backup_state;
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\package\layout;
use tool_moodleclone\local\package\package_verifier;
use tool_moodleclone\local\package\workspace;

/**
 * Verifies the temporary archive and publishes it atomically.
 *
 * <pre>
 * work/job-N/package.zip.part   (written by the previous steps)
 *        |  package_verifier: structure, manifest, every file's SHA-256
 *        |  SHA-256 of the whole archive
 *        v
 * rename() into packages/moodle-clone-YYYY-MM-DD-HHMMSS.zip   (same filesystem: atomic)
 *        + packages/moodle-clone-...zip.sha256 (also written via rename)
 * </pre>
 *
 * Only then does the runner mark the job completed, which is the only thing
 * that makes it downloadable.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finaliser implements step {
    /**
     * The stage this step performs.
     *
     * @return string
     */
    public function get_stage(): string {
        return stage::FINALISING;
    }

    /**
     * Implemented.
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Verify and publish.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        $temp = $state->zip->get_path();
        if (!$state->zip->is_finished()) {
            throw new \coding_exception('The archive must be finished before it is published');
        }

        $verifier = new package_verifier(function (float $fraction) use ($state) {
            $state->progress($fraction * 0.7);
        });
        $verifier->verify($temp, $state->zip->get_entry_count());

        $sha256 = package_verifier::hash_file($temp, function (float $fraction) use ($state) {
            $state->progress(0.7 + $fraction * 0.3);
        });
        clearstatcache(true, $temp);
        $size = (int) filesize($temp);

        $filename = layout::filename($state->timestarted);
        $final = $state->workspace->get_package_path($filename);
        if (file_exists($final) || is_link($final)) {
            throw new backup_exception('packageexists', $filename);
        }
        @chmod($temp, 0600);

        // Record the name first, so cleanup after a crash can find a published but unconfirmed file.
        if ($state->onpackagename !== null) {
            ($state->onpackagename)($filename);
        }
        if (!@rename($temp, $final)) {
            throw new backup_exception('publishfailed', $filename);
        }

        $sidecartemp = $state->workdir . '/package.sha256.part';
        if (
            file_put_contents($sidecartemp, $sha256 . '  ' . $filename . "\n") === false ||
                !@rename($sidecartemp, $final . workspace::SIDECAR_SUFFIX)
        ) {
            throw new backup_exception('publishfailed', $filename . workspace::SIDECAR_SUFFIX);
        }
        @chmod($final . workspace::SIDECAR_SUFFIX, 0600);

        $state->result = ['filename' => $filename, 'path' => $final, 'size' => $size, 'sha256' => $sha256];
        $state->progress(1.0);
    }
}
