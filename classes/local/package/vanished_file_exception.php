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

namespace tool_moodleclone\local\package;

use tool_moodleclone\local\backup\backup_exception;

/**
 * Thrown when a file disappears between being listed and being read.
 *
 * On a live site this is normal (a temporary file was removed), so collectors
 * log it and continue instead of failing the backup.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vanished_file_exception extends backup_exception {
    /**
     * Constructor.
     *
     * @param string $name Entry name.
     */
    public function __construct(string $name) {
        parent::__construct('vanished', $name);
    }
}
