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

use tool_moodleclone\local\filesystem\tree_entry;
use tool_moodleclone\local\filesystem\tree_walker;

/**
 * Conservative estimate of the disk space a backup needs.
 *
 * Everything is written to the dataroot filesystem (working files and the
 * final package live under dataroot/moodleclone, and the final rename is on
 * the same filesystem, so it needs no extra space). The estimate assumes:
 *  - no compression at all (code usually deflates to ~30%, but filedir is
 *    mostly already-compressed media and is stored as is);
 *  - the compressed database dump is no larger than the tables' data length
 *    (hex encoding doubles the text, gzip then shrinks it well below that);
 *  - 1 KB per entry for headers, central directory (written twice: spool and
 *    archive) and checksum lines (spool and archive);
 *  - 10% on top of the data, plus a fixed 256 MB reserve so the site keeps
 *    some free space while the backup runs.
 * The database is streamed straight into the archive, and files are never
 * staged, so there is no additional copy of the data.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class size_estimator {

    /** @var int Per-entry overhead. */
    public const ENTRY_OVERHEAD = 1024;

    /** @var int Fixed reserve. */
    public const RESERVE = 268435456;

    /** @var float Safety factor applied to the data. */
    public const FACTOR = 1.10;

    /**
     * Count files and bytes of a tree the same way the collectors will walk it.
     *
     * @param string $root
     * @param callable $filter
     * @return array ['files', 'directories', 'symlinks', 'bytes']
     * @throws backup_exception Where the collector would also fail (external symlink, unreadable dir, ...).
     */
    public function estimate_tree(string $root, callable $filter): array {
        $result = ['files' => 0, 'directories' => 0, 'symlinks' => 0, 'bytes' => 0];
        foreach ((new tree_walker($root, $filter))->walk() as $entry) {
            if ($entry->type === tree_entry::FILE) {
                $result['files']++;
                $result['bytes'] += $entry->size;
            } else if ($entry->type === tree_entry::DIRECTORY) {
                $result['directories']++;
            } else {
                $result['symlinks']++;
            }
        }
        return $result;
    }

    /**
     * Cheap summary of the Moodle tables from information_schema (statistics, approximate).
     *
     * @param \moodle_database $db
     * @return array ['tables', 'rows', 'bytes', 'nontransactional' => string[], 'views' => string[]]
     */
    public function database_summary(\moodle_database $db): array {
        $summary = ['tables' => 0, 'rows' => 0, 'bytes' => 0, 'nontransactional' => [], 'views' => []];
        if ($db->get_dbfamily() !== 'mysql') {
            return $summary;
        }
        $prefix = $db->get_prefix();
        $recordset = $db->get_recordset_sql('SELECT TABLE_NAME AS name, TABLE_TYPE AS tabletype, ENGINE AS engine,
                TABLE_ROWS AS tablerows, DATA_LENGTH AS datalength
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($recordset as $record) {
            if ($prefix !== '' && strpos($record->name, $prefix) !== 0) {
                continue;
            }
            if ($record->tabletype !== 'BASE TABLE') {
                $summary['views'][] = $record->name;
                continue;
            }
            if (strcasecmp((string) $record->engine, 'InnoDB') !== 0) {
                $summary['nontransactional'][] = $record->name . ' (' . $record->engine . ')';
            }
            $summary['tables']++;
            $summary['rows'] += (int) $record->tablerows;
            $summary['bytes'] += (int) $record->datalength;
        }
        $recordset->close();
        return $summary;
    }

    /**
     * Full estimate for a backup.
     *
     * @param source_paths $paths
     * @param options $options
     * @param \moodle_database $db
     * @param string $workspacebase Directory on the target filesystem (for free space).
     * @return array ['moodle' => ?array, 'moodledata' => ?array, 'database' => ?array, 'entries', 'required', 'free']
     */
    public function estimate(source_paths $paths, options $options, \moodle_database $db, string $workspacebase): array {
        $estimate = ['moodle' => null, 'moodledata' => null, 'database' => null];
        if ($options->includecode) {
            $estimate['moodle'] = $this->estimate_tree($paths->dirroot,
                content_policy::for_site_code($paths)->get_filter());
        }
        if ($options->includedataroot) {
            $data = $this->estimate_tree($paths->dataroot, content_policy::for_site_dataroot($paths)->get_filter());
            if ($paths->customfiledir) {
                foreach ($this->estimate_tree($paths->filedir, function() {
                    return true;
                }) as $key => $value) {
                    $data[$key] += $value;
                }
            }
            $estimate['moodledata'] = $data;
        }
        if ($options->includedatabase) {
            $estimate['database'] = $this->database_summary($db);
        }

        $payload = 0;
        $entries = 3;
        foreach (['moodle', 'moodledata'] as $component) {
            if ($estimate[$component] !== null) {
                $payload += $estimate[$component]['bytes'];
                $entries += $estimate[$component]['files'] + $estimate[$component]['directories'] +
                    $estimate[$component]['symlinks'];
            }
        }
        if ($estimate['database'] !== null) {
            $payload += $estimate['database']['bytes'];
        }
        $estimate['entries'] = $entries;
        $estimate['required'] = self::required_bytes($payload, $entries);
        $estimate['free'] = self::free_space($workspacebase);
        return $estimate;
    }

    /**
     * Required free space for a payload.
     *
     * @param int $payload Bytes of data to archive.
     * @param int $entries Number of archive entries.
     * @return int
     */
    public static function required_bytes(int $payload, int $entries): int {
        return (int) ceil($payload * self::FACTOR) + $entries * self::ENTRY_OVERHEAD + self::RESERVE;
    }

    /**
     * Free bytes on the filesystem holding $path (or its nearest existing parent).
     *
     * @param string $path
     * @return int|null
     */
    public static function free_space(string $path): ?int {
        while ($path !== '' && !is_dir($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }
            $path = $parent;
        }
        if (!function_exists('disk_free_space')) {
            return null;
        }
        $free = @disk_free_space($path);
        return $free === false ? null : (int) $free;
    }
}
