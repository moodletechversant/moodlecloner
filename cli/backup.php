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

/**
 * Moodle Clone command line interface.
 *
 * The CLI is the preferred way to create packages of large sites: it has no
 * time limit and does not depend on cron. It uses the same job records, lock
 * and pipeline as the scheduled task, so the admin page shows its progress.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_moodleclone\local\backup\manager;
use tool_moodleclone\local\backup\options;
use tool_moodleclone\local\backup\preflight;
use tool_moodleclone\local\backup\progress\cli_reporter;
use tool_moodleclone\local\backup\size_estimator;
use tool_moodleclone\local\backup\source_paths;
use tool_moodleclone\local\environment\collector;
use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\local\job\job;
use tool_moodleclone\local\job\queue;
use tool_moodleclone\local\job\runner;
use tool_moodleclone\local\log\mtrace_logger;
use tool_moodleclone\local\log\redactor;
use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\workspace;

/**
 * Read the installer password: one line from standard input, or a hidden prompt (twice) on a terminal.
 *
 * @param bool $fromstdin Read a single line from standard input (for scripts) instead of prompting.
 * @return string[] ['first' => ..., 'second' => ...]
 */
function tool_moodleclone_cli_read_password(bool $fromstdin): array {
    if ($fromstdin || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        $line = fgets(STDIN);
        if ($line === false) {
            cli_error(get_string('error:installerpassword_short', 'tool_moodleclone', installer_auth::MIN_LENGTH));
        }
        $line = rtrim($line, "\r\n");
        return ['first' => $line, 'second' => $line];
    }
    $answers = [];
    system('stty -echo');
    try {
        foreach (['installerpassword', 'installerpassword2'] as $key) {
            fwrite(STDOUT, get_string($key, 'tool_moodleclone') . ': ');
            $answers[] = rtrim((string) fgets(STDIN), "\r\n");
            fwrite(STDOUT, "\n");
        }
    } finally {
        system('stty echo');
    }
    return ['first' => $answers[0], 'second' => $answers[1]];
}

