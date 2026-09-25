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

namespace tool_moodleclone\local\package;

use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\local\job\job;

/**
 * Decides whether a job's package can be served, and where it is.
 *
 * Used by download.php (before send_file()), by the admin page (to show a
 * download button or explain why not) and by the tests, so all three apply
 * exactly the same rules: completed job, valid package name, a regular file in
 * the packages directory, readable by this (web server) process.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_download {
    /**
     * Path of a downloadable package.
     *
     * @param job $job
     * @param workspace $workspace
     * @return string
     * @throws \moodle_exception With a message an administrator can act on.
     */
    public static function resolve(job $job, workspace $workspace): string {
        if ($job->get('status') !== job::STATUS_COMPLETED || $job->get('filename') === null) {
            throw new \moodle_exception('error:notdownloadable', 'tool_moodleclone');
        }
        try {
            $path = $workspace->get_package_path($job->get('filename'));
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('error:notdownloadable', 'tool_moodleclone');
        }
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            throw new \moodle_exception('error:packagemissing', 'tool_moodleclone');
        }
        if (!is_readable($path)) {
            $owner = os_identity::owner($path);
            $current = os_identity::current();
            throw new \moodle_exception('error:packageunreadable', 'tool_moodleclone', '', (object) [
                'owner' => $owner['name'] ?? '?',
                'user' => $current['name'] ?? '?',
            ]);
        }
        return $path;
    }

    /**
     * Why a completed job's package cannot be served, or null when it can.
     *
     * @param job $job
     * @param workspace $workspace
     * @return string|null
     */
    public static function problem(job $job, workspace $workspace): ?string {
        try {
            self::resolve($job, $workspace);
            return null;
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }
    }
}
