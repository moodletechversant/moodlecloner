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

use tool_moodleclone\local\backup\backup_exception;
use tool_moodleclone\local\filesystem\path_validator;

/**
 * Writes checksums.sha256 incrementally to a spool file.
 *
 * The in-memory {@see checksums} class is used to read and verify lists; this
 * one exists because a large site has millions of entries, which would not
 * fit in PHP memory. Lines are written in archive order.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checksum_writer {
    /** @var string */
    private $path;

    /** @var resource|null */
    private $fh;

    /** @var int */
    private $count = 0;

    /**
     * Constructor.
     *
     * @param string $path Spool file to create.
     */
    public function __construct(string $path) {
        $this->path = $path;
        $this->fh = @fopen($path, 'xb');
        if (!$this->fh) {
            throw new backup_exception('cannotcreate', basename($path));
        }
        @chmod($path, 0600);
    }

    /**
     * Record the digest of a package entry.
     *
     * @param string $path Package-relative entry name.
     * @param string $hash Hex SHA-256.
     * @return void
     */
    public function add(string $path, string $hash): void {
        if ($this->fh === null) {
            throw new \coding_exception('Checksum list is closed');
        }
        if (!path_validator::is_safe_relative_path($path) || $path === layout::CHECKSUMS) {
            throw new invalid_package_exception(['invalid checksum path']);
        }
        $hash = strtolower($hash);
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new invalid_package_exception(['invalid sha256 digest']);
        }
        $line = $hash . '  ' . $path . "\n";
        if (fwrite($this->fh, $line) !== strlen($line)) {
            throw new backup_exception('writefailed', basename($this->path));
        }
        $this->count++;
    }

    /**
     * Number of lines written.
     *
     * @return int
     */
    public function count(): int {
        return $this->count;
    }

    /**
     * Close the spool and return its path.
     *
     * @return string
     */
    public function close(): string {
        if ($this->fh !== null) {
            if (!fflush($this->fh)) {
                throw new backup_exception('writefailed', basename($this->path));
            }
            fclose($this->fh);
            $this->fh = null;
        }
        return $this->path;
    }
}
