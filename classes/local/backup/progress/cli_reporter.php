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

namespace tool_moodleclone\local\backup\progress;

use tool_moodleclone\local\backup\stage;

/**
 * Prints "[1/7] Collecting Moodle files        47%" lines for the CLI.
 *
 * On a terminal the percentage is updated in place; otherwise (cron, pipes)
 * only the final line of each step is printed, so logs stay readable.
 * The error itself is printed by the caller, after redaction.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cli_reporter implements reporter {

    /** @var resource */
    private $out;

    /** @var bool */
    private $interactive;

    /** @var string Prefix of the current line. */
    private $line = '';

    /** @var int Last printed percentage. */
    private $lastpercent = -1;

    /**
     * Constructor.
     *
     * @param resource|null $out Defaults to STDOUT.
     * @param bool|null $interactive Defaults to whether $out is a terminal.
     */
    public function __construct($out = null, ?bool $interactive = null) {
        $this->out = $out ?? STDOUT;
        if ($interactive === null) {
            $interactive = function_exists('stream_isatty') && @stream_isatty($this->out);
        }
        $this->interactive = $interactive;
    }

    /**
     * Start a line.
     *
     * @param string $stage
     * @param int $index
     * @param int $total
     * @return void
     */
    public function step_started(string $stage, int $index, int $total): void {
        $this->line = sprintf('[%d/%d] %-32s', $index, $total, stage::get_label($stage));
        $this->lastpercent = -1;
        $this->step_progress($stage, 0.0);
    }

    /**
     * Update the percentage.
     *
     * @param string $stage
     * @param float $fraction
     * @return void
     */
    public function step_progress(string $stage, float $fraction): void {
        $percent = (int) floor(max(0.0, min(1.0, $fraction)) * 100);
        if ($this->interactive && $percent !== $this->lastpercent) {
            $this->lastpercent = $percent;
            fwrite($this->out, "\r" . $this->line . sprintf('%3d%%', $percent));
        }
    }

    /**
     * Finish the line at 100%.
     *
     * @param string $stage
     * @return void
     */
    public function step_completed(string $stage): void {
        fwrite($this->out, ($this->interactive ? "\r" : '') . $this->line . "100%\n");
    }

    /**
     * Print a skipped step.
     *
     * @param string $stage
     * @param int $index
     * @param int $total
     * @return void
     */
    public function step_skipped(string $stage, int $index, int $total): void {
        fwrite($this->out, sprintf('[%d/%d] %-32s', $index, $total, stage::get_label($stage)) .
            get_string('status:skipped', 'tool_moodleclone') . "\n");
    }

    /**
     * Print the failure.
     *
     * @param string $stage
     * @param string $error
     * @return void
     */
    public function step_failed(string $stage, string $error): void {
        fwrite($this->out, ($this->interactive ? "\r" : '') . $this->line .
            get_string('status:failed', 'tool_moodleclone') . "\n");
    }
}
