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
 * Logger that keeps messages in memory, for the web UI summary and for tests.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class memory_logger extends base_logger {

    /** @var array[] Each entry is ['level' => string, 'message' => string]. */
    private $entries = [];

    /**
     * Store the message.
     *
     * @param string $level
     * @param string $message
     * @return void
     */
    protected function write(string $level, string $message): void {
        $this->entries[] = ['level' => $level, 'message' => $message];
    }

    /**
     * Logged entries in order.
     *
     * @return array[]
     */
    public function get_entries(): array {
        return $this->entries;
    }

    /**
     * Logged messages in order, without levels.
     *
     * @return string[]
     */
    public function get_messages(): array {
        return array_column($this->entries, 'message');
    }
}
