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

namespace tool_moodleclone\task;

use tool_moodleclone\local\job\runner;
use tool_moodleclone\local\job\worker_status;

/**
 * Processes queued Moodle Clone backups.
 *
 * Runs every minute and returns at once unless there is work. This is the
 * normal production worker: a backup queued from the admin page starts at the
 * next cron run. It first checks that cron runs as the web server's OS user
 * (otherwise packages would be unreadable for download); if not, it runs
 * nothing, leaves jobs queued and records the problem for the admin page.
 * With the backup lock held it then recovers jobs whose worker died (marks
 * them failed and deletes their temporary files) and runs the oldest pending
 * job. One job per execution; a failed job is recorded, not retried.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_backups extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:processbackups', 'tool_moodleclone');
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute() {
        $runner = new runner();
        if ($problem = $runner->get_worker_problem()) {
            mtrace($problem);
            worker_status::record_problem($problem);
            return;
        }
        worker_status::clear_problem();

        $lock = runner::acquire_lock(0);
        if (!$lock) {
            mtrace(get_string('task:locked', 'tool_moodleclone'));
            return;
        }
        try {
            $runner->recover_interrupted();
            $job = $runner->run_next();
            if ($job !== null) {
                mtrace(get_string(
                    'task:finished',
                    'tool_moodleclone',
                    ['id' => $job->get('id'), 'status' => $job->get('status')]
                ));
            }
        } finally {
            $lock->release();
        }
    }
}
