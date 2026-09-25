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

namespace tool_moodleclone\local;

use context_system;

/**
 * Single place for Moodle Clone permission checks.
 *
 * Every entry point (admin page, future web services, future tasks started from
 * the web UI) must go through this class so the rule cannot drift.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {

    /** @var string The capability that grants use of the tool. */
    public const CAPABILITY = 'tool/moodleclone:manage';

    /**
     * Whether the user may use Moodle Clone.
     *
     * @param int|\stdClass|null $user User id or object, null for the current user.
     * @return bool
     */
    public static function can_manage($user = null): bool {
        return has_capability(self::CAPABILITY, context_system::instance(), $user);
    }

    /**
     * Throw required_capability_exception unless the current user may use Moodle Clone.
     *
     * @return void
     */
    public static function require_manage(): void {
        require_capability(self::CAPABILITY, context_system::instance());
    }
}
