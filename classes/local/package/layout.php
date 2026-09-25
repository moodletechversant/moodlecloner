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

namespace tool_moodleclone\local\package;

use tool_moodleclone\local\filesystem\path_validator;

/**
 * The on-disk layout of a clone package (see manifest::FORMAT).
 *
 * <pre>
 * moodle-clone-YYYY-MM-DD-HHMMSS.zip
 *     manifest.json
 *     database.sql.gz
 *     checksums.sha256
 *     moodle/...          Moodle code ($CFG->dirroot) without config.php
 *     moodledata/...      Persistent part of $CFG->dataroot
 * </pre>
 *
 * Every entry name written to or read from a package goes through this class,
 * so the writer and the future installer agree on the layout.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class layout {
    /** @var string Package metadata. */
    public const MANIFEST = 'manifest.json';

    /** @var string Gzipped SQL dump of the Moodle database. */
    public const DATABASE = 'database.sql.gz';

    /** @var string sha256sum-compatible list of every other entry. */
    public const CHECKSUMS = 'checksums.sha256';

    /** @var string Directory holding the Moodle code tree. */
    public const CODE_DIR = 'moodle';

    /** @var string Directory holding persistent moodledata. */
    public const DATA_DIR = 'moodledata';

    /** @var string Package file name prefix. */
    public const FILENAME_PREFIX = 'moodle-clone-';

    /** @var string Regular expression a package file name must match. */
    public const FILENAME_PATTERN = '/^moodle-clone-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/';

    /**
     * Package file name for a given creation time. Uses UTC so names sort and
     * compare the same way on every server.
     *
     * @param int $time Unix time.
     * @return string e.g. moodle-clone-2026-09-24-134501.zip
     */
    public static function filename(int $time): string {
        return self::FILENAME_PREFIX . gmdate('Y-m-d-His', $time) . '.zip';
    }

    /**
     * Whether a file name looks like a package produced by this plugin.
     *
     * @param string $filename Base name only.
     * @return bool
     */
    public static function is_valid_filename(string $filename): bool {
        return (bool) preg_match(self::FILENAME_PATTERN, $filename);
    }

    /**
     * Archive entry name for a file from the Moodle code tree.
     *
     * @param string $relative Path relative to $CFG->dirroot.
     * @return string
     */
    public static function code_entry(string $relative): string {
        return self::CODE_DIR . '/' . path_validator::require_safe_relative_path($relative);
    }

    /**
     * Archive entry name for a file from moodledata.
     *
     * @param string $relative Path relative to $CFG->dataroot.
     * @return string
     */
    public static function data_entry(string $relative): string {
        return self::DATA_DIR . '/' . path_validator::require_safe_relative_path($relative);
    }

    /**
     * Whether an archive entry name is permitted in a package.
     *
     * Used when reading packages to reject anything unexpected (including
     * traversal attempts) before it is extracted.
     *
     * @param string $entry
     * @return bool
     */
    public static function is_allowed_entry(string $entry): bool {
        if (!path_validator::is_safe_relative_path($entry)) {
            return false;
        }
        if (in_array($entry, [self::MANIFEST, self::DATABASE, self::CHECKSUMS], true)) {
            return true;
        }
        foreach ([self::CODE_DIR, self::DATA_DIR] as $dir) {
            if (strpos($entry, $dir . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a directory entry name (with trailing slash) is permitted.
     *
     * @param string $entry e.g. "moodle/" or "moodledata/filedir/ab/".
     * @return bool
     */
    public static function is_allowed_directory_entry(string $entry): bool {
        if (substr($entry, -1) !== '/') {
            return false;
        }
        $name = substr($entry, 0, -1);
        return $name === self::CODE_DIR || $name === self::DATA_DIR ||
            (path_validator::is_safe_relative_path($name) && self::is_allowed_entry($name));
    }

    /**
     * Which component an entry belongs to.
     *
     * @param string $entry
     * @return string|null manifest::CONTENT_* constant, or null for metadata entries.
     */
    public static function component_of(string $entry): ?string {
        if ($entry === self::DATABASE) {
            return manifest::CONTENT_DATABASE;
        }
        if ($entry === self::CODE_DIR . '/' || strpos($entry, self::CODE_DIR . '/') === 0) {
            return manifest::CONTENT_MOODLE;
        }
        if ($entry === self::DATA_DIR . '/' || strpos($entry, self::DATA_DIR . '/') === 0) {
            return manifest::CONTENT_MOODLEDATA;
        }
        return null;
    }
}
