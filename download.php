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

/**
 * Download a completed Moodle Clone package.
 *
 * The only input is a job id. The file served is always
 * dataroot/moodleclone/packages/<filename> where <filename> comes from a
 * completed job record and must match the package name pattern, so no path
 * can be supplied or influenced by the request, and temporary files (which
 * live in a different directory) can never be served.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

use tool_moodleclone\event\package_downloaded;
use tool_moodleclone\local\access;
use tool_moodleclone\local\job\job;
use tool_moodleclone\local\package\package_download;
use tool_moodleclone\local\package\workspace;

$id = required_param('id', PARAM_INT);
$checksum = optional_param('checksum', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/admin/tool/moodleclone/download.php', ['id' => $id, 'checksum' => $checksum]));
$PAGE->set_context(context_system::instance());

require_login(null, false);
access::require_manage();
require_sesskey();

$job = job::get_record(['id' => $id]);
if (!$job) {
    throw new moodle_exception('error:notdownloadable', 'tool_moodleclone');
}
// Same rules as the admin page: completed job, package name, regular file, readable by this process.
$path = package_download::resolve($job, new workspace());

if ($checksum) {
    // The small .sha256 file that sits next to the package: the installer uses it to verify the copy it is given.
    $sidecar = $path . '.sha256';
    if (!is_file($sidecar) || is_link($sidecar) || !is_readable($sidecar)) {
        throw new moodle_exception('error:packagemissing', 'tool_moodleclone');
    }
    \core\session\manager::write_close();
    send_file($sidecar, $job->get('filename') . '.sha256', 0, 0, false, true, 'text/plain');
}

package_downloaded::create_for_job($job, ['size' => (int) $job->get('packagesize')], (int) $USER->id)->trigger();

\core\session\manager::write_close();
send_file($path, $job->get('filename'), 0, 0, false, true, 'application/zip');
