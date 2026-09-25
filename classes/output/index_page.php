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

namespace tool_moodleclone\output;

use core\output\named_templatable;
use moodle_url;
use renderable;
use renderer_base;
use tool_moodleclone\local\backup\preflight;
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\environment\snapshot;
use tool_moodleclone\local\job\job;
use tool_moodleclone\local\job\worker_status;
use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\package_download;
use tool_moodleclone\local\package\workspace;

/**
 * The Moodle Clone admin page.
 *
 * The database username and password are never passed to the template, and
 * neither are filesystem paths of packages.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class index_page implements named_templatable, renderable {

    /** @var int Seconds without a progress update after which a running job is shown as possibly stalled. */
    public const STALLED_AFTER = 600;

    /** @var snapshot */
    private $snapshot;

    /** @var array[] Preflight results. */
    private $checks;

    /** @var moodle_url Form target. */
    private $actionurl;

    /** @var job|null Pending or running job. */
    private $active;

    /** @var job[] Recent jobs. */
    private $jobs;

    /** @var workspace */
    private $workspace;

    /** @var array From worker_status::get(). */
    private $worker;

    /**
     * Constructor.
     *
     * @param snapshot $snapshot
     * @param array[] $checks From preflight::run().
     * @param moodle_url $actionurl
     * @param job|null $active
     * @param job[] $jobs
     * @param workspace|null $workspace
     * @param array|null $worker From worker_status::get().
     */
    public function __construct(snapshot $snapshot, array $checks, moodle_url $actionurl, ?job $active = null,
            array $jobs = [], ?workspace $workspace = null, ?array $worker = null) {
        $this->snapshot = $snapshot;
        $this->checks = $checks;
        $this->actionurl = $actionurl;
        $this->active = $active;
        $this->jobs = $jobs;
        $this->workspace = $workspace ?? new workspace();
        $this->worker = $worker ?? worker_status::get();
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $s = $this->snapshot;
        $notavailable = get_string('notavailable', 'tool_moodleclone');

        $info = [
            'moodleversion' => "{$s->moodlerelease} ({$s->moodleversion})",
            'phpversion' => $s->phpversion,
            'databasetype' => "{$s->dbtype} ({$s->dbfamily})",
            'databaseversion' => $s->dbversion ?? $notavailable,
            'wwwroot' => $s->wwwroot,
            'dirroot' => $s->dirroot,
            'dataroot' => $s->dataroot,
            'databasename' => $s->dbname,
            'tableprefix' => $s->prefix,
            'operatingsystem' => $s->os,
            'freediskspace' => $s->freediskspace === null ? $notavailable : display_size($s->freediskspace),
        ];
        $sourceinfo = [];
        foreach ($info as $key => $value) {
            $sourceinfo[] = ['label' => get_string('info:' . $key, 'tool_moodleclone'), 'value' => $value];
        }

        $extensions = [];
        foreach ($s->extensions as $name => $loaded) {
            $extensions[] = ['name' => $name, 'loaded' => $loaded];
        }

        $checks = [];
        foreach ($this->checks as $check) {
            $checks[] = [
                'message' => $check['message'],
                'statuslabel' => get_string('status:' . $check['status'], 'tool_moodleclone'),
                'badge' => self::badge($check['status']),
            ];
        }

        $jobs = [];
        foreach ($this->jobs as $job) {
            $jobs[] = $this->export_job($job);
        }

        $lastcron = (int) $this->worker['lastcron'];
        $workerready = worker_status::is_ready($this->worker);

        return [
            'sourceinfo' => $sourceinfo,
            'extensions' => $extensions,
            'checks' => $checks,
            'hasactive' => $this->active !== null,
            'active' => $this->active ? $this->export_job($this->active) : null,
            'cancreate' => $this->active === null && !preflight::has_errors($this->checks),
            'hasjobs' => !empty($jobs),
            'jobs' => $jobs,
            'actionurl' => $this->actionurl->out(false),
            'sesskey' => sesskey(),
            'clicommand' => 'php admin/tool/moodleclone/cli/backup.php --execute',
            'lastcron' => $lastcron ? userdate($lastcron) : get_string('never'),
            'workerready' => $workerready,
            'workermessage' => (string) worker_status::reason($this->worker),
            'needscronsetup' => worker_status::needs_cron_setup($this->worker),
            'cronline' => $this->worker['cronline'],
            'crontab' => $this->worker['crontab'],
            'installerdownloadurl' => (new moodle_url('/admin/tool/moodleclone/installer-download.php',
                ['sesskey' => sesskey()]))->out(false),
            'minpasswordlength' => installer_auth::MIN_LENGTH,
        ];
    }

    /**
     * Template data for one job.
     *
     * @param job $job
     * @return array
     */
    private function export_job(job $job): array {
        $status = $job->get('status');
        $id = (int) $job->get('id');
        $user = $job->get('userid') ? \core_user::get_user($job->get('userid')) : null;
        $step = $job->get('currentstep');
        $errorstep = $job->get('errorstep');
        $filename = $job->get('filename');
        $downloadproblem = $status === job::STATUS_COMPLETED ? package_download::problem($job, $this->workspace) : null;
        $available = $status === job::STATUS_COMPLETED && $downloadproblem === null;
        $modified = (int) $job->get('timemodified');
        $protection = $job->get_installer_mode();

        $steps = [];
        foreach ($job->get_step_states() as $stepname => $data) {
            if (!in_array($stepname, stage::ALL, true)) {
                continue;
            }
            $steps[] = [
                'label' => stage::get_label($stepname),
                'statuslabel' => get_string('status:' . $data['status'], 'tool_moodleclone'),
                'badge' => self::badge($data['status']),
                'progress' => (int) $data['progress'],
            ];
        }

        return [
            'id' => $id,
            'status' => $status,
            'statuslabel' => get_string('status:' . $status, 'tool_moodleclone'),
            'badge' => self::badge($status),
            'ispending' => $status === job::STATUS_PENDING,
            'isrunning' => $status === job::STATUS_RUNNING,
            'cancelrequested' => (bool) $job->get('cancelrequested'),
            'stalled' => $status === job::STATUS_RUNNING && $modified < time() - self::STALLED_AFTER,
            'currentstep' => $step && in_array($step, stage::ALL, true) ? stage::get_label($step) : '',
            'progress' => (int) $job->get('progress'),
            'steps' => $steps,
            'requester' => $user ? fullname($user) : '-',
            'origin' => get_string('origin:' . $job->get('origin'), 'tool_moodleclone'),
            'timecreated' => userdate($job->get('timecreated')),
            'timestarted' => $job->get('timestarted') ? userdate($job->get('timestarted')) : '',
            'timemodified' => userdate($modified),
            'timefinished' => $job->get('timefinished') ? userdate($job->get('timefinished')) : '',
            'filename' => $filename ?? '',
            'size' => $job->get('packagesize') !== null ? display_size((int) $job->get('packagesize')) : '',
            'sha256' => (string) $job->get('packagehash'),
            'error' => (string) $job->get('errormessage'),
            'errorstep' => $errorstep && in_array($errorstep, stage::ALL, true) ? stage::get_label($errorstep) : '',
            'downloadurl' => $available ? (new moodle_url('/admin/tool/moodleclone/download.php',
                ['id' => $id, 'sesskey' => sesskey()]))->out(false) : null,
            'downloadchecksumurl' => $available ? (new moodle_url('/admin/tool/moodleclone/download.php',
                ['id' => $id, 'checksum' => 1, 'sesskey' => sesskey()]))->out(false) : null,
            'downloadproblem' => $downloadproblem,
            'deletable' => !$job->is_active(),
            'protection' => $protection === null ? '' : get_string('protection:' . $protection, 'tool_moodleclone'),
        ];
    }

    /**
     * Bootstrap badge colour for a status.
     *
     * @param string $status
     * @return string
     */
    private static function badge(string $status): string {
        $map = [
            preflight::OK => 'success', preflight::WARNING => 'warning', preflight::ERROR => 'danger',
            job::STATUS_COMPLETED => 'success', job::STATUS_RUNNING => 'info', job::STATUS_PENDING => 'secondary',
            job::STATUS_FAILED => 'danger', job::STATUS_CANCELLED => 'warning', 'skipped' => 'light',
        ];
        return $map[$status] ?? 'secondary';
    }

    /**
     * Template name.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'tool_moodleclone/index_page';
    }
}
