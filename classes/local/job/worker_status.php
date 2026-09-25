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

use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\task\process_backups;

/**
 * Is anything going to run queued backups? Diagnostics for the admin page.
 *
 * Queued backups are executed by the "process_backups" scheduled task, i.e.
 * by Moodle's cron. This class reports what would stop that from happening
 * (cron not running, cron disabled, task disabled, cron running as the wrong
 * OS user) and the exact fix, so a queued job never waits silently.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class worker_status {
    /** @var string Config key holding the last worker identity problem (JSON). */
    public const PROBLEM_CONFIG = 'workerproblem';

    /** @var int A recorded worker problem is shown for this long. */
    public const PROBLEM_TTL = 15 * MINSECS;

    /**
     * Record that a worker refused to run (e.g. cron runs as the wrong OS user).
     *
     * @param string $message
     * @return void
     */
    public static function record_problem(string $message): void {
        $recorded = json_decode((string) get_config('tool_moodleclone', self::PROBLEM_CONFIG), true);
        if (
            is_array($recorded) &&
            ($recorded['message'] ?? null) === $message &&
            ($recorded['time'] ?? 0) >= time() - 5 * MINSECS
        ) {
            // The task runs every minute; avoid rewriting the same config value each time.
            return;
        }
        set_config(self::PROBLEM_CONFIG, json_encode(['time' => time(), 'message' => $message]), 'tool_moodleclone');
    }

    /**
     * Forget a previously recorded problem (a worker ran with the right identity).
     *
     * @return void
     */
    public static function clear_problem(): void {
        if (get_config('tool_moodleclone', self::PROBLEM_CONFIG) !== false) {
            unset_config(self::PROBLEM_CONFIG, 'tool_moodleclone');
        }
    }

    /**
     * Current status.
     *
     * @return array
     */
    public static function get(): array {
        global $CFG;
        $lastcron = (int) get_config('tool_task', 'lastcronstart');
        $frequency = (int) ($CFG->expectedcronfrequency ?? 200);
        $task = \core\task\manager::get_scheduled_task(process_backups::class);
        $problem = null;
        $recorded = json_decode((string) get_config('tool_moodleclone', self::PROBLEM_CONFIG), true);
        if (is_array($recorded) && ($recorded['time'] ?? 0) >= time() - self::PROBLEM_TTL) {
            $problem = (string) $recorded['message'];
        }
        // The recorded web server user; before the admin page has been opened, the workspace owner.
        $web = os_identity::expected_worker() ?? os_identity::current();
        $user = $web['name'] ?? 'www-data';
        $php = !empty($CFG->pathtophp) ? $CFG->pathtophp : '/usr/bin/php';
        return [
            'cronenabled' => get_config('core', 'cron_enabled') !== '0',
            'lastcron' => $lastcron,
            'cronrunning' => $lastcron > 0 && $lastcron >= time() - max($frequency, 60) * 2,
            'taskregistered' => $task !== null,
            'taskdisabled' => $task === null || $task->get_disabled(),
            'tasklastrun' => $task ? (int) $task->get_last_run_time() : 0,
            'problem' => $problem,
            'webuser' => $user,
            'crontab' => 'sudo crontab -u ' . $user . ' -e',
            'cronline' => '* * * * * ' . $php . ' ' . $CFG->dirroot . '/admin/cli/cron.php >/dev/null 2>&1',
        ];
    }

    /**
     * Whether queued jobs will be picked up automatically.
     *
     * @param array|null $status From get().
     * @return bool
     */
    public static function is_ready(?array $status = null): bool {
        $status = $status ?? self::get();
        return $status['cronenabled'] && $status['cronrunning'] && !$status['taskdisabled'] && $status['problem'] === null;
    }

    /**
     * Short reason why queued jobs will not start, or null when the worker is ready.
     *
     * @param array|null $status
     * @return string|null
     */
    public static function reason(?array $status = null): ?string {
        $status = $status ?? self::get();
        if ($status['problem'] !== null) {
            return $status['problem'];
        }
        if (!$status['cronenabled']) {
            return get_string('worker:crondisabled', 'tool_moodleclone');
        }
        if ($status['taskdisabled']) {
            return get_string('worker:taskdisabled', 'tool_moodleclone');
        }
        if (!$status['cronrunning']) {
            return get_string('worker:cronnotrunning', 'tool_moodleclone', (object) [
                'lastcron' => $status['lastcron'] ? userdate($status['lastcron']) : get_string('never'),
                'user' => $status['webuser'],
            ]);
        }
        return null;
    }

    /**
     * Whether the fix is to set up (or repair) the cron entry.
     *
     * @param array|null $status
     * @return bool
     */
    public static function needs_cron_setup(?array $status = null): bool {
        $status = $status ?? self::get();
        return $status['problem'] === null && $status['cronenabled'] && !$status['taskdisabled'] && !$status['cronrunning'];
    }

    /**
     * One check row (same shape as preflight results) for the admin page.
     *
     * @param array|null $status
     * @return array ['check', 'status', 'message']
     */
    public static function check(?array $status = null): array {
        $status = $status ?? self::get();
        $reason = self::reason($status);
        if ($reason === null) {
            return ['check' => 'worker', 'status' => 'ok', 'message' => get_string(
                'worker:ok',
                'tool_moodleclone',
                $status['lastcron'] ? userdate($status['lastcron']) : get_string('never')
            )];
        }
        if (self::needs_cron_setup($status)) {
            $reason .= ' ' . get_string(
                'worker:cronfix',
                'tool_moodleclone',
                (object) ['crontab' => $status['crontab'], 'cronline' => $status['cronline']]
            );
        }
        return ['check' => 'worker', 'status' => 'warning', 'message' => $reason];
    }
}
