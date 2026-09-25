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
 * The checksums.sha256 list of a clone package.
 *
 * Serialised in GNU coreutils format ("<hash>  <path>" per line) so a package
 * can also be checked by hand with `sha256sum -c checksums.sha256` after
 * extraction. Every entry except checksums.sha256 itself is listed.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checksums implements \Countable {
    /** @var string hash() algorithm name. */
    public const ALGORITHM = 'sha256';

    /** @var array<string, string> Package-relative path => lowercase hex digest. */
    private $entries = [];

    /**
     * Record the digest of an entry.
     *
     * @param string $path Package-relative path.
     * @param string $hash Hex SHA-256 digest.
     * @return void
     * @throws invalid_package_exception
     */
    public function add(string $path, string $hash): void {
        if (!path_validator::is_safe_relative_path($path) || $path === layout::CHECKSUMS) {
            throw new invalid_package_exception(['invalid checksum path']);
        }
        $hash = strtolower($hash);
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new invalid_package_exception(['invalid sha256 digest']);
        }
        if (isset($this->entries[$path])) {
            throw new invalid_package_exception(['duplicate checksum path']);
        }
        $this->entries[$path] = $hash;
    }

    /**
     * Hash a file on disk and record it under a package-relative path.
     *
     * @param string $path Package-relative path.
     * @param string $file Absolute path of the file to hash.
     * @return string The digest.
     */
    public function add_file(string $path, string $file): string {
        $hash = self::hash_file($file);
        $this->add($path, $hash);
        return $hash;
    }

    /**
     * Recorded digest for a path.
     *
     * @param string $path
     * @return string|null
     */
    public function get(string $path): ?string {
        return $this->entries[$path] ?? null;
    }

    /**
     * Whether a file on disk matches the recorded digest for $path.
     *
     * @param string $path Package-relative path.
     * @param string $file Absolute path of the extracted file.
     * @return bool False when the path is unknown or the file is unreadable.
     */
    public function verify_file(string $path, string $file): bool {
        $expected = $this->get($path);
        if ($expected === null || !is_file($file) || !is_readable($file)) {
            return false;
        }
        return hash_equals($expected, self::hash_file($file));
    }

    /**
     * Recorded paths in serialisation order.
     *
     * @return string[]
     */
    public function get_paths(): array {
        $paths = array_keys($this->entries);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * Number of entries.
     *
     * @return int
     */
    public function count(): int {
        return count($this->entries);
    }

    /**
     * Serialise to checksums.sha256 content, sorted by path for reproducibility.
     *
     * @return string
     */
    public function to_string(): string {
        $out = '';
        foreach ($this->get_paths() as $path) {
            $out .= $this->entries[$path] . '  ' . $path . "\n";
        }
        return $out;
    }

    /**
     * Parse checksums.sha256 content.
     *
     * @param string $content
     * @return self
     * @throws invalid_package_exception On the first malformed line.
     */
    public static function from_string(string $content): self {
        $checksums = new self();
        $lines = preg_split('/\r?\n/', $content);
        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }
            $lineno = $index + 1;
            if (!preg_match('/^([0-9a-fA-F]{64}) [ *](.+)$/', $line, $matches)) {
                throw new invalid_package_exception(["malformed checksum line {$lineno}"]);
            }
            try {
                $checksums->add($matches[2], $matches[1]);
            } catch (invalid_package_exception $e) {
                throw new invalid_package_exception(["line {$lineno}: " . implode('; ', $e->errors)]);
            }
        }
        return $checksums;
    }

    /**
     * Stream-hash a file without loading it into memory.
     *
     * @param string $file
     * @return string
     * @throws \moodle_exception When the file cannot be read.
     */
    public static function hash_file(string $file): string {
        $hash = is_readable($file) ? hash_file(self::ALGORITHM, $file) : false;
        if ($hash === false) {
            throw new \moodle_exception('error:cannotreadfile', 'tool_moodleclone');
        }
        return $hash;
    }
}
