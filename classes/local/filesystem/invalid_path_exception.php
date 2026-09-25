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

namespace tool_moodleclone\local\filesystem;

/**
 * Thrown when a path fails validation.
 *
 * The offending path is deliberately not included in the message: it may be
 * attacker controlled (from a package) or reveal server layout.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invalid_path_exception extends \moodle_exception {

    /** @var string Machine readable reason, e.g. "unsaferelativepath". */
    public $reason;

    /**
     * Constructor.
     *
     * @param string $reason One of unsaferelativepath, basedirmissing, pathescapesbase.
     */
    public function __construct(string $reason) {
        $this->reason = $reason;
        parent::__construct('error:invalidpath', 'tool_moodleclone', '', $reason);
    }
}
