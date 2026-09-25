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
use tool_moodleclone\local\package\layout;

/**
 * Adds checksums.sha256 to the package.
 *
 * The digests themselves were computed by the collectors while archiving
 * (from the exact bytes written); this step closes the list and archives it.
 * It covers every regular file entry, including manifest.json and
 * database.sql.gz, but not itself (no circular dependency) and not the
 * finished ZIP (whose digest is published separately, next to it).
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checksum_generator implements step {

    /**
     * The stage this step performs.
     *
     * @return string
     */
    public function get_stage(): string {
        return stage::CHECKSUMS;
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
     * Archive the checksum list.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        if ($state->manifest === null) {
            throw new \coding_exception('The manifest step must run before the checksums step');
        }
        $spool = $state->checksums->close();
        $state->zip->add_file(layout::CHECKSUMS, $spool, true);
    }
}
