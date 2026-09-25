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
use tool_moodleclone\local\package\layout;

/**
 * A backup job record.
 *
 * Creation and reading go through core\persistent. Status changes and
 * progress updates are written with targeted, conditional UPDATE statements
 * instead of persistent::update(), because the worker and the admin page
 * change the same row concurrently (e.g. a cancel request while progress is
 * being written) and a full-row update would silently undo the other side.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job extends \core\persistent {
    /** @var string Table. */
    public const TABLE = 'tool_moodleclone_jobs';

    /** @var string Waiting for the background task. */
    public const STATUS_PENDING = 'pending';

    /** @var string A worker holds the backup lock and is running it. */
    public const STATUS_RUNNING = 'running';

    /** @var string The package was verified and published. */
    public const STATUS_COMPLETED = 'completed';

    /** @var string A step failed or the worker stopped. */
    public const STATUS_FAILED = 'failed';

    /** @var string Stopped by an administrator. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string[] All statuses. */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_FAILED,
        self::STATUS_CANCELLED];

    /** @var array Allowed status changes. Completed, failed and cancelled are final. */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_RUNNING => [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    /** @var string Requested from the admin page. */
    public const ORIGIN_WEB = 'web';

    /** @var string Started from cli/backup.php. */
    public const ORIGIN_CLI = 'cli';

    /** @var string[] Columns that may be set together with a status change. */
    private const UPDATABLE = ['currentstep', 'progress', 'steps', 'filename', 'packagesize', 'packagehash', 'errorstep',
        'errormessage', 'cancelrequested', 'timestarted', 'timefinished'];

    /**
     * Properties.
     *
     * @return array
     */
    protected static function define_properties() {
        return [
            'userid' => ['type' => PARAM_INT, 'default' => 0],
            'origin' => ['type' => PARAM_ALPHA, 'default' => self::ORIGIN_WEB,
                'choices' => [self::ORIGIN_WEB, self::ORIGIN_CLI]],
            'status' => ['type' => PARAM_ALPHA, 'default' => self::STATUS_PENDING, 'choices' => self::STATUSES],
            'currentstep' => ['type' => PARAM_ALPHA, 'null' => NULL_ALLOWED, 'default' => null],
            'progress' => ['type' => PARAM_INT, 'default' => 0],
            'steps' => ['type' => PARAM_RAW, 'null' => NULL_ALLOWED, 'default' => null],
            'options' => ['type' => PARAM_RAW, 'null' => NULL_ALLOWED, 'default' => null],
            'filename' => ['type' => PARAM_FILE, 'null' => NULL_ALLOWED, 'default' => null],
            'packagesize' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'packagehash' => ['type' => PARAM_ALPHANUM, 'null' => NULL_ALLOWED, 'default' => null],
            'errorstep' => ['type' => PARAM_ALPHA, 'null' => NULL_ALLOWED, 'default' => null],
            'errormessage' => ['type' => PARAM_RAW, 'null' => NULL_ALLOWED, 'default' => null],
            'cancelrequested' => ['type' => PARAM_INT, 'default' => 0],
            'timestarted' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
            'timefinished' => ['type' => PARAM_INT, 'null' => NULL_ALLOWED, 'default' => null],
        ];
    }

    /**
     * Only names this plugin generates may be stored as the package file name.
     *
     * @param string|null $value
     * @return true|\lang_string
     */
    protected function validate_filename($value) {
        if ($value !== null && !layout::is_valid_filename($value)) {
            return new \lang_string('error:invalidpath', 'tool_moodleclone', 'filename');
        }
        return true;
    }

    /**
     * Whether a status change is allowed.
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public static function is_valid_transition(string $from, string $to): bool {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Change a job's status only if it is still in the expected status.
     *
     * @param int $id
     * @param string $from Expected current status.
     * @param string $to New status.
     * @param array $fields Other columns to set in the same statement.
     * @return bool True when this call made the change.
     * @throws \moodle_exception For a transition the state machine does not allow.
     */
    public static function change_status(int $id, string $from, string $to, array $fields = []): bool {
        global $DB;
        if (!self::is_valid_transition($from, $to)) {
            throw new \moodle_exception('error:invalidtransition', 'tool_moodleclone', '', "{$from} -> {$to}");
        }
        $sets = ['status = :newstatus', 'timemodified = :now'];
        $params = ['newstatus' => $to, 'now' => time(), 'id' => $id, 'oldstatus' => $from];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::UPDATABLE, true)) {
                throw new \coding_exception("Column {$column} cannot be updated here");
            }
            $sets[] = "{$column} = :f{$column}";
            $params['f' . $column] = $value;
        }
        $before = $DB->get_field(self::TABLE, 'timemodified', ['id' => $id, 'status' => $from]);
        if ($before === false) {
            return false;
        }
        $DB->execute('UPDATE {' . self::TABLE . '} SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND status = :oldstatus', $params);
        return $DB->record_exists(self::TABLE, ['id' => $id, 'status' => $to]);
    }

    /**
     * Update progress columns while the job runs (no-op once it is no longer running).
     *
     * @param int $id
     * @param array $fields Subset of currentstep, progress, steps.
     * @return void
     */
    public static function update_progress(int $id, array $fields): void {
        global $DB;
        $sets = ['timemodified = :now'];
        $params = ['now' => time(), 'id' => $id, 'running' => self::STATUS_RUNNING];
        foreach ($fields as $column => $value) {
            if (!in_array($column, ['currentstep', 'progress', 'steps'], true)) {
                throw new \coding_exception("Column {$column} is not a progress column");
            }
            $sets[] = "{$column} = :f{$column}";
            $params['f' . $column] = $value;
        }
        $DB->execute('UPDATE {' . self::TABLE . '} SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND status = :running', $params);
    }

    /**
     * The oldest job that is pending or running, if any.
     *
     * @return self|null
     */
    public static function get_active(): ?self {
        global $DB;
        $records = $DB->get_records_select(
            self::TABLE,
            'status IN (:pending, :running)',
            ['pending' => self::STATUS_PENDING, 'running' => self::STATUS_RUNNING],
            'id ASC',
            '*',
            0,
            1
        );
        return $records ? new self(0, reset($records)) : null;
    }

    /**
     * Options stored with the job.
     *
     * @return options
     */
    public function get_backup_options(): options {
        $options = new options();
        $data = json_decode((string) $this->get('options'), true);
        if (is_array($data)) {
            $options->includecode = !empty($data['moodle']);
            $options->includedataroot = !empty($data['moodledata']);
            $options->includedatabase = !empty($data['database']);
            if (is_array($data['installer_auth'] ?? null) && !installer_auth::validate($data['installer_auth'])) {
                $options->installerauth = installer_auth::from_array($data['installer_auth']);
            }
        }
        return $options;
    }

    /**
     * How the installer of this job's package is protected: "password" or "keyfile" (null when unknown).
     *
     * Reads only the mode, which stays in the record after the password verifier has been removed.
     *
     * @return string|null
     */
    public function get_installer_mode(): ?string {
        $data = json_decode((string) $this->get('options'), true);
        $mode = is_array($data) && is_array($data['installer_auth'] ?? null) ? ($data['installer_auth']['mode'] ?? null) : null;
        return in_array($mode, installer_auth::MODES, true) ? $mode : null;
    }

    /**
     * Per-step state.
     *
     * Not named get_steps(): core\persistent treats get_<property>() as a custom
     * getter for that property, which would recurse.
     *
     * @return array Stage => ['status' => string, 'progress' => int, 'error' => ?string]
     */
    public function get_step_states(): array {
        $steps = json_decode((string) $this->raw_get('steps'), true);
        return is_array($steps) ? $steps : [];
    }

    /**
     * Pending or running.
     *
     * @return bool
     */
    public function is_active(): bool {
        return in_array($this->get('status'), [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }
}
