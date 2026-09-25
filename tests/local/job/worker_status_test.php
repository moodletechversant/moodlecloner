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

namespace tool_moodleclone\local\job;

/**
 * Tests for the background worker diagnostics.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\job\worker_status
 */
class worker_status_test extends \advanced_testcase {

    public function test_ready_when_cron_ran_recently(): void {
        $this->resetAfterTest();
        set_config('lastcronstart', time() - 30, 'tool_task');
        $status = worker_status::get();
        $this->assertTrue($status['taskregistered']);
        $this->assertTrue(worker_status::is_ready($status));
        $this->assertNull(worker_status::reason($status));
        $this->assertSame('ok', worker_status::check($status)['status']);
    }

    public function test_cron_not_running_explains_the_fix(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('lastcronstart', time() - 2 * YEARSECS, 'tool_task');
        $status = worker_status::get();

        $this->assertFalse(worker_status::is_ready($status));
        $this->assertTrue(worker_status::needs_cron_setup($status));
        $check = worker_status::check($status);
        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString($CFG->dirroot . '/admin/cli/cron.php', $check['message']);
        $this->assertStringContainsString('crontab -u ' . $status['webuser'], $check['message']);
    }

    public function test_never_ran(): void {
        $this->resetAfterTest();
        unset_config('lastcronstart', 'tool_task');
        $this->assertFalse(worker_status::is_ready());
    }

    public function test_disabled_task_and_cron(): void {
        $this->resetAfterTest();
        set_config('lastcronstart', time(), 'tool_task');
        $task = \core\task\manager::get_scheduled_task(\tool_moodleclone\task\process_backups::class);
        $task->set_disabled(true);
        \core\task\manager::configure_scheduled_task($task);
        $this->assertStringContainsString('disabled', worker_status::reason());

        $task->set_disabled(false);
        \core\task\manager::configure_scheduled_task($task);
        set_config('cron_enabled', 0);
        $this->assertStringContainsString('Cron is disabled', worker_status::reason());
    }

    public function test_recorded_problem_is_shown_then_cleared(): void {
        $this->resetAfterTest();
        set_config('lastcronstart', time(), 'tool_task');
        worker_status::record_problem('cron runs as root');
        $this->assertSame('cron runs as root', worker_status::reason());
        $this->assertFalse(worker_status::needs_cron_setup());

        worker_status::clear_problem();
        $this->assertNull(worker_status::reason());
    }
}
