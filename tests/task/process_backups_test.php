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

use tool_moodleclone\local\backup\options;
use tool_moodleclone\local\job\job;
use tool_moodleclone\local\job\queue;
use tool_moodleclone\local\job\runner;

/**
 * Tests for the scheduled task.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\task\process_backups
 */
class process_backups_test extends \advanced_testcase {
    public function test_does_nothing_while_another_backup_holds_the_lock(): void {
        $this->resetAfterTest();
        $job = queue::create(2, job::ORIGIN_WEB, new options());
        $lock = runner::acquire_lock(0);
        try {
            ob_start();
            (new process_backups())->execute();
            $output = ob_get_clean();
        } finally {
            $lock->release();
        }
        $this->assertStringContainsString(get_string('task:locked', 'tool_moodleclone'), $output);
        $this->assertSame(job::STATUS_PENDING, (new job((int) $job->get('id')))->get('status'));
    }

    public function test_recovers_interrupted_job_when_idle(): void {
        $this->resetAfterTest();
        $job = queue::create(2, job::ORIGIN_WEB, new options());
        job::change_status((int) $job->get('id'), job::STATUS_PENDING, job::STATUS_RUNNING);

        ob_start();
        (new process_backups())->execute();
        ob_end_clean();

        $this->assertSame(job::STATUS_FAILED, (new job((int) $job->get('id')))->get('status'));
        $this->assertNull(queue::next_pending());
    }

    public function test_registered_every_minute(): void {
        $task = \core\task\manager::get_scheduled_task(process_backups::class);
        $this->assertNotNull($task);
        $this->assertSame('*', $task->get_minute());
    }

    public function test_wrong_os_user_runs_nothing_and_records_problem(): void {
        if (!\tool_moodleclone\local\environment\os_identity::is_supported()) {
            $this->markTestSkipped('OS users cannot be determined on this platform');
        }
        $this->resetAfterTest();
        $job = queue::create(2, job::ORIGIN_WEB, new options());
        set_config('webuid', posix_geteuid() + 1, 'tool_moodleclone');
        set_config('webuser', 'webserveruser', 'tool_moodleclone');

        ob_start();
        (new process_backups())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('webserveruser', $output);
        $this->assertSame(job::STATUS_PENDING, (new job((int) $job->get('id')))->get('status'));
        $this->assertStringContainsString('webserveruser', \tool_moodleclone\local\job\worker_status::reason());

        // Once cron runs as the right user again, the recorded problem is cleared.
        unset_config('webuid', 'tool_moodleclone');
        unset_config('webuser', 'tool_moodleclone');
        $lock = runner::acquire_lock(0);
        ob_start();
        (new process_backups())->execute();
        ob_end_clean();
        $lock->release();
        $this->assertNull(get_config('tool_moodleclone', 'workerproblem') ?: null);
    }
}
