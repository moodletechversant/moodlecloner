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
use tool_moodleclone\local\backup\content_policy;
use tool_moodleclone\local\backup\options;
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\package\layout;
use tool_moodleclone\local\package\manifest;

/**
 * Adds the Moodle code tree ($CFG->dirroot) under moodle/.
 *
 * The whole tree is copied (not a list of known directories), minus
 * content_policy::for_site_code(): config.php (holds the database password),
 * .git, node_modules and any data directory nested inside dirroot.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class code_collector extends tree_collector {
    /**
     * The stage this step performs.
     *
     * @return string
     */
    public function get_stage(): string {
        return stage::CODE;
    }

    /**
     * Included when code is selected.
     *
     * @param options $options
     * @return bool
     */
    public function is_included(options $options): bool {
        return (bool) $options->includecode;
    }

    /**
     * Collect.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        $stats = self::empty_stats();
        $done = 0;
        $total = (int) ($state->estimate[manifest::CONTENT_MOODLE]['bytes'] ?? 0);
        $policy = content_policy::for_site_code($state->sources);
        $this->collect_tree(
            $state,
            $state->sources->dirroot,
            layout::CODE_DIR,
            $policy->get_filter(),
            function () {
                return true;
            },
            $stats,
            $total,
            $done
        );
        $state->statistics[manifest::CONTENT_MOODLE] = $stats;
    }
}
