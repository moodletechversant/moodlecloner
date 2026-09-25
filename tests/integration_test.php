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

namespace tool_moodleclone;

use tool_moodleclone\local\backup\source_paths;
use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\local\job\job;
use tool_moodleclone\local\job\queue;
use tool_moodleclone\local\job\runner;
use tool_moodleclone\local\job\worker_status;
use tool_moodleclone\local\package\package_download;
use tool_moodleclone\local\package\package_verifier;
use tool_moodleclone\local\package\workspace;
use tool_moodleclone\task\process_backups;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture_helper.php');

/**
 * The whole production path: admin clicks "Create Clone Package", cron runs the
 * scheduled task, the package is published and the download works.
 *
 * Cron is exercised through the same calls admin/cli/scheduled_task.php and
 * cron.php make (cron lock factory, task lock, cron_run_inner_scheduled_task()),
 * so this covers task registration, locking and execution, not just execute().
 * The "web process" is this PHPUnit process: its OS user creates the package
 * and must be able to read it back.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\task\process_backups
 * @covers     \tool_moodleclone\local\job\queue::create_from_web
 * @covers     \tool_moodleclone\local\package\package_download
 */
class integration_test extends \advanced_testcase {
    /**
     * Fixture site; the task's runner archives it instead of the real code tree.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('Backups queued from the web include the database, which is MySQL only');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $dirroot = make_request_directory();
        fixture_helper::make_tree($dirroot, ['index.php' => '<?php', 'config.php' => 'secret', 'lib/a.php' => 'a']);
        $dataroot = make_request_directory();
        fixture_helper::add_pool_file($dataroot . '/filedir', 'an uploaded file');
        runner::set_test_overrides(source_paths::from_config(fixture_helper::config($dirroot, $dataroot)), ['config']);
    }

    /**
     * Remove overrides.
     *
     * @return void
     */
    protected function tearDown(): void {
        runner::set_test_overrides(null);
        parent::tearDown();
    }

    /**
     * Run the scheduled task the way Moodle's cron does.
     *
     * @return string Task output.
     */
    private function run_task_via_cron(): string {
        global $CFG;
        require_once($CFG->libdir . '/cronlib.php');
        $task = \core\task\manager::get_scheduled_task(process_backups::class);
        $this->assertNotNull($task, 'The scheduled task is registered');
        $this->assertFalse($task->get_disabled());
        $factory = \core\lock\lock_config::get_lock_factory('cron');
        $cronlock = $factory->get_lock('core_cron', 10);
        $lock = $factory->get_lock('\\' . get_class($task), 10);
        $this->assertNotFalse($lock);
        $task->set_lock($lock);
        $cronlock->release();
        ob_start();
        cron_run_inner_scheduled_task($task);
        return ob_get_clean();
    }

    public function test_create_in_browser_then_cron_then_download(): void {
        global $USER;

        // 1. Admin clicks "Create Clone Package" (same entry point as index.php).
        $job = queue::create_from_web((int) $USER->id);
        $this->assertSame(job::STATUS_PENDING, $job->get('status'));
        $this->assertSame(job::ORIGIN_WEB, $job->get('origin'));

        // 2. Cron runs the scheduled task: the job goes pending -> running -> completed without any CLI.
        $output = $this->run_task_via_cron();
        $job = new job((int) $job->get('id'));
        $this->assertSame(job::STATUS_COMPLETED, $job->get('status'), $output . $job->get('errormessage'));
        $this->assertNotEmpty($job->get('timestarted'));
        $this->assertSame(100, (int) $job->get('progress'));
        $this->assertGreaterThan(0, \core\task\manager::get_scheduled_task(process_backups::class)->get_last_run_time());

        // 3. The download button's check and download.php both resolve through package_download.
        $workspace = new workspace();
        $this->assertNull(package_download::problem($job, $workspace));
        $path = package_download::resolve($job, $workspace);

        // 4. The web process (this process) owns and can read it; nobody else can.
        $this->assertTrue(is_readable($path));
        if (os_identity::is_supported()) {
            $this->assertSame(posix_geteuid(), fileowner($path));
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertSame(0700, fileperms(dirname($path)) & 0777);
            $this->assertSame(0700, fileperms($workspace->get_base()) & 0777);
        }
        $manifest = (new package_verifier())->verify($path);
        $this->assertTrue($manifest->includes('database'));
        $this->assertNotContains('moodle/config.php', fixture_helper::zip_names($path));

        // 5. A second cron run has nothing to do.
        $this->run_task_via_cron();
        $this->assertNull(job::get_active());
    }

    public function test_cron_as_wrong_user_leaves_job_queued_and_reports_it(): void {
        global $USER;
        if (!os_identity::is_supported()) {
            $this->markTestSkipped('OS users cannot be determined on this platform');
        }
        $job = queue::create_from_web((int) $USER->id);
        // Pretend the web server runs as another OS user than this (cron) process.
        set_config('webuid', posix_geteuid() + 1, 'tool_moodleclone');
        set_config('webuser', 'someotheruser', 'tool_moodleclone');

        $output = $this->run_task_via_cron();

        $this->assertStringContainsString('someotheruser', $output);
        $this->assertSame(job::STATUS_PENDING, (new job((int) $job->get('id')))->get('status'), 'Nothing was created');
        $status = worker_status::get();
        $this->assertFalse(worker_status::is_ready($status));
        $this->assertStringContainsString('someotheruser', worker_status::reason($status));
    }
}
