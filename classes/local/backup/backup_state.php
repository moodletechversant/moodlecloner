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

use tool_moodleclone\local\backup\progress\null_reporter;
use tool_moodleclone\local\backup\progress\reporter;
use tool_moodleclone\local\environment\snapshot;
use tool_moodleclone\local\log\logger;
use tool_moodleclone\local\package\checksum_writer;
use tool_moodleclone\local\package\manifest;
use tool_moodleclone\local\package\workspace;
use tool_moodleclone\local\package\zip_writer;

/**
 * Mutable state shared by the steps of one backup run.
 *
 * Steps communicate only through this object, so each can be implemented and
 * tested in isolation.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_state {
    /** @var int Minimum seconds between cancellation checks. */
    private const CANCEL_CHECK_INTERVAL = 5;

    /** @var snapshot Source installation, taken once at the start. */
    public $snapshot;

    /** @var options What to include. */
    public $options;

    /** @var logger Progress output. */
    public $logger;

    /** @var int Unix time the run started; used for the package name and manifest. */
    public $timestarted;

    /** @var checksum_writer|null Digests of every entry added so far. Collectors add to this as they go. */
    public $checksums = null;

    /** @var manifest|null Set by the manifest step. */
    public $manifest = null;

    /** @var reporter Step progress receiver. */
    public $reporter;

    /** @var source_paths|null Source locations from $CFG. */
    public $sources = null;

    /** @var workspace|null */
    public $workspace = null;

    /** @var string|null Job working directory. */
    public $workdir = null;

    /** @var zip_writer|null Archive being written. */
    public $zip = null;

    /** @var array|null From size_estimator::estimate(). */
    public $estimate = null;

    /** @var array Component => statistics for the manifest. */
    public $statistics = [];

    /** @var array|null Database dump description for the manifest. */
    public $databasedump = null;

    /** @var int|null Unix time the database snapshot started. */
    public $snapshottime = null;

    /** @var callable fn(): \moodle_database, opens the dump connection. */
    public $dbconnector = null;

    /** @var string[]|null Restrict the dump to these tables (tests only). */
    public $dumptables = null;

    /** @var callable|null fn(): bool, true when the run must stop. */
    public $cancelcheck = null;

    /** @var callable|null fn(string $filename), called before the package is published. */
    public $onpackagename = null;

    /** @var array|null Set by the finaliser: ['filename', 'path', 'size', 'sha256']. */
    public $result = null;

    /** @var int Entries skipped with a warning (vanished or special files). */
    public $warnings = 0;

    /** @var string|null Stage being executed. */
    public $currentstage = null;

    /** @var float */
    private $lastcancelcheck = 0.0;

    /**
     * Constructor.
     *
     * @param snapshot $snapshot
     * @param options $options
     * @param logger $logger
     * @param int|null $timestarted Defaults to now.
     */
    public function __construct(snapshot $snapshot, options $options, logger $logger, ?int $timestarted = null) {
        $this->snapshot = $snapshot;
        $this->options = $options;
        $this->logger = $logger;
        $this->timestarted = $timestarted ?? time();
        $this->reporter = new null_reporter();
    }

    /**
     * Report progress of the current step and honour cancellation requests.
     *
     * @param float $fraction 0..1
     * @return void
     * @throws backup_cancelled_exception
     */
    public function progress(float $fraction): void {
        if ($this->currentstage !== null) {
            $this->reporter->step_progress($this->currentstage, $fraction);
        }
        $this->check_cancelled();
    }

    /**
     * Throw when cancellation was requested (checked at most every few seconds).
     *
     * @param bool $force Check now regardless of the interval.
     * @return void
     * @throws backup_cancelled_exception
     */
    public function check_cancelled(bool $force = false): void {
        if ($this->cancelcheck === null) {
            return;
        }
        $now = microtime(true);
        if (!$force && $now - $this->lastcancelcheck < self::CANCEL_CHECK_INTERVAL) {
            return;
        }
        $this->lastcancelcheck = $now;
        if (($this->cancelcheck)()) {
            throw new backup_cancelled_exception();
        }
    }

    /**
     * Log a skipped entry.
     *
     * @param string $code "vanished" or "special".
     * @param string $name Entry name.
     * @return void
     */
    public function warn(string $code, string $name): void {
        $this->warnings++;
        $this->logger->log(logger::WARNING, get_string(
            'warning:' . $code,
            'tool_moodleclone',
            backup_exception::printable($name)
        ));
    }
}
