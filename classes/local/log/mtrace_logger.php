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

namespace tool_moodleclone\local\log;

/**
 * Logger that prints through mtrace(), for CLI scripts and scheduled/adhoc tasks.
 *
 * mtrace() output is captured by the task log when running under cron, so the
 * same logger serves both the CLI and future background execution.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mtrace_logger extends base_logger {
    /**
     * Print the message with a timestamp and level.
     *
     * @param string $level
     * @param string $message
     * @return void
     */
    protected function write(string $level, string $message): void {
        mtrace(gmdate('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $message);
    }
}
