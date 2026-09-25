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
 * Reporter that ignores everything.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class null_reporter implements reporter {

    /**
     * Ignored.
     *
     * @param string $stage
     * @param int $index
     * @param int $total
     * @return void
     */
    public function step_started(string $stage, int $index, int $total): void {
    }

    /**
     * Ignored.
     *
     * @param string $stage
     * @param float $fraction
     * @return void
     */
    public function step_progress(string $stage, float $fraction): void {
    }

    /**
     * Ignored.
     *
     * @param string $stage
     * @return void
     */
    public function step_completed(string $stage): void {
    }

    /**
     * Ignored.
     *
     * @param string $stage
     * @param int $index
     * @param int $total
     * @return void
     */
    public function step_skipped(string $stage, int $index, int $total): void {
    }

    /**
     * Ignored.
     *
     * @param string $stage
     * @param string $error
     * @return void
     */
    public function step_failed(string $stage, string $error): void {
    }
}
