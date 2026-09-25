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

use tool_moodleclone\event\backup_completed;
use tool_moodleclone\event\backup_failed;
use tool_moodleclone\event\backup_started;
use tool_moodleclone\fixture_helper;
use tool_moodleclone\local\backup\backup_exception;
use tool_moodleclone\local\backup\options;
use tool_moodleclone\local\backup\source_paths;
use tool_moodleclone\local\log\memory_logger;
use tool_moodleclone\local\log\redactor;
use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\package_verifier;
use tool_moodleclone\local\package\workspace;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/fixture_helper.php');

/**
 * End-to-end tests of job execution on fixture source trees.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\job\runner
 * @covers     \tool_moodleclone\local\job\job_reporter
 * @covers     \tool_moodleclone\local\backup\step\finaliser
 */
class runner_test extends \advanced_testcase {
    /** @var string */
    private $dirroot;

    /** @var string */
    private $dataroot;

    /** @var workspace */
    private $workspace;

    /**
     * Fixture trees.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->dirroot = make_request_directory();
        $this->dataroot = make_request_directory();
        fixture_helper::make_tree($this->dirroot, [
            'config.php' => '<?php $CFG->dbpass = "never";',
            'index.php' => '<?php echo "hi";',
            'lib/moodlelib.php' => str_repeat('code ', 1000),
        ]);
        fixture_helper::add_pool_file($this->dataroot . '/filedir', 'uploaded file');
        fixture_helper::make_tree($this->dataroot, ['cache/x' => 'cache', 'lang/fr/langconfig.php' => 'fr']);
        $this->workspace = new workspace($this->dataroot);
    }

    /**
     * A runner archiving the fixtures.
     *
     * @return runner
     */
    private function runner(): runner {
        global $DB;
        $runner = new runner($this->workspace, new memory_logger(new redactor()));
        $runner->set_sources(source_paths::from_config(fixture_helper::config($this->dirroot, $this->dataroot)));
        $runner->set_dump_tables(['config_plugins']);
        return $runner;
    }

    /**
     * Queue a job; the database is included only on MySQL.
     *
     * @return job
     */
    private function queue_job(): job {
        global $DB;
        $options = new options();
        $options->includedatabase = $DB->get_dbfamily() === 'mysql';
        return queue::create(2, job::ORIGIN_CLI, $options);
    }

    /**
     * Files in the packages directory.
     *
     * @return string[]
     */
    private function published(): array {
        $dir = $this->workspace->get_packages_dir();
        return is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
    }

    public function test_successful_backup_publishes_verified_package(): void {
        $sink = $this->redirectEvents();
        $job = $this->runner()->run($this->queue_job());

        $this->assertSame(job::STATUS_COMPLETED, $job->get('status'), (string) $job->get('errormessage'));
        $this->assertSame(100, (int) $job->get('progress'));
        $filename = $job->get('filename');
        $path = $this->workspace->get_package_path($filename);
        $this->assertSame([$filename, $filename . '.sha256'], $this->published());
        $this->assertSame(hash_file('sha256', $path), $job->get('packagehash'));
        $this->assertSame(filesize($path), (int) $job->get('packagesize'));
        $this->assertSame($job->get('packagehash') . '  ' . $filename . "\n", file_get_contents($path . '.sha256'));
        $this->assertDirectoryDoesNotExist($this->workspace->get_work_dir((int) $job->get('id')));

        $manifest = (new package_verifier())->verify($path);
        $names = fixture_helper::zip_names($path);
        $this->assertContains('moodle/index.php', $names);
        $this->assertNotContains('moodle/config.php', $names);
        $this->assertNotContains('moodledata/cache/x', $names);
        $this->assertSame(2, $manifest->get('statistics')['moodle']['files']);

        $steps = $job->get_step_states();
        $this->assertSame('completed', $steps['finalising']['status']);

        $classes = array_map('get_class', $sink->get_events());
        $this->assertContains(backup_started::class, $classes);
        $this->assertContains(backup_completed::class, $classes);
        $this->assertNotContains(backup_failed::class, $classes);
    }

