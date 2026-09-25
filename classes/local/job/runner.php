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
use tool_moodleclone\local\backup\backup_cancelled_exception;
use tool_moodleclone\local\backup\backup_exception;
use tool_moodleclone\local\backup\backup_state;
use tool_moodleclone\local\backup\manager;
use tool_moodleclone\local\backup\preflight;
use tool_moodleclone\local\backup\progress\multi_reporter;
use tool_moodleclone\local\backup\progress\reporter;
use tool_moodleclone\local\backup\size_estimator;
use tool_moodleclone\local\backup\source_paths;
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\environment\collector;
use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\local\log\logger;
use tool_moodleclone\local\log\mtrace_logger;
use tool_moodleclone\local\log\redactor;
use tool_moodleclone\local\package\checksum_writer;
use tool_moodleclone\local\package\workspace;
use tool_moodleclone\local\package\zip_writer;

/**
 * Executes backup jobs: locking, recovery, preflight, the pipeline, publication.
 *
 * Only one backup can run per site: every execution holds the
 * "tool_moodleclone/backup" lock from Moodle's lock API for its whole
 * duration. With the default MySQL/PostgreSQL/file lock factories the lock
 * is released automatically if the process dies, so the next run can detect
 * the dead job.
 *
 * Restart safety: a job found in status "running" while this process holds
 * the lock cannot have a live worker. recover_interrupted() marks it failed,
 * keeps its error details, and deletes its working directory and any package
 * file it may have published before dying. A shutdown handler does the same
 * for fatal errors (memory, time limit) in the worker itself.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runner {

    /** @var string Lock type (frankenstyle). */
    public const LOCK_TYPE = 'tool_moodleclone';

    /** @var string Lock resource held while a backup runs. */
    public const LOCK_RESOURCE = 'backup';

    /** @var int Lock lifetime for lock factories without auto-release (db_record_lock_factory). */
    public const LOCK_LIFETIME = 2 * DAYSECS;

    /** @var \core\lock\lock_factory|null Shared, so a second acquisition in the same process also fails fast. */
    private static $lockfactory = null;

    /** @var array|null PHPUnit only: ['sources' => source_paths, 'dumptables' => ?array] for runners built by the task. */
    private static $testoverrides = null;

    /** @var workspace */
    private $workspace;

    /** @var logger */
    private $logger;

    /** @var reporter|null */
    private $extrareporter;

    /** @var callable|null */
    private $dbconnector = null;

    /** @var string[]|null */
    private $dumptables = null;

    /** @var callable|null fn(): bool, e.g. a CLI signal flag. */
    private $stopcheck = null;

    /** @var source_paths|null Override of the $CFG-derived sources (tests only). */
    private $sources = null;

    /** @var int|null Job being executed by this process. */
    private $activejobid = null;

    /** @var backup_state|null */
    private $state = null;

    /** @var bool */
    private $shutdownregistered = false;

    /**
     * Constructor.
     *
     * @param workspace|null $workspace
     * @param logger|null $logger Defaults to mtrace output.
     * @param reporter|null $extrareporter Additional progress output (CLI).
     */
    public function __construct(?workspace $workspace = null, ?logger $logger = null, ?reporter $extrareporter = null) {
        $this->workspace = $workspace ?? new workspace();
        $this->logger = $logger ?? new mtrace_logger();
        $this->extrareporter = $extrareporter;
        if (self::$testoverrides !== null) {
            $this->sources = self::$testoverrides['sources'];
            $this->dumptables = self::$testoverrides['dumptables'];
        }
    }

    /**
     * PHPUnit only: make runners created elsewhere (e.g. by the scheduled task)
     * archive fixture trees instead of the real site.
     *
     * @param source_paths|null $sources Null clears the overrides.
     * @param string[]|null $dumptables
     * @return void
     */
    public static function set_test_overrides(?source_paths $sources, ?array $dumptables = null): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Test overrides are only available in PHPUnit');
        }
        self::$testoverrides = $sources === null ? null : ['sources' => $sources, 'dumptables' => $dumptables];
    }

    /**
     * Why this process must not run backups (wrong OS user), or null.
     *
     * @return string|null
     */
    public function get_worker_problem(): ?string {
        return os_identity::worker_problem($this->workspace->get_base());
    }

    /**
     * The lock factory used for all Moodle Clone locks.
     *
     * @return \core\lock\lock_factory
     */
    public static function get_lock_factory(): \core\lock\lock_factory {
        if (self::$lockfactory === null) {
            self::$lockfactory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        }
        return self::$lockfactory;
    }

    /**
     * Try to take the backup lock.
     *
     * @param int $timeout Seconds to wait.
     * @return \core\lock\lock|false
     */
    public static function acquire_lock(int $timeout = 0) {
        return self::get_lock_factory()->get_lock(self::LOCK_RESOURCE, $timeout, self::LOCK_LIFETIME);
    }

    /**
     * Use another connection factory for the dump (tests).
     *
     * @param callable $connector
     * @return void
     */
    public function set_db_connector(callable $connector): void {
        $this->dbconnector = $connector;
    }

    /**
     * Restrict the dump to some tables (tests).
     *
     * @param string[]|null $tables Names without prefix.
     * @return void
     */
    public function set_dump_tables(?array $tables): void {
        $this->dumptables = $tables;
    }

    /**
     * Archive other source trees than $CFG's (tests only).
     *
     * @param source_paths $sources
     * @return void
     */
    public function set_sources(source_paths $sources): void {
        $this->sources = $sources;
    }

    /**
     * Extra stop condition, checked with cancellation requests.
     *
     * @param callable $check fn(): bool
     * @return void
     */
    public function set_stop_check(callable $check): void {
        $this->stopcheck = $check;
    }

    /**
     * Fail jobs whose worker died, and remove files no job owns. Call with the lock held.
     *
     * @return int[] Ids of recovered jobs.
     */
    public function recover_interrupted(): array {
        $recovered = [];
        foreach (job::get_records(['status' => job::STATUS_RUNNING]) as $job) {
            $id = (int) $job->get('id');
            $changed = job::change_status($id, job::STATUS_RUNNING, job::STATUS_FAILED, [
                'errorstep' => $job->get('currentstep'),
                'errormessage' => get_string('error:interrupted', 'tool_moodleclone'),
                'timefinished' => time(),
            ]);
            if ($changed) {
                $this->remove_unconfirmed_files($id);
                backup_failed::create_for_job($job, ['status' => job::STATUS_FAILED,
                    'stage' => (string) $job->get('currentstep')])->trigger();
                $this->logger->log(logger::WARNING, get_string('recovered', 'tool_moodleclone', $id));
                $recovered[] = $id;
            }
        }
        $keep = [];
        foreach (job::get_records(['status' => job::STATUS_COMPLETED]) as $job) {
            if ($job->get('filename') !== null) {
                $keep[] = $job->get('filename');
            }
        }
        $this->workspace->cleanup([], $keep);
        return $recovered;
    }

    /**
     * Run the oldest pending job, if any. Call with the lock held.
     *
     * @return job|null
     */
    public function run_next(): ?job {
        $job = queue::next_pending();
        return $job ? $this->run($job) : null;
    }

    /**
     * Run a pending job to completion or failure. Call with the lock held. Never throws for backup failures.
     *
     * @param job $job
     * @return job The job as stored afterwards.
     */
    public function run(job $job): job {
        $id = (int) $job->get('id');
        if ($problem = $this->get_worker_problem()) {
            // Leave the job pending: it runs as soon as a worker with the right identity picks it up.
            $this->logger->log(logger::ERROR, $problem);
            worker_status::record_problem($problem);
            return new job($id);
        }
        $claimed = job::change_status($id, job::STATUS_PENDING, job::STATUS_RUNNING, [
            'timestarted' => time(),
            'currentstep' => stage::PREFLIGHT,
            'progress' => 0,
        ]);
        $job = new job($id);
        if (!$claimed) {
            // Cancelled (or taken) in the meantime.
            return $job;
        }

        backup_started::create_for_job($job)->trigger();
        $this->activejobid = $id;
        $this->state = null;
        if (!$this->shutdownregistered) {
            \core_shutdown_manager::register_function([$this, 'handle_shutdown']);
            $this->shutdownregistered = true;
        }
        \core_php_time_limit::raise();

        try {
            $this->execute($job);
            $result = $this->state->result;
            $completed = job::change_status($id, job::STATUS_RUNNING, job::STATUS_COMPLETED, [
                'filename' => $result['filename'],
                'packagesize' => $result['size'],
                'packagehash' => $result['sha256'],
                'progress' => 100,
                'currentstep' => null,
                'timefinished' => time(),
            ]);
            if (!$completed) {
                // Only a completed job makes a package downloadable; without that record it must not stay.
                throw new backup_exception('statuslost');
            }
            $job = new job($id);
            backup_completed::create_for_job($job, ['size' => (int) $result['size'], 'sha256' => $result['sha256']])
                ->trigger();
        } catch (backup_cancelled_exception $e) {
            $this->finish_unsuccessful($job, job::STATUS_CANCELLED, $e);
        } catch (\Throwable $e) {
            $this->finish_unsuccessful($job, job::STATUS_FAILED, $e);
        } finally {
            if ($this->state !== null && $this->state->zip !== null && !$this->state->zip->is_finished()) {
                $this->state->zip->abort();
            }
            $this->workspace->remove_work_dir($id);
            $this->activejobid = null;
        }
        return new job($id);
    }

    /**
     * The state of the last run (result, warnings), for callers such as the CLI.
     *
     * @return backup_state|null
     */
    public function get_last_state(): ?backup_state {
        return $this->state;
    }

    /**
     * Preflight, then the pipeline.
     *
     * @param job $job
     * @return void
     */
    private function execute(job $job): void {
        global $CFG, $DB;
        $id = (int) $job->get('id');
        $options = $job->get_backup_options();
        // The password verifier is needed only for the manifest: take it out of the database before the dump starts.
        queue::discard_installer_verifier($id, $options);
        $snapshot = (new collector())->collect();
        $sources = $this->sources ?? source_paths::from_config($CFG);
        $manager = manager::create_default();

        $this->logger->log(logger::INFO, stage::get_label(stage::PREFLIGHT));
        $estimate = (new size_estimator())->estimate($sources, $options, $DB, $this->workspace->get_base());
        $checks = (new preflight($snapshot, $manager, $estimate, $options, null, $this->workspace->get_base()))->run();
        foreach ($checks as $check) {
            if ($check['status'] !== preflight::OK) {
                $this->logger->log($check['status'] === preflight::ERROR ? logger::ERROR : logger::WARNING,
                    $check['message']);
            }
        }
        if (preflight::has_errors($checks)) {
            throw new backup_exception('preflightfailed', implode(' ', preflight::get_errors($checks)));
        }

        $state = new backup_state($snapshot, $options, $this->logger, (int) $job->get('timestarted'));
        $this->state = $state;
        $state->sources = $sources;
        $state->estimate = $estimate;
        $state->workspace = $this->workspace;
        $state->dbconnector = $this->dbconnector;
        $state->dumptables = $this->dumptables;
        $state->reporter = new multi_reporter([new job_reporter($id, job_reporter::weights($estimate)),
            $this->extrareporter]);
        $state->cancelcheck = function() use ($id) {
            // Ctrl-C/SIGTERM on cron.php, scheduled_task.php or cli/backup.php (core graceful-exit API).
            return queue::is_cancel_requested($id) || \core\local\cli\shutdown::should_gracefully_exit() ||
                ($this->stopcheck !== null && ($this->stopcheck)());
        };
        $state->onpackagename = function(string $filename) use ($id) {
            global $DB;
            $DB->set_field(job::TABLE, 'filename', $filename, ['id' => $id]);
        };
        $state->workdir = $this->workspace->create_work_dir($id);
        $state->zip = new zip_writer($state->workdir . '/package.zip.part', $state->workdir . '/central-directory.spool');
        $state->checksums = new checksum_writer($state->workdir . '/checksums.spool');

        $manager->run($state);
    }

    /**
     * Record a failure or cancellation and remove everything the job wrote.
     *
     * @param job $job
     * @param string $status
     * @param \Throwable $e
     * @return void
     */
    private function finish_unsuccessful(job $job, string $status, \Throwable $e): void {
        $id = (int) $job->get('id');
        $stage = $this->state->currentstage ?? stage::PREFLIGHT;
        $message = job_reporter::clean_error(backup_exception::describe($e), redactor::from_config());
        $this->logger->log(logger::ERROR, $message);
        job::change_status($id, job::STATUS_RUNNING, $status, [
            'errorstep' => $stage,
            'errormessage' => $message,
            'timefinished' => time(),
        ]);
        $this->remove_unconfirmed_files($id);
        backup_failed::create_for_job(new job($id), ['status' => $status, 'stage' => $stage])->trigger();
    }

    /**
     * Delete the working directory and a package that was published but never confirmed.
     *
     * @param int $jobid
     * @return void
     */
    private function remove_unconfirmed_files(int $jobid): void {
        global $DB;
        $this->workspace->remove_work_dir($jobid);
        $record = $DB->get_record(job::TABLE, ['id' => $jobid], 'status, filename');
        if ($record && $record->status !== job::STATUS_COMPLETED && $record->filename !== null) {
            $this->workspace->remove_package($record->filename);
        }
    }

    /**
     * Shutdown handler: a fatal error (memory, time limit) must not leave a "running" job behind.
     *
     * @return void
     */
    public function handle_shutdown(): void {
        if ($this->activejobid === null) {
            return;
        }
        $id = $this->activejobid;
        $this->activejobid = null;
        try {
            if ($this->state !== null && $this->state->zip !== null && !$this->state->zip->is_finished()) {
                $this->state->zip->abort();
            }
            $error = error_get_last();
            $message = get_string('error:interrupted', 'tool_moodleclone');
            if ($error !== null) {
                $message .= ' ' . job_reporter::clean_error($error['message'], redactor::from_config());
            }
            job::change_status($id, job::STATUS_RUNNING, job::STATUS_FAILED, [
                'errorstep' => $this->state->currentstage ?? stage::PREFLIGHT,
                'errormessage' => $message,
                'timefinished' => time(),
            ]);
            $this->remove_unconfirmed_files($id);
        } catch (\Throwable $e) {
            // Nothing more can be done here; the next run's recovery will clean up.
            unset($e);
        }
    }
}