[$params, $unrecognised] = cli_get_params([
    'help' => false,
    'check' => false,
    'execute' => false,
    'no-code' => false,
    'no-dataroot' => false,
    'no-database' => false,
    'installer-protection' => installer_auth::MODE_PASSWORD,
    'installer-password-stdin' => false,
], [
    'h' => 'help',
    'c' => 'check',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($params['help'] || (!$params['check'] && !$params['execute'])) {
    cli_writeln(get_string('clihelp', 'tool_moodleclone'));
    exit(0);
}

// Packages are private (0600) to the OS user that creates them, so the worker
// must be the web server's user; root and any other user are refused.
$identityproblem = os_identity::worker_problem();
if ($identityproblem !== null && $params['execute']) {
    cli_error($identityproblem);
}

\core\session\manager::set_user(get_admin());

$options = new options();
$options->includecode = !$params['no-code'];
$options->includedataroot = !$params['no-dataroot'];
$options->includedatabase = !$params['no-database'];
if ($options->is_empty()) {
    cli_error(get_string('error:nothingselected', 'tool_moodleclone'));
}

$workspace = new workspace();
cli_heading(get_string('pluginname', 'tool_moodleclone'));

// Full pre-flight: the same checks and size estimate a job performs before writing anything.
$snapshot = (new collector())->collect();
try {
    $estimate = (new size_estimator())->estimate(source_paths::from_config(), $options, $DB, $workspace->get_base());
    $checks = (new preflight($snapshot, manager::create_default(), $estimate, $options, null,
        $workspace->get_base()))->run();
} catch (moodle_exception $e) {
    $checks = [['check' => 'sources', 'status' => preflight::ERROR,
        'message' => redactor::from_config()->redact($e->getMessage() . (empty($e->debuginfo) ? '' : ' (' . $e->debuginfo . ')'))]];
    $estimate = null;
}
if ($identityproblem !== null) {
    $checks[] = ['check' => 'identity', 'status' => preflight::ERROR, 'message' => $identityproblem];
} else if (($current = os_identity::current()) !== null && os_identity::expected_worker() !== null) {
    $checks[] = ['check' => 'identity', 'status' => preflight::OK,
        'message' => get_string('identity:ok', 'tool_moodleclone', $current['name'])];
} else if ($current !== null) {
    $checks[] = ['check' => 'identity', 'status' => preflight::WARNING,
        'message' => get_string('identity:unverified', 'tool_moodleclone', $current['name'])];
} else {
    $checks[] = ['check' => 'identity', 'status' => preflight::WARNING,
        'message' => get_string('identity:unknown', 'tool_moodleclone')];
}
foreach ($checks as $check) {
    cli_writeln(sprintf('[%-7s] %s', strtoupper($check['status']), $check['message']));
}
if ($estimate !== null) {
    cli_writeln(get_string('cli:estimate', 'tool_moodleclone', (object) [
        'files' => ($estimate['moodle']['files'] ?? 0) + ($estimate['moodledata']['files'] ?? 0),
        'data' => display_size(($estimate['moodle']['bytes'] ?? 0) + ($estimate['moodledata']['bytes'] ?? 0)),
        'database' => display_size($estimate['database']['bytes'] ?? 0),
    ]));
}
$failed = preflight::has_errors($checks);
cli_writeln('');
cli_writeln(get_string('cli:environment', 'tool_moodleclone', $failed ? 'FAIL' : 'PASS'));

if ($params['check'] || $failed) {
    exit($failed ? 1 : 0);
}

// How the installer of the new package will authorize its user. A queued web job already carries its own choice.
$installerauth = null;
if (queue::next_pending() === null) {
    if ($params['installer-protection'] === installer_auth::MODE_KEYFILE) {
        $installerauth = installer_auth::keyfile();
    } else if ($params['installer-protection'] === installer_auth::MODE_PASSWORD) {
        // Never a command line argument (it would show in the process list and the shell history).
        $password = tool_moodleclone_cli_read_password((bool) $params['installer-password-stdin']);
        $problems = installer_auth::password_problems($password['first'], $password['second']);
        if ($problems) {
            cli_error(implode(' ', array_map(function($code) {
                return get_string('error:installerpassword_' . $code, 'tool_moodleclone', installer_auth::MIN_LENGTH);
            }, $problems)));
        }
        $installerauth = installer_auth::from_password($password['first']);
        unset($password);
    } else {
        cli_error(get_string('error:installerprotection', 'tool_moodleclone'));
    }
}
$options->installerauth = $installerauth;

// Execute.
$lock = runner::acquire_lock(0);
if (!$lock) {
    $active = job::get_active();
    cli_error(get_string('cli:locked', 'tool_moodleclone', $active ? $active->get('id') : '?'));
}

// Ctrl-C / SIGTERM: the runner stops at its next check, cleans up and records the job as cancelled.
\core\local\cli\shutdown::script_supports_graceful_exit();

try {
    $runner = new runner($workspace, new mtrace_logger(), new cli_reporter());
    $runner->recover_interrupted();
    $job = queue::next_pending();
    if ($job !== null) {
        cli_writeln(get_string('cli:runningqueued', 'tool_moodleclone', $job->get('id')));
    } else {
        $job = queue::create((int) get_admin()->id, job::ORIGIN_CLI, $options);
    }
    cli_writeln('');
    cli_writeln(get_string('cli:starting', 'tool_moodleclone', $job->get('id')));
    cli_writeln('');
    $job = $runner->run($job);
} finally {
    $lock->release();
}

cli_writeln('');
if ($job->get('status') !== job::STATUS_COMPLETED) {
    cli_error(get_string('cli:failed', 'tool_moodleclone', (object) [
        'status' => $job->get('status'),
        'error' => (string) $job->get('errormessage'),
    ]));
}
$result = $runner->get_last_state()->result;
cli_writeln(get_string('cli:completed', 'tool_moodleclone'));
cli_writeln('');
cli_writeln(get_string('cli:package', 'tool_moodleclone') . "\n  " . $result['path']);
cli_writeln(get_string('cli:size', 'tool_moodleclone') . "\n  " . display_size($result['size']) . " ({$result['size']} bytes)");
cli_writeln("SHA-256:\n  " . $result['sha256']);
$owner = os_identity::owner($result['path']);
if ($owner !== null) {
    cli_writeln(get_string('cli:owner', 'tool_moodleclone') . "\n  " . $owner['name'] . ', ' .
        sprintf('%04o', fileperms($result['path']) & 0777));
}
if ($runner->get_last_state()->warnings > 0) {
    cli_writeln('');
    cli_writeln(get_string('cli:warnings', 'tool_moodleclone', $runner->get_last_state()->warnings));
}
exit(0);