    public function test_the_password_verifier_is_removed_from_the_job_before_the_dump_and_ends_up_only_in_the_package(): void {
        global $DB;
        $password = 'correct horse battery staple';
        $options = new options();
        $options->includedatabase = $DB->get_dbfamily() === 'mysql';
        $options->installerauth = installer_auth::from_password($password);
        $job = queue::create(2, job::ORIGIN_WEB, $options);
        $verifier = $options->installerauth->to_array()['verifier'];
        // While it waits, the job holds the verifier (never the password): the worker runs later, in another process.
        $this->assertStringContainsString($verifier, (string) $job->get('options'));
        $this->assertStringNotContainsString($password, (string) $job->get('options'));
        $this->assertSame('password', $job->get_installer_mode());

        $job = $this->runner()->run($job);
        $this->assertSame(job::STATUS_COMPLETED, $job->get('status'), (string) $job->get('errormessage'));

        // Afterwards the record keeps only the mode; the verifier is in the package's manifest alone.
        $stored = (string) $DB->get_field(job::TABLE, 'options', ['id' => $job->get('id')]);
        $this->assertStringNotContainsString($verifier, $stored);
        $this->assertStringNotContainsString('salt', $stored);
        $this->assertSame('password', $job->get_installer_mode());
        $manifest = (new package_verifier())->verify($this->workspace->get_package_path($job->get('filename')));
        $this->assertTrue($manifest->get_installer_auth()->verify($password));
    }

    public function test_the_key_file_is_the_default_when_no_protection_is_given(): void {
        $job = $this->runner()->run($this->queue_job());
        $this->assertSame('keyfile', $job->get_installer_mode());
        $manifest = (new package_verifier())->verify($this->workspace->get_package_path($job->get('filename')));
        $this->assertSame('keyfile', $manifest->get_installer_auth()->get_mode());
    }

    public function test_failing_step_marks_job_failed_and_publishes_nothing(): void {
        global $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('Uses the MySQL dump step');
        }
        $sink = $this->redirectEvents();
        $runner = $this->runner();
        $runner->set_db_connector(function () {
            throw new backup_exception('dbconnect', null, "Access denied for user 'secretuser'@'localhost'");
        });

        $job = $runner->run($this->queue_job());

