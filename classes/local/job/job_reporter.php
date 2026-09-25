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

use tool_moodleclone\local\backup\progress\reporter;
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\log\redactor;

/**
 * Writes step progress to the job record, for the admin page.
 *
 * Progress writes are throttled (every 2 seconds by default); start, finish
 * and failure of a step are always written. They also serve as the worker's
 * heartbeat (timemodified). Errors are redacted before being stored.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_reporter implements reporter {

    /** @var int Maximum stored error length. */
    public const MAX_ERROR = 2000;

    /** @var int */
    private $jobid;

    /** @var float[] Stage => weight. */
    private $weights;

    /** @var float */
    private $interval;

    /** @var redactor */
    private $redactor;

    /** @var array Stage => ['status', 'progress', 'error']. */
    private $steps = [];

    /** @var float */
    private $lastwrite = 0.0;

    /** @var string|null */
    private $current = null;

    /**
     * Constructor.
     *
     * @param int $jobid
     * @param float[] $weights Stage => relative amount of work (see weights()).
     * @param float $interval Minimum seconds between progress writes.
     * @param redactor|null $redactor
     */
    public function __construct(int $jobid, array $weights, float $interval = 2.0, ?redactor $redactor = null) {
        $this->jobid = $jobid;
        $this->weights = $weights;
        $this->interval = $interval;
        $this->redactor = $redactor ?? redactor::from_config();
    }

    /**
     * Relative weights of the stages, from a size estimate.
     *
     * @param array|null $estimate From size_estimator::estimate().
     * @return float[]
     */
    public static function weights(?array $estimate): array {
        $code = (float) ($estimate['moodle']['bytes'] ?? 0);
        $data = (float) ($estimate['moodledata']['bytes'] ?? 0);
        $db = (float) ($estimate['database']['bytes'] ?? 0);
        $total = max(1.0, $code + $data + $db);
        return [
            stage::CODE => max($code, $total * 0.01),
            stage::DATABASE => max($db, $total * 0.01),
            stage::DATAROOT => max($data, $total * 0.01),
            stage::MANIFEST => $total * 0.001,
            stage::CHECKSUMS => $total * 0.001,
            stage::ARCHIVE => $total * 0.001,
            // Verification reads the whole archive back and hashes it.
            stage::FINALISING => $total * 0.5,
        ];
    }

    /**
     * Step started.
     *
     * @param string $stage
     * @param int $index
     * @param int $total
     * @return void
     */
    public function step_started(string $stage, int $index, int $total): void {
        $this->current = $stage;
        $this->steps[$stage] = ['status' => 'running', 'progress' => 0, 'error' => null];
        $this->write(true);
    }

    /**
     * Step progress.
     *
     * @param string $stage
     * @param float $fraction
     * @return void
     */
    public function step_progress(string $stage, float $fraction): void {
        if (!isset($this->steps[$stage])) {
            return;
        }
        $this->steps[$stage]['progress'] = (int) floor(max(0.0, min(1.0, $fraction)) * 100);
        $this->write(false);
    }

    /**
     * Step completed.
     *
     * @param string $stage
     * @return void
     */
    public function step_completed(string $stage): void {
        $this->steps[$stage] = ['status' => 'completed', 'progress' => 100, 'error' => null];
        $this->write(true);
    }

    /**
     * Step skipped.
     *
     * @param string $stage
     * @param int $index
     * @param int $total
     * @return void
     */
    public function step_skipped(string $stage, int $index, int $total): void {
        $this->steps[$stage] = ['status' => 'skipped', 'progress' => 100, 'error' => null];
        $this->write(true);
    }

    /**
     * Step failed.
     *
     * @param string $stage
     * @param string $error
     * @return void
     */
    public function step_failed(string $stage, string $error): void {
        $this->steps[$stage] = [
            'status' => 'failed',
            'progress' => $this->steps[$stage]['progress'] ?? 0,
            'error' => self::clean_error($error, $this->redactor),
        ];
        $this->write(true);
    }

    /**
     * Overall progress 0..99 (100 is set only when the job completes).
     *
     * @return int
     */
    public function get_overall(): int {
        $sum = array_sum($this->weights);
        if ($sum <= 0) {
            return 0;
        }
        $done = 0.0;
        foreach ($this->weights as $stage => $weight) {
            $step = $this->steps[$stage] ?? null;
            if ($step !== null) {
                $done += $weight * $step['progress'] / 100;
            }
        }
        return (int) min(99, floor($done / $sum * 100));
    }

    /**
     * Current per-step state.
     *
     * @return array
     */
    public function get_steps(): array {
        return $this->steps;
    }

    /**
     * Redact and shorten an error for storage.
     *
     * @param string $error
     * @param redactor $redactor
     * @return string
     */
    public static function clean_error(string $error, redactor $redactor): string {
        $error = $redactor->redact($error);
        return \core_text::substr($error, 0, self::MAX_ERROR);
    }

    /**
     * Persist, throttled unless forced.
     *
     * @param bool $force
     * @return void
     */
    private function write(bool $force): void {
        $now = microtime(true);
        if (!$force && $now - $this->lastwrite < $this->interval) {
            return;
        }
        $this->lastwrite = $now;
        job::update_progress($this->jobid, [
            'currentstep' => $this->current,
            'progress' => $this->get_overall(),
            'steps' => json_encode($this->steps),
        ]);
    }
}
