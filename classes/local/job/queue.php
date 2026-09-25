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
use tool_moodleclone\local\backup\preflight;
use tool_moodleclone\local\environment\collector;
use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\workspace;

/**
 * Creating, cancelling and deleting jobs.
 *
 * Creation is serialised with a short "queue" lock, so two administrators
 * clicking at the same moment cannot both queue a backup; at most one job is
 * pending or running at any time.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue {
    /** @var string Lock resource guarding job creation. */
    public const LOCK_RESOURCE = 'queue';

    /**
     * Queue a new backup.
     *
     * @param int $userid Requesting user.
     * @param string $origin job::ORIGIN_WEB or job::ORIGIN_CLI.
     * @param options $options
     * @return job
     * @throws \moodle_exception When another job is active or the lock is unavailable.
     */
    public static function create(int $userid, string $origin, options $options): job {
        if ($options->is_empty()) {
            throw new \moodle_exception('error:nothingselected', 'tool_moodleclone');
        }
        $lock = runner::get_lock_factory()->get_lock(self::LOCK_RESOURCE, 10);
        if (!$lock) {
            throw new \moodle_exception('error:queuelocked', 'tool_moodleclone');
        }
        try {
            if ($active = job::get_active()) {
                throw new \moodle_exception('error:jobactive', 'tool_moodleclone', '', $active->get('id'));
            }
            $job = new job(0, (object) [
                'userid' => $userid,
                'origin' => $origin,
                'status' => job::STATUS_PENDING,
                'options' => json_encode($options->to_job_data()),
            ]);
            $job->create();
            return $job;
        } finally {
            $lock->release();
        }
    }

    /**
     * What "Create Clone Package" on the admin page does: record the web server's
     * OS user (the identity workers must use), run the quick checks and queue a
     * full backup. The backup itself runs later in the scheduled task.
     *
     * @param int $userid
     * @param workspace|null $workspace
     * @param installer_auth|null $installerauth How the installer will authorize its user; the key file when omitted.
     * @return job
     * @throws \moodle_exception When checks fail or a job is already active.
     */
    public static function create_from_web(int $userid, ?workspace $workspace = null, ?installer_auth $installerauth = null): job {
        os_identity::record_web_identity();
        $checks = preflight::quick((new collector())->collect(), $workspace ?? new workspace());
        if (preflight::has_errors($checks)) {
            throw new \moodle_exception('createblocked', 'tool_moodleclone', '', implode(' ', preflight::get_errors($checks)));
        }
        $options = new options();
        $options->installerauth = $installerauth;
        return self::create($userid, job::ORIGIN_WEB, $options);
    }

    /**
     * Remove a password verifier from a job record, keeping only the mode.
     *
     * The verifier must not linger in the database: it is needed only to write
     * the manifest, and the job table is itself part of the database dump.
     *
     * @param int $jobid
     * @param options $options As read from the job.
     * @return void
     */
    public static function discard_installer_verifier(int $jobid, options $options): void {
        global $DB;
        $DB->set_field(job::TABLE, 'options', json_encode($options->to_contents() +
            ['installer_auth' => ['mode' => $options->get_installer_auth()->get_mode()]]), ['id' => $jobid]);
    }

    /**
     * Cancel a job: pending jobs stop immediately, running ones are asked to stop.
     *
     * @param int $jobid
     * @return string The job's status afterwards.
     */
    public static function cancel(int $jobid): string {
        global $DB;
        if (job::change_status($jobid, job::STATUS_PENDING, job::STATUS_CANCELLED, ['timefinished' => time()])) {
            self::discard_installer_verifier($jobid, (new job($jobid))->get_backup_options());
        } else {
            $DB->set_field_select(
                job::TABLE,
                'cancelrequested',
                1,
                'id = :id AND status = :running',
                ['id' => $jobid, 'running' => job::STATUS_RUNNING]
            );
        }
        return (string) $DB->get_field(job::TABLE, 'status', ['id' => $jobid]);
    }

    /**
     * Whether an administrator asked this job to stop.
     *
     * @param int $jobid
     * @return bool
     */
    public static function is_cancel_requested(int $jobid): bool {
        global $DB;
        return (bool) $DB->get_field(job::TABLE, 'cancelrequested', ['id' => $jobid]);
    }

    /**
     * Delete a finished job and its package.
     *
     * @param job $job
     * @param workspace $workspace
     * @return void
     * @throws \moodle_exception When the job is still active.
     */
    public static function delete(job $job, workspace $workspace): void {
        if ($job->is_active()) {
            throw new \moodle_exception('error:jobactive', 'tool_moodleclone', '', $job->get('id'));
        }
        if ($job->get('filename') !== null) {
            $workspace->remove_package($job->get('filename'));
        }
        $workspace->remove_work_dir((int) $job->get('id'));
        $job->delete();
    }

    /**
     * Most recent jobs, newest first.
     *
     * @param int $limit
     * @return job[]
     */
    public static function get_recent(int $limit = 20): array {
        return job::get_records([], 'id', 'DESC', 0, $limit);
    }

    /**
     * Oldest pending job.
     *
     * @return job|null
     */
    public static function next_pending(): ?job {
        $jobs = job::get_records(['status' => job::STATUS_PENDING], 'id', 'ASC', 0, 1);
        return $jobs ? reset($jobs) : null;
    }
}
