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
 * Moodle Clone administration page.
 *
 * Creating a package only queues a job; the scheduled task (Moodle cron, the
 * production mechanism) runs it, or cli/backup.php --execute for manual runs.
 * No backup work ever happens inside this request.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use tool_moodleclone\event\package_deleted;
use tool_moodleclone\local\access;
use tool_moodleclone\local\backup\preflight;
use tool_moodleclone\local\environment\collector;
use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\local\job\job;
use tool_moodleclone\local\job\queue;
use tool_moodleclone\local\job\worker_status;
use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\workspace;

// Requires login and the capability declared for the external page in settings.php.
admin_externalpage_setup('tool_moodleclone');
// Explicit check as well, so the rule does not depend on settings.php alone.
access::require_manage();

$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/admin/tool/moodleclone/index.php');
$workspace = new workspace();

// Workers (cron, CLI) must run as the OS user serving this page; remember who that is.
os_identity::record_web_identity();

if ($action === 'create') {
    require_sesskey();
    // The installer password is read raw (it is used byte for byte), hashed at once and never stored or logged.
    $protection = optional_param('installerprotection', installer_auth::MODE_PASSWORD, PARAM_ALPHA);
    $installerauth = null;
    if ($protection === installer_auth::MODE_KEYFILE) {
        $installerauth = installer_auth::keyfile();
    } else if ($protection === installer_auth::MODE_PASSWORD) {
        $password = optional_param('installerpassword', '', PARAM_RAW);
        $problems = installer_auth::password_problems($password, optional_param('installerpassword2', '', PARAM_RAW));
        // Never reuse the administrator's own Moodle password (only the current user's can be checked).
        $account = $DB->get_record('user', ['id' => $USER->id], 'id, auth, password');
        if (!$problems && $account && $account->auth === 'manual' && $account->password !== '' &&
                validate_internal_user_password($account, $password)) {
            $problems[] = 'reused';
        }
        if ($problems) {
            redirect($pageurl, implode(' ', array_map(function($code) {
                return get_string('error:installerpassword_' . $code, 'tool_moodleclone', installer_auth::MIN_LENGTH);
            }, $problems)), null, notification::NOTIFY_ERROR);
        }
        $installerauth = installer_auth::from_password($password);
        unset($password, $_POST['installerpassword'], $_POST['installerpassword2'], $_REQUEST['installerpassword'],
            $_REQUEST['installerpassword2']);
    } else {
        redirect($pageurl, get_string('error:installerprotection', 'tool_moodleclone'), null, notification::NOTIFY_ERROR);
    }
    try {
        $job = queue::create_from_web((int) $USER->id, $workspace, $installerauth);
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, notification::NOTIFY_ERROR);
    }
    $message = worker_status::is_ready() ? 'jobqueued' : 'jobqueuednoworker';
    redirect($pageurl, get_string($message, 'tool_moodleclone', $job->get('id')), null,
        $message === 'jobqueued' ? notification::NOTIFY_SUCCESS : notification::NOTIFY_WARNING);
}

if ($action === 'cancel') {
    require_sesskey();
    $id = required_param('id', PARAM_INT);
    $status = queue::cancel($id);
    $message = $status === job::STATUS_CANCELLED ? 'jobcancelled' : 'cancelrequestsent';
    redirect($pageurl, get_string($message, 'tool_moodleclone', $id), null, notification::NOTIFY_INFO);
}

if ($action === 'delete') {
    $id = required_param('id', PARAM_INT);
    $job = job::get_record(['id' => $id]);
    if (!$job) {
        redirect($pageurl, get_string('error:nojob', 'tool_moodleclone'), null, notification::NOTIFY_ERROR);
    }
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        try {
            $event = package_deleted::create_for_job($job, [], (int) $USER->id);
            queue::delete($job, $workspace);
            $event->trigger();
        } catch (moodle_exception $e) {
            redirect($pageurl, $e->getMessage(), null, notification::NOTIFY_ERROR);
        }
        redirect($pageurl, get_string('jobdeleted', 'tool_moodleclone', $id), null, notification::NOTIFY_SUCCESS);
    }
    require_sesskey();
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'tool_moodleclone'));
    echo $OUTPUT->confirm(get_string('confirmdelete', 'tool_moodleclone', $id),
        new single_button(new moodle_url($pageurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1,
            'sesskey' => sesskey()]), get_string('delete'), 'post'),
        $pageurl);
    echo $OUTPUT->footer();
    die();
}

$active = job::get_active();
if ($active !== null) {
    // Plain periodic reload while a backup is queued or running; no JavaScript needed.
    $PAGE->set_periodic_refresh_delay(10);
}

$snapshot = (new collector())->collect();
$worker = worker_status::get();
$checks = preflight::quick($snapshot, $workspace);
$checks[] = worker_status::check($worker);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_moodleclone'));
echo $OUTPUT->render(new \tool_moodleclone\output\index_page($snapshot, $checks, $pageurl, $active,
    queue::get_recent(20), $workspace, $worker));
echo $OUTPUT->footer();
