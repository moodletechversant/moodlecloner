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

namespace tool_moodleclone\local\backup\step;

use tool_moodleclone\local\backup\backup_state;

/**
 * Base for steps that are defined but deliberately not implemented yet.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class not_implemented_step implements step {
    /**
     * Not available until implemented.
     *
     * @return bool
     */
    public function is_available(): bool {
        return false;
    }

    /**
     * Refuse to run.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        throw new \coding_exception('Backup stage ' . $this->get_stage() . ' is not implemented yet');
    }
}
