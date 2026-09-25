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

use moodle_database;

/**
 * Collects information about the running Moodle installation.
 *
 * Configuration and database handles are injected so the collector can be
 * tested against fake values. Only the $CFG properties listed in this class
 * are read; $CFG->dbpass is never touched.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collector {

    /** @var string[] Extensions every backup needs, independent of database driver. */
    public const REQUIRED_EXTENSIONS = ['zip', 'zlib', 'json', 'hash'];

    /** @var string[] Extensions that are useful but not mandatory (encryption, CLI user checks). */
    public const OPTIONAL_EXTENSIONS = ['openssl', 'posix'];

    /** @var string[] DML driver => PHP extension that driver needs. */
    public const DRIVER_EXTENSIONS = [
        'mysqli' => 'mysqli',
        'mariadb' => 'mysqli',
        'auroramysql' => 'mysqli',
        'pgsql' => 'pgsql',
        'sqlsrv' => 'sqlsrv',
        'oci' => 'oci8',
    ];

    /** @var \stdClass */
    private $cfg;

    /** @var moodle_database */
    private $db;

    /**
     * Constructor.
     *
     * @param \stdClass|null $cfg Configuration object, defaults to global $CFG.
     * @param moodle_database|null $db Database, defaults to global $DB.
     */
    public function __construct(?\stdClass $cfg = null, ?moodle_database $db = null) {
        global $CFG, $DB;
        $this->cfg = $cfg ?? $CFG;
        $this->db = $db ?? $DB;
    }

    /**
     * Collect a snapshot of the current installation.
     *
     * @return snapshot
     */
    public function collect(): snapshot {
        $snapshot = new snapshot();
        $snapshot->moodleversion = (string) ($this->cfg->version ?? '');
        $snapshot->moodlerelease = (string) ($this->cfg->release ?? '');
        $snapshot->moodlebranch = (string) ($this->cfg->branch ?? '');
        $snapshot->phpversion = PHP_VERSION;
        $snapshot->dbtype = (string) ($this->cfg->dbtype ?? '');
        $snapshot->dbfamily = $this->db->get_dbfamily();
        $snapshot->dbversion = $this->get_database_version();
        $snapshot->dbname = (string) ($this->cfg->dbname ?? '');
        $snapshot->dbuser = (string) ($this->cfg->dbuser ?? '');
        $snapshot->prefix = (string) ($this->cfg->prefix ?? '');
        $snapshot->wwwroot = (string) ($this->cfg->wwwroot ?? '');
        $snapshot->dirroot = (string) ($this->cfg->dirroot ?? '');
        $snapshot->dataroot = (string) ($this->cfg->dataroot ?? '');
        $snapshot->os = $this->get_operating_system();
        $snapshot->freediskspace = $this->get_free_disk_space($snapshot->dataroot);
        $snapshot->extensions = $this->get_extensions($snapshot->dbtype);
        $snapshot->timecollected = time();
        return $snapshot;
    }

    /**
     * Database server version as reported by the DML driver.
     *
     * @return string|null
     */
    protected function get_database_version(): ?string {
        try {
            $info = $this->db->get_server_info();
        } catch (\Throwable $e) {
            // Never let an informational lookup break the page; the exception
            // text may also contain connection details, so it is not rethrown.
            return null;
        }
        return isset($info['version']) && $info['version'] !== '' ? (string) $info['version'] : null;
    }

    /**
     * Operating system family plus release where php_uname() is permitted.
     *
     * @return string
     */
    protected function get_operating_system(): string {
        $os = PHP_OS_FAMILY;
        if (function_exists('php_uname')) {
            $release = @php_uname('r');
            if (is_string($release) && $release !== '') {
                $os .= ' ' . $release;
            }
        }
        return $os;
    }

    /**
     * Free bytes on the filesystem that holds $path.
     *
     * @param string $path
     * @return int|null Null when unknown (function disabled, path missing).
     */
    protected function get_free_disk_space(string $path): ?int {
        if ($path === '' || !function_exists('disk_free_space') || !is_dir($path)) {
            return null;
        }
        $free = @disk_free_space($path);
        return $free === false ? null : (int) $free;
    }

    /**
     * Load state of every extension relevant to backup for the given driver.
     *
     * @param string $dbtype
     * @return bool[] Extension name => loaded.
     */
    protected function get_extensions(string $dbtype): array {
        $names = array_merge(self::REQUIRED_EXTENSIONS, self::OPTIONAL_EXTENSIONS);
        if (isset(self::DRIVER_EXTENSIONS[$dbtype])) {
            $names[] = self::DRIVER_EXTENSIONS[$dbtype];
        }
        $result = [];
        foreach (array_unique($names) as $name) {
            $result[$name] = extension_loaded($name);
        }
        return $result;
    }

    /**
     * Extensions a backup of this site cannot run without.
     *
     * @param string $dbtype
     * @return string[]
     */
    public static function get_required_extensions(string $dbtype): array {
        $required = self::REQUIRED_EXTENSIONS;
        if (isset(self::DRIVER_EXTENSIONS[$dbtype])) {
            $required[] = self::DRIVER_EXTENSIONS[$dbtype];
        }
        return $required;
    }
}
