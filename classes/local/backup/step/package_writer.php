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
use tool_moodleclone\local\backup\stage;

/**
 * Completes the ZIP archive: writes the central directory and end records,
 * flushes and syncs the file. The archive is still a temporary file in the
 * job's working directory at this point.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_writer implements step {

    /**
     * The stage this step performs.
     *
     * @return string
     */
    public function get_stage(): string {
        return stage::ARCHIVE;
    }

    /**
     * Implemented.
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Finish the archive.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        $state->zip->finish();
        $state->progress(1.0);
    }
}
