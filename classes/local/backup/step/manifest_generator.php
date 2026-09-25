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
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\package\layout;
use tool_moodleclone\local\package\manifest;

/**
 * Builds manifest.json from the snapshot, the selected options and what the
 * collectors actually archived, and adds it to the package.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manifest_generator implements step {
    /**
     * The stage this step performs.
     *
     * @return string
     */
    public function get_stage(): string {
        return stage::MANIFEST;
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
     * Create the manifest, archive it and record its digest.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        $excluded = [];
        if ($state->options->includedataroot && $state->sources !== null) {
            $excluded = content_policy::for_site_dataroot($state->sources)->describe_exclusions();
        }
        $state->manifest = manifest::from_snapshot(
            $state->snapshot,
            $state->options->to_contents(),
            $state->timestarted,
            self::get_generator(),
            $state->statistics,
            $state->databasedump,
            $excluded,
            $state->options->get_installer_auth()
        );
        $json = $state->manifest->to_json();
        $result = $state->zip->add_string(layout::MANIFEST, $json, true, 0644, $state->timestarted);
        $state->checksums->add(layout::MANIFEST, $result['sha256']);
    }

    /**
     * Version and release of the code producing the package.
     *
     * @return array ['version' => int, 'release' => string]
     */
    public static function get_generator(): array {
        $info = \core_plugin_manager::instance()->get_plugin_info('tool_moodleclone');
        return [
            'version' => (int) ($info->versiondisk ?? get_config('tool_moodleclone', 'version')),
            'release' => (string) ($info->release ?? ''),
        ];
    }
}
