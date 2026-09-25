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
 * Download the standalone installer (installer.php).
 *
 * The file served is always the fixed path installer/installer.php that
 * ships inside this plugin. There is no user-supplied filename or path, so
 * no path can be influenced by the request. The file is a static template:
 * when it is dropped into a fresh destination and run there, it refuses to
 * run in place inside this plugin's own tree (see the guard at the top of
 * installer.php).
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

use tool_moodleclone\local\access;

$PAGE->set_url(new moodle_url('/admin/tool/moodleclone/installer-download.php'));
$PAGE->set_context(context_system::instance());

require_login(null, false);
access::require_manage();
require_sesskey();

$path = __DIR__ . '/installer/installer.php';
if (!is_file($path)) {
    throw new moodle_exception('error:installernotfound', 'tool_moodleclone');
}

\core\session\manager::write_close();
send_file($path, 'installer.php', 0, 0, false, true, 'application/octet-stream');
