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

namespace tool_moodleclone\local\backup;

use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\manifest;

/**
 * Which parts of the site a backup should contain.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class options {

    /** @var bool Include the Moodle code tree. */
    public $includecode = true;

    /** @var bool Include persistent moodledata. */
    public $includedataroot = true;

    /** @var bool Include the database dump. */
    public $includedatabase = true;

    /** @var installer_auth|null How the installer authorizes its user; null means the key file. */
    public $installerauth = null;

    /**
     * How the installer authorizes its user.
     *
     * @return installer_auth
     */
    public function get_installer_auth(): installer_auth {
        return $this->installerauth ?? installer_auth::keyfile();
    }

    /**
     * What the job record stores while the job waits: the contents plus the installer
     * authorization, including a password verifier (never the password). The worker
     * removes the verifier from the record as soon as it has read it.
     *
     * @return array
     */
    public function to_job_data(): array {
        return $this->to_contents() + ['installer_auth' => $this->get_installer_auth()->to_array()];
    }

    /**
     * The manifest package_contents object for these options.
     *
     * @return bool[]
     */
    public function to_contents(): array {
        return [
            manifest::CONTENT_MOODLE => (bool) $this->includecode,
            manifest::CONTENT_MOODLEDATA => (bool) $this->includedataroot,
            manifest::CONTENT_DATABASE => (bool) $this->includedatabase,
        ];
    }

    /**
     * Whether at least one component is selected.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return !in_array(true, $this->to_contents(), true);
    }
}