        $this->assertSame(job::STATUS_FAILED, $job->get('status'));
        $this->assertSame('database', $job->get('errorstep'));
        $this->assertStringContainsString(get_string('error:dbconnect', 'tool_moodleclone'), $job->get('errormessage'));
        $this->assertStringNotContainsString('secretuser', $job->get('errormessage'), 'Stored errors are redacted');
        $this->assertSame('completed', $job->get_step_states()['code']['status'], 'Earlier steps had completed');
        $this->assertSame([], $this->published());
        $this->assertDirectoryDoesNotExist($this->workspace->get_work_dir((int) $job->get('id')));
        $this->assertContains(backup_failed::class, array_map('get_class', $sink->get_events()));
    }

    public function test_preflight_failure_fails_before_writing(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
        symlink('/etc', $this->dataroot . '/outside');

        $job = $this->runner()->run($this->queue_job());

        $this->assertSame(job::STATUS_FAILED, $job->get('status'));
        $this->assertSame('preflight', $job->get('errorstep'));
        $this->assertSame([], $this->published());
    }

    public function test_cancellation_stops_and_cleans_up(): void {
        $runner = $this->runner();
        $runner->set_stop_check(function () {
            return true;
        });

        $job = $runner->run($this->queue_job());

        $this->assertSame(job::STATUS_CANCELLED, $job->get('status'));
        $this->assertSame([], $this->published());
        $this->assertDirectoryDoesNotExist($this->workspace->get_work_dir((int) $job->get('id')));
    }

    public function test_cancelled_pending_job_is_never_started(): void {
        $sink = $this->redirectEvents();
        $job = $this->queue_job();
        queue::cancel((int) $job->get('id'));

        $job = $this->runner()->run($job);

        $this->assertSame(job::STATUS_CANCELLED, $job->get('status'));
        $this->assertSame([], $sink->get_events());
    }

    public function test_recover_interrupted_job(): void {
        $sink = $this->redirectEvents();
        $interrupted = $this->queue_job();
        $id = (int) $interrupted->get('id');
        $filename = 'moodle-clone-2026-01-01-000000.zip';
        job::change_status($id, job::STATUS_PENDING, job::STATUS_RUNNING, ['currentstep' => 'dataroot', 'filename' => $filename]);
        $workdir = $this->workspace->create_work_dir($id);
        file_put_contents($workdir . '/package.zip.part', str_repeat('x', 1000));
        file_put_contents($this->workspace->get_packages_dir() . '/' . $filename, 'published but never confirmed');
        $completedname = 'moodle-clone-2025-12-31-000000.zip';
        file_put_contents($this->workspace->get_packages_dir() . '/' . $completedname, 'good package');
        $done = new job(0, (object) ['status' => job::STATUS_COMPLETED, 'filename' => $completedname]);
        $done->create();

        $recovered = $this->runner()->recover_interrupted();

        $this->assertSame([$id], $recovered);
        $job = new job($id);
        $this->assertSame(job::STATUS_FAILED, $job->get('status'));
        $this->assertSame('dataroot', $job->get('errorstep'), 'Where it stopped is kept for diagnosis');
        $this->assertSame(get_string('error:interrupted', 'tool_moodleclone'), $job->get('errormessage'));
        $this->assertDirectoryDoesNotExist($workdir);
        $this->assertSame([$completedname], $this->published());
        $this->assertContains(backup_failed::class, array_map('get_class', $sink->get_events()));
    }

    public function test_shutdown_handler_fails_running_job(): void {
        $job = $this->queue_job();
        $id = (int) $job->get('id');
        job::change_status($id, job::STATUS_PENDING, job::STATUS_RUNNING);
        $this->workspace->create_work_dir($id);
        $runner = $this->runner();
        $property = new \ReflectionProperty(runner::class, 'activejobid');
        $property->setAccessible(true);
        $property->setValue($runner, $id);

        $runner->handle_shutdown();

        $this->assertSame(job::STATUS_FAILED, (new job($id))->get('status'));
        $this->assertDirectoryDoesNotExist($this->workspace->get_work_dir($id));
    }

    public function test_wrong_os_user_leaves_job_pending(): void {
        if (!\tool_moodleclone\local\environment\os_identity::is_supported()) {
            $this->markTestSkipped('OS users cannot be determined on this platform');
        }
        set_config('webuid', posix_geteuid() + 1, 'tool_moodleclone');
        set_config('webuser', 'webserveruser', 'tool_moodleclone');

        $job = $this->runner()->run($this->queue_job());

        $this->assertSame(job::STATUS_PENDING, $job->get('status'));
        $this->assertSame([], $this->published());
    }

    public function test_backup_lock_is_exclusive(): void {
        $lock = runner::acquire_lock(0);
        $this->assertNotFalse($lock);
        try {
            $this->assertFalse(runner::acquire_lock(0), 'A second backup cannot start while one holds the lock');
        } finally {
            $lock->release();
        }
        $again = runner::acquire_lock(0);
        $this->assertNotFalse($again);
        $again->release();
    }

    public function test_job_reporter_weights_and_overall_progress(): void {
        $weights = job_reporter::weights(['moodle' => ['bytes' => 100], 'moodledata' => ['bytes' => 300],
            'database' => ['bytes' => 100]]);
        $job = $this->queue_job();
        job::change_status((int) $job->get('id'), job::STATUS_PENDING, job::STATUS_RUNNING);
        $reporter = new job_reporter((int) $job->get('id'), $weights, 0.0, new redactor());

        $reporter->step_started('code', 1, 7);
        $reporter->step_completed('code');
        $reporter->step_started('dataroot', 3, 7);
        $reporter->step_progress('dataroot', 0.5);

        $expected = (int) floor((100 + 150) / array_sum($weights) * 100);
        $this->assertSame($expected, $reporter->get_overall());
        $this->assertSame($expected, (int) (new job((int) $job->get('id')))->get('progress'));
        $this->assertSame('dataroot', (new job((int) $job->get('id')))->get('currentstep'));
    }
}
