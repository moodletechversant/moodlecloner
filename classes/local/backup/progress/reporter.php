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

/**
 * Receives step-level progress from the backup manager.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface reporter {
    /**
     * A step started.
     *
     * @param string $stage
     * @param int $index 1-based position.
     * @param int $total Number of steps.
     * @return void
     */
    public function step_started(string $stage, int $index, int $total): void;

    /**
     * Progress within the running step.
     *
     * @param string $stage
     * @param float $fraction 0..1
     * @return void
     */
    public function step_progress(string $stage, float $fraction): void;

    /**
     * A step finished successfully.
     *
     * @param string $stage
     * @return void
     */
    public function step_completed(string $stage): void;

    /**
     * A step was not needed for the selected options.
     *
     * @param string $stage
     * @param int $index
     * @param int $total
     * @return void
     */
    public function step_skipped(string $stage, int $index, int $total): void;

    /**
     * A step failed; the backup stops.
     *
     * @param string $stage
     * @param string $error Message (reporters that persist it must redact it).
     * @return void
     */
    public function step_failed(string $stage, string $error): void;
}
