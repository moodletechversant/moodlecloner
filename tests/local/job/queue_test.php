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
use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\workspace;

/**
 * Tests for creating, cancelling and deleting jobs.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\job\queue
 */
class queue_test extends \advanced_testcase {
    public function test_only_one_active_job(): void {
        $this->resetAfterTest();
        $first = queue::create(2, job::ORIGIN_WEB, new options());
        try {
            queue::create(2, job::ORIGIN_WEB, new options());
            $this->fail('A second concurrent job was queued');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:jobactive', $e->errorcode);
        }

        job::change_status((int) $first->get('id'), job::STATUS_PENDING, job::STATUS_RUNNING);
        $this->expectException(\moodle_exception::class);
        queue::create(2, job::ORIGIN_CLI, new options());
    }

    public function test_new_job_allowed_after_previous_finished(): void {
        $this->resetAfterTest();
        $first = queue::create(2, job::ORIGIN_WEB, new options());
        job::change_status((int) $first->get('id'), job::STATUS_PENDING, job::STATUS_CANCELLED);
        $second = queue::create(2, job::ORIGIN_CLI, new options());
        $this->assertSame(job::ORIGIN_CLI, $second->get('origin'));
        $this->assertSame((int) $second->get('id'), (int) queue::next_pending()->get('id'));
    }

    public function test_empty_options_rejected(): void {
        $this->resetAfterTest();
        $options = new options();
        $options->includecode = $options->includedataroot = $options->includedatabase = false;
        $this->expectException(\moodle_exception::class);
        queue::create(2, job::ORIGIN_WEB, $options);
    }

    public function test_a_cancelled_job_does_not_keep_its_password_verifier(): void {
        global $DB;
        $this->resetAfterTest();
        $options = new options();
        $options->installerauth = installer_auth::from_password('correct horse battery staple');
        $verifier = $options->installerauth->to_array()['verifier'];
        $job = queue::create(2, job::ORIGIN_WEB, $options);
        $this->assertStringContainsString($verifier, (string) $DB->get_field(job::TABLE, 'options', ['id' => $job->get('id')]));

        $this->assertSame(job::STATUS_CANCELLED, queue::cancel((int) $job->get('id')));
        $stored = (string) $DB->get_field(job::TABLE, 'options', ['id' => $job->get('id')]);
        $this->assertStringNotContainsString($verifier, $stored);
        $this->assertSame('password', (new job((int) $job->get('id')))->get_installer_mode());
    }

    public function test_options_without_installer_auth_mean_the_key_file(): void {
        $this->resetAfterTest();
        $job = queue::create(2, job::ORIGIN_WEB, new options());
        $this->assertSame('keyfile', $job->get_installer_mode());
        $this->assertSame('keyfile', $job->get_backup_options()->get_installer_auth()->get_mode());
        // A job record from before this feature has no installer_auth at all.
        $old = new job(0, (object) ['userid' => 2, 'origin' => job::ORIGIN_WEB, 'status' => job::STATUS_COMPLETED,
            'options' => '{"moodle":true,"moodledata":true,"database":true}']);
        $this->assertNull($old->get_installer_mode());
        $this->assertSame('keyfile', $old->get_backup_options()->get_installer_auth()->get_mode());
    }

    public function test_cancel_pending_and_running(): void {
        global $DB;
        $this->resetAfterTest();
        $pending = queue::create(2, job::ORIGIN_WEB, new options());
        $this->assertSame(job::STATUS_CANCELLED, queue::cancel((int) $pending->get('id')));

        $running = queue::create(2, job::ORIGIN_WEB, new options());
        $id = (int) $running->get('id');
        job::change_status($id, job::STATUS_PENDING, job::STATUS_RUNNING);
        $this->assertFalse(queue::is_cancel_requested($id));
        $this->assertSame(job::STATUS_RUNNING, queue::cancel($id), 'A running job is asked to stop, not killed');
        $this->assertTrue(queue::is_cancel_requested($id));

        // Cancelling a finished job changes nothing.
        job::change_status($id, job::STATUS_RUNNING, job::STATUS_COMPLETED);
        $DB->set_field(job::TABLE, 'cancelrequested', 0, ['id' => $id]);
        $this->assertSame(job::STATUS_COMPLETED, queue::cancel($id));
        $this->assertFalse(queue::is_cancel_requested($id));
    }

    public function test_delete_removes_package_and_refuses_active_jobs(): void {
        global $DB;
        $this->resetAfterTest();
        $workspace = new workspace(make_request_directory());
        $workspace->prepare();
        $job = queue::create(2, job::ORIGIN_WEB, new options());

        try {
            queue::delete($job, $workspace);
            $this->fail('Active job deleted');
        } catch (\moodle_exception $e) {
            $this->assertTrue($DB->record_exists(job::TABLE, ['id' => $job->get('id')]));
        }

        $filename = 'moodle-clone-2026-01-01-000000.zip';
        file_put_contents($workspace->get_packages_dir() . '/' . $filename, 'zip');
        file_put_contents($workspace->get_packages_dir() . '/' . $filename . '.sha256', 'sum');
        job::change_status((int) $job->get('id'), job::STATUS_PENDING, job::STATUS_RUNNING);
        job::change_status((int) $job->get('id'), job::STATUS_RUNNING, job::STATUS_COMPLETED, ['filename' => $filename]);
        $job = new job((int) $job->get('id'));

        queue::delete($job, $workspace);

        $this->assertFalse($DB->record_exists(job::TABLE, ['filename' => $filename]));
        $this->assertFileDoesNotExist($workspace->get_packages_dir() . '/' . $filename);
        $this->assertFileDoesNotExist($workspace->get_packages_dir() . '/' . $filename . '.sha256');
    }

    public function test_create_from_web_queues_a_full_backup(): void {
        global $DB;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->expectException(\moodle_exception::class);
        }
        $job = queue::create_from_web(2, new workspace(make_request_directory()));
        $this->assertSame(job::STATUS_PENDING, $job->get('status'));
        $this->assertSame(job::ORIGIN_WEB, $job->get('origin'));
        $this->assertTrue($job->get_backup_options()->includedatabase);
    }
}
