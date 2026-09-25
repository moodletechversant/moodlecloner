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

use tool_moodleclone\local\backup\options;

/**
 * Tests for job records and the status state machine.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\job\job
 */
class job_test extends \advanced_testcase {
    /**
     * A new pending job.
     *
     * @return job
     */
    private function create_job(): job {
        $job = new job(0, (object) ['userid' => 2, 'options' => json_encode((new options())->to_contents())]);
        $job->create();
        return $job;
    }

    public function test_creation_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->create_job();

        $this->assertGreaterThan(0, $job->get('id'));
        $this->assertSame(job::STATUS_PENDING, $job->get('status'));
        $this->assertSame(job::ORIGIN_WEB, $job->get('origin'));
        $this->assertSame(0, (int) $job->get('progress'));
        $this->assertNull($job->get('filename'));
        $this->assertTrue($job->is_active());
        $this->assertTrue($job->get_backup_options()->includedatabase);
        $this->assertSame([], $job->get_step_states());
    }

    /**
     * Every pair of statuses.
     *
     * @return array
     */
    public static function transition_provider(): array {
        $cases = [];
        foreach (job::STATUSES as $from) {
            foreach (job::STATUSES as $to) {
                $cases["{$from} -> {$to}"] = [$from, $to, in_array($to, job::TRANSITIONS[$from], true)];
            }
        }
        return $cases;
    }

    /**
     * The state machine: pending -> running/cancelled/failed, running -> completed/failed/cancelled, final states stay final.
     *
     * @dataProvider transition_provider
     * @param string $from
     * @param string $to
     * @param bool $allowed
     */
    public function test_transitions(string $from, string $to, bool $allowed): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->create_job();
        $DB->set_field(job::TABLE, 'status', $from, ['id' => $job->get('id')]);

        if (!$allowed) {
            $this->expectException(\moodle_exception::class);
        }
        $this->assertTrue(job::change_status((int) $job->get('id'), $from, $to));
        $this->assertSame($to, $DB->get_field(job::TABLE, 'status', ['id' => $job->get('id')]));
    }

    public function test_change_status_requires_expected_current_status(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->create_job();
        $id = (int) $job->get('id');

        $this->assertTrue(job::change_status($id, job::STATUS_PENDING, job::STATUS_RUNNING, ['timestarted' => 123]));
        // A second worker (or a stale page) expecting "pending" must not win.
        $this->assertFalse(job::change_status($id, job::STATUS_PENDING, job::STATUS_CANCELLED));
        $this->assertSame(job::STATUS_RUNNING, $DB->get_field(job::TABLE, 'status', ['id' => $id]));
        $this->assertSame(123, (int) $DB->get_field(job::TABLE, 'timestarted', ['id' => $id]));
    }

    public function test_change_status_rejects_unknown_columns(): void {
        $this->resetAfterTest();
        $job = $this->create_job();
        $this->expectException(\coding_exception::class);
        job::change_status((int) $job->get('id'), job::STATUS_PENDING, job::STATUS_RUNNING, ['userid' => 5]);
    }

    public function test_progress_updates_only_while_running(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->create_job();
        $id = (int) $job->get('id');

        job::update_progress($id, ['progress' => 40]);
        $this->assertSame(0, (int) $DB->get_field(job::TABLE, 'progress', ['id' => $id]), 'Pending jobs are not updated');

        job::change_status($id, job::STATUS_PENDING, job::STATUS_RUNNING);
        job::update_progress($id, ['progress' => 40, 'currentstep' => 'code', 'steps' => '{"code":{"status":"running"}}']);
        $record = $DB->get_record(job::TABLE, ['id' => $id]);
        $this->assertSame(40, (int) $record->progress);
        $this->assertSame('code', $record->currentstep);
    }

    public function test_invalid_filename_is_rejected(): void {
        $this->resetAfterTest();
        $job = new job(0, (object) ['filename' => '../../config.php']);
        $this->expectException(\core\invalid_persistent_exception::class);
        $job->create();
    }

    public function test_get_active(): void {
        $this->resetAfterTest();
        $this->assertNull(job::get_active());
        $job = $this->create_job();
        $this->assertSame((int) $job->get('id'), (int) job::get_active()->get('id'));
        job::change_status((int) $job->get('id'), job::STATUS_PENDING, job::STATUS_CANCELLED);
        $this->assertNull(job::get_active());
    }
}
