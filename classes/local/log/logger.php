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
 * Destination for backup progress messages.
 *
 * Implementations must pass messages through a {@see redactor} before they
 * reach any persistent or visible output; {@see base_logger} does this.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface logger {

    /** @var string Normal progress. */
    public const INFO = 'info';

    /** @var string Something unexpected that does not stop the backup. */
    public const WARNING = 'warning';

    /** @var string A failure. */
    public const ERROR = 'error';

    /**
     * Record a message.
     *
     * @param string $level One of the level constants.
     * @param string $message Plain text. Must not contain user data beyond what an administrator needs.
     * @return void
     */
    public function log(string $level, string $message): void;
}
