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

namespace tool_moodleclone\local\backup;

use tool_moodleclone\local\environment\collector;
use tool_moodleclone\local\environment\snapshot;
use tool_moodleclone\local\package\workspace;

/**
 * Checks whether a backup can run on this server.
 *
 * Shared by the admin page, the CLI and the job runner so they all report the
 * same problems. Two depths:
 *  - quick (admin page): no estimate; cheap checks only;
 *  - full (CLI --check and every job before it writes anything): with a size
 *    estimate, which walks both trees exactly as the collectors will, so an
 *    external symlink or unreadable directory is found before the backup
 *    starts, and the disk space check compares against a real estimate.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight {
    /** @var string Check passed. */
    public const OK = 'ok';

    /** @var string Check passed with a caveat. */
    public const WARNING = 'warning';

    /** @var string Backup cannot run. */
    public const ERROR = 'error';

    /** @var string[] DML driver types verified with this release. */
    public const TESTED_DRIVERS = ['mysqli'];

    /** @var snapshot */
    private $snapshot;

    /** @var manager */
    private $manager;

    /** @var array|null From size_estimator::estimate(). */
    private $estimate;

    /** @var options */
    private $options;

    /** @var array|null From size_estimator::database_summary(). */
    private $database;

    /** @var string|null Directory the package will be written to (for writability). */
    private $workspacebase;

    /**
     * Constructor.
     *
     * @param snapshot $snapshot
     * @param manager $manager
     * @param array|null $estimate Full estimate, or null for a quick check.
     * @param options|null $options Defaults to everything.
     * @param array|null $database Table summary (engines); taken from $estimate when omitted.
     * @param string|null $workspacebase
     */
    public function __construct(
        snapshot $snapshot,
        manager $manager,
        ?array $estimate = null,
        ?options $options = null,
        ?array $database = null,
        ?string $workspacebase = null
    ) {
        $this->snapshot = $snapshot;
        $this->manager = $manager;
        $this->estimate = $estimate;
        $this->options = $options ?? new options();
        $this->database = $database ?? ($estimate['database'] ?? null);
        $this->workspacebase = $workspacebase;
    }

    /**
     * Run all checks.
     *
     * @return array[] Each ['check' => string, 'status' => string, 'message' => string].
     */
    public function run(): array {
        $results = [
            $this->check_extensions(),
            $this->check_readable('dirroot', $this->snapshot->dirroot),
            $this->check_readable('dataroot', $this->snapshot->dataroot),
        ];
        if ($this->options->includedatabase) {
            $results[] = $this->check_database();
            if ($this->database !== null) {
                $results[] = $this->check_engines();
            }
        }
        if ($this->workspacebase !== null) {
            $results[] = $this->check_workspace();
        }
        $results[] = $this->check_disk_space();
        $results[] = $this->check_pipeline();
        return $results;
    }

    /**
     * The quick checks shown on the admin page and applied before queuing a backup.
     *
     * @param snapshot $snapshot
     * @param workspace $workspace
     * @return array[]
     */
    public static function quick(snapshot $snapshot, workspace $workspace): array {
        global $DB;
        $summary = (new size_estimator())->database_summary($DB);
        return (new self($snapshot, manager::create_default(), null, new options(), $summary, $workspace->get_base()))->run();
    }

    /**
     * Whether any check returned an error.
     *
     * @param array[] $results From run().
     * @return bool
     */
    public static function has_errors(array $results): bool {
        return in_array(self::ERROR, array_column($results, 'status'), true);
    }

    /**
     * Messages of the failed checks.
     *
     * @param array[] $results
     * @return string[]
     */
    public static function get_errors(array $results): array {
        return array_column(array_filter($results, function (array $r) {
            return $r['status'] === self::ERROR;
        }), 'message');
    }

    /**
     * Required PHP extensions.
     *
     * @return array
     */
    private function check_extensions(): array {
        $missing = $this->snapshot->get_missing_extensions(collector::get_required_extensions($this->snapshot->dbtype));
        if ($missing) {
            return $this->result(
                'extensions',
                self::ERROR,
                get_string('check:extensions_missing', 'tool_moodleclone', implode(', ', $missing))
            );
        }
        return $this->result('extensions', self::OK, get_string('check:extensions_ok', 'tool_moodleclone'));
    }

    /**
     * A source directory must be readable.
     *
     * @param string $name dirroot or dataroot.
     * @param string|null $path
     * @return array
     */
    private function check_readable(string $name, ?string $path): array {
        if ($path === null || $path === '' || !is_dir($path) || !is_readable($path)) {
            return $this->result($name, self::ERROR, get_string('check:notreadable', 'tool_moodleclone', $name));
        }
        return $this->result($name, self::OK, get_string('check:readable', 'tool_moodleclone', $name));
    }

    /**
     * Only MySQL can be dumped in this release.
     *
     * @return array
     */
    private function check_database(): array {
        if ($this->snapshot->dbfamily !== 'mysql') {
            return $this->result(
                'database',
                self::ERROR,
                get_string('check:database_unsupported', 'tool_moodleclone', $this->snapshot->dbtype)
            );
        }
        if (!in_array($this->snapshot->dbtype, self::TESTED_DRIVERS, true)) {
            return $this->result(
                'database',
                self::WARNING,
                get_string('check:database_untested', 'tool_moodleclone', $this->snapshot->dbtype)
            );
        }
        return $this->result('database', self::OK, get_string(
            'check:database_ok',
            'tool_moodleclone',
            $this->snapshot->dbtype . ' ' . ($this->snapshot->dbversion ?? '')
        ));
    }

    /**
     * All tables must be transactional for a consistent snapshot.
     *
     * @return array
     */
    private function check_engines(): array {
        if (!empty($this->database['nontransactional'])) {
            return $this->result('engines', self::ERROR, get_string(
                'check:engines_bad',
                'tool_moodleclone',
                implode(', ', array_slice($this->database['nontransactional'], 0, 10))
            ));
        }
        return $this->result(
            'engines',
            self::OK,
            get_string('check:engines_ok', 'tool_moodleclone', (int) $this->database['tables'])
        );
    }

    /**
     * The package location must be writable.
     *
     * @return array
     */
    private function check_workspace(): array {
        $problem = (new workspace(dirname($this->workspacebase)))->check();
        if ($problem !== null) {
            return $this->result('workspace', self::ERROR, $problem);
        }
        return $this->result('workspace', self::OK, get_string('check:workspace_ok', 'tool_moodleclone'));
    }

    /**
     * With an estimate, compare required and free space; otherwise only report free space.
     *
     * @return array
     */
    private function check_disk_space(): array {
        if ($this->estimate !== null) {
            $a = (object) [
                'required' => display_size($this->estimate['required']),
                'free' => $this->estimate['free'] === null ? '?' : display_size($this->estimate['free']),
            ];
            if ($this->estimate['free'] === null) {
                return $this->result('diskspace', self::WARNING, get_string('check:diskspace_unknown', 'tool_moodleclone'));
            }
            if ($this->estimate['free'] < $this->estimate['required']) {
                return $this->result('diskspace', self::ERROR, get_string('check:diskspace_low', 'tool_moodleclone', $a));
            }
            return $this->result('diskspace', self::OK, get_string('check:diskspace_enough', 'tool_moodleclone', $a));
        }
        if ($this->snapshot->freediskspace === null) {
            return $this->result('diskspace', self::WARNING, get_string('check:diskspace_unknown', 'tool_moodleclone'));
        }
        return $this->result(
            'diskspace',
            self::OK,
            get_string('check:diskspace', 'tool_moodleclone', display_size($this->snapshot->freediskspace))
        );
    }

    /**
     * Every backup step must be implemented.
     *
     * @return array
     */
    private function check_pipeline(): array {
        $unavailable = $this->manager->get_unavailable_stages();
        if ($unavailable) {
            $labels = array_map([stage::class, 'get_label'], $unavailable);
            return $this->result(
                'pipeline',
                self::ERROR,
                get_string('check:pipeline_incomplete', 'tool_moodleclone', implode(', ', $labels))
            );
        }
        return $this->result('pipeline', self::OK, get_string('check:pipeline_ok', 'tool_moodleclone'));
    }

    /**
     * Build a result row.
     *
     * @param string $check
     * @param string $status
     * @param string $message
     * @return array
     */
    private function result(string $check, string $status, string $message): array {
        return ['check' => $check, 'status' => $status, 'message' => $message];
    }
}
