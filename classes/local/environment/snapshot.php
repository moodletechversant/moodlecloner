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

namespace tool_moodleclone\local\environment;

/**
 * Point-in-time description of the source installation.
 *
 * Produced by {@see collector}. There is intentionally no field for the
 * database password or any other secret: the collector never reads them, so
 * they cannot leak through this object.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot {
    /** @var string $CFG->version, e.g. "2022112800.00". */
    public $moodleversion;

    /** @var string $CFG->release, e.g. "4.1 (Build: 20221128)". */
    public $moodlerelease;

    /** @var string $CFG->branch, e.g. "401". */
    public $moodlebranch;

    /** @var string PHP_VERSION. */
    public $phpversion;

    /** @var string $CFG->dbtype (DML driver), e.g. "mysqli", "pgsql". */
    public $dbtype;

    /** @var string DML family, e.g. "mysql", "postgres". */
    public $dbfamily;

    /** @var string|null Server version reported by the driver, null if unavailable. */
    public $dbversion;

    /** @var string $CFG->dbname. */
    public $dbname;

    /** @var string $CFG->dbuser. Sensitive: shown to administrators only, never written to a manifest or log. */
    public $dbuser;

    /** @var string $CFG->prefix. */
    public $prefix;

    /** @var string $CFG->wwwroot. */
    public $wwwroot;

    /** @var string $CFG->dirroot. */
    public $dirroot;

    /** @var string $CFG->dataroot. */
    public $dataroot;

    /** @var string Operating system family and release. */
    public $os;

    /** @var int|null Free bytes on the dataroot filesystem, null if it cannot be determined. */
    public $freediskspace;

    /** @var bool[] Relevant PHP extension name => loaded. */
    public $extensions = [];

    /** @var int Unix time the snapshot was taken. */
    public $timecollected;

    /**
     * Names of the PHP extensions that are required and missing.
     *
     * @param string[] $required
     * @return string[]
     */
    public function get_missing_extensions(array $required): array {
        $missing = [];
        foreach ($required as $name) {
            if (empty($this->extensions[$name])) {
                $missing[] = $name;
            }
        }
        return $missing;
    }
}
