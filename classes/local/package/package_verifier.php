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

use tool_moodleclone\local\database\mysql_dumper;

/**
 * Verifies a finished package before it is published.
 *
 * Uses PHP's ZipArchive (libzip), an implementation independent of
 * {@see zip_writer}, so a writer bug cannot hide itself. Checks:
 *  - the archive opens with consistency checks (central directory matches local headers);
 *  - the entry count is what the writer reported;
 *  - every entry name is allowed by {@see layout};
 *  - manifest.json is valid and matches the entries actually present;
 *  - checksums.sha256 lists every regular file exactly once, in archive
 *    order, and every file's content (read back and decompressed) matches
 *    its SHA-256;
 *  - database.sql.gz decompresses completely and ends with the dump's
 *    completion marker, so a truncated dump cannot pass.
 *
 * Every byte of every file is read back once. Memory use is constant.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_verifier {
    /** @var callable|null fn(float $fraction) */
    private $progress;

    /**
     * Constructor.
     *
     * @param callable|null $progress Receives 0..1 as verification advances.
     */
    public function __construct(?callable $progress = null) {
        $this->progress = $progress;
    }

    /**
     * Verify a package.
     *
     * @param string $path Archive path.
     * @param int|null $expectedentries Entry count reported by the writer, if known.
     * @return manifest The verified manifest.
     * @throws invalid_package_exception Listing the problems found.
     */
    public function verify(string $path, ?int $expectedentries = null): manifest {
        $zip = new \ZipArchive();
        $flags = \ZipArchive::CHECKCONS;
        if (defined('ZipArchive::RDONLY')) {
            $flags |= \ZipArchive::RDONLY;
        }
        $result = $zip->open($path, $flags);
        if ($result !== true) {
            throw new invalid_package_exception(["archive cannot be opened (ZipArchive error {$result})"]);
        }
        try {
            return $this->verify_open($zip, $expectedentries);
        } finally {
            $zip->close();
        }
    }

    /**
     * Verify an opened archive.
     *
     * @param \ZipArchive $zip
     * @param int|null $expectedentries
     * @return manifest
     */
    private function verify_open(\ZipArchive $zip, ?int $expectedentries): manifest {
        $count = $zip->numFiles;
        if ($expectedentries !== null && $count !== $expectedentries) {
            throw new invalid_package_exception(["archive has {$count} entries, expected {$expectedentries}"]);
        }

        // Pass 1: names, types and per-component counts, from the central directory.
        $files = [];
        $counts = [];
        foreach (manifest::CONTENTS as $component) {
            $counts[$component] = ['files' => 0, 'directories' => 0, 'symlinks' => 0];
        }
        $totalbytes = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            $type = ($attr >> 16) & 0170000;
            $isdir = substr($name, -1) === '/';
            if ($isdir ? !layout::is_allowed_directory_entry($name) : !layout::is_allowed_entry($name)) {
                throw new invalid_package_exception(['entry name not allowed: ' . rawurlencode($name)]);
            }
            $component = layout::component_of($name);
            if ($isdir) {
                if ($component === null || $component === manifest::CONTENT_DATABASE) {
                    throw new invalid_package_exception(['unexpected directory entry']);
                }
                $counts[$component]['directories']++;
                continue;
            }
            if ($type === 0120000) {
                if ($component === null || $component === manifest::CONTENT_DATABASE) {
                    throw new invalid_package_exception(['unexpected symlink entry']);
                }
                $counts[$component]['symlinks']++;
                continue;
            }
            if ($name === layout::CHECKSUMS) {
                continue;
            }
            if ($component !== null && $component !== manifest::CONTENT_DATABASE) {
                $counts[$component]['files']++;
            }
            $files[] = $i;
            $totalbytes += $stat['size'];
        }

        // Manifest must match what is actually in the archive.
        $json = $zip->getFromName(layout::MANIFEST);
        if ($json === false) {
            throw new invalid_package_exception(['manifest.json is missing']);
        }
        $manifest = manifest::from_json($json);
        $this->check_manifest_matches($manifest, $zip, $counts);

        // Pass 2: checksums.sha256 lists every file in archive order; verify each file's content.
        $list = $zip->getStream(layout::CHECKSUMS);
        if ($list === false) {
            throw new invalid_package_exception(['checksums.sha256 is missing']);
        }
        try {
            $donebytes = 0;
            $lineno = 0;
            foreach ($files as $index) {
                $lineno++;
                $line = fgets($list);
                if ($line === false || !preg_match('/^([0-9a-f]{64})  (.+)\n$/', $line, $m)) {
                    throw new invalid_package_exception(["checksums.sha256 line {$lineno} is missing or malformed"]);
                }
                $name = $zip->getNameIndex($index);
                if ($m[2] !== $name) {
                    throw new invalid_package_exception(["checksums.sha256 line {$lineno} does not match the archive"]);
                }
                $actual = $this->hash_entry($zip, $name, $donebytes, $totalbytes);
                if (!hash_equals($m[1], $actual)) {
                    throw new invalid_package_exception(['checksum mismatch: ' . rawurlencode($name)]);
                }
            }
            if (fgets($list) !== false) {
                throw new invalid_package_exception(['checksums.sha256 lists entries that are not in the archive']);
            }
        } finally {
            fclose($list);
        }
        $this->report(1.0);
        return $manifest;
    }

    /**
     * Compare manifest claims with the archive contents.
     *
     * @param manifest $manifest
     * @param \ZipArchive $zip
     * @param array $counts Component => counted entries.
     * @return void
     */
    private function check_manifest_matches(manifest $manifest, \ZipArchive $zip, array $counts): void {
        $errors = [];
        $statistics = $manifest->get('statistics');
        foreach ([manifest::CONTENT_MOODLE, manifest::CONTENT_MOODLEDATA] as $component) {
            $present = array_sum($counts[$component]) > 0;
            if ($manifest->includes($component) !== $present) {
                $errors[] = "manifest package_contents.{$component} does not match the archive";
                continue;
            }
            if ($present) {
                foreach ($counts[$component] as $key => $value) {
                    if ($statistics[$component][$key] !== $value) {
                        $errors[] = "manifest statistics.{$component}.{$key} does not match the archive";
                    }
                }
            }
        }
        $hasdatabase = $zip->locateName(layout::DATABASE) !== false;
        if ($manifest->includes(manifest::CONTENT_DATABASE) !== $hasdatabase) {
            $errors[] = 'manifest package_contents.database does not match the archive';
        }
        if ($errors) {
            throw new invalid_package_exception($errors);
        }
    }

    /**
     * SHA-256 of an entry's uncompressed content; for the database dump also
     * check that the gzip stream is complete and ends with the completion marker.
     *
     * @param \ZipArchive $zip
     * @param string $name
     * @param int $donebytes Updated as data is read.
     * @param int $totalbytes
     * @return string
     */
    private function hash_entry(\ZipArchive $zip, string $name, int &$donebytes, int $totalbytes): string {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new invalid_package_exception(['entry cannot be read: ' . rawurlencode($name)]);
        }
        $isdump = $name === layout::DATABASE;
        $inflate = $isdump ? inflate_init(ZLIB_ENCODING_GZIP) : null;
        $tail = '';
        $hash = hash_init('sha256');
        $lastreport = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, zip_writer::CHUNK);
                if ($chunk === false) {
                    throw new invalid_package_exception(['entry cannot be read: ' . rawurlencode($name)]);
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($hash, $chunk);
                if ($inflate !== null) {
                    $out = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
                    if ($out === false) {
                        throw new invalid_package_exception(['database.sql.gz is not a valid gzip stream']);
                    }
                    $tail = substr($tail . $out, -4096);
                }
                $donebytes += strlen($chunk);
                if ($donebytes - $lastreport >= 16 * zip_writer::CHUNK) {
                    $lastreport = $donebytes;
                    $this->report($totalbytes > 0 ? $donebytes / $totalbytes : 0.0);
                }
            }
        } finally {
            fclose($stream);
        }
        if ($inflate !== null) {
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                throw new invalid_package_exception(['database.sql.gz is truncated']);
            }
            if (strpos($tail, mysql_dumper::COMPLETION_MARKER) === false) {
                throw new invalid_package_exception(['database.sql.gz does not end with the completion marker']);
            }
        }
        return hash_final($hash);
    }

    /**
     * Report progress.
     *
     * @param float $fraction
     * @return void
     */
    private function report(float $fraction): void {
        if ($this->progress !== null) {
            ($this->progress)(min(1.0, $fraction));
        }
    }

    /**
     * Stream the SHA-256 of a whole file.
     *
     * @param string $path
     * @param callable|null $progress fn(float $fraction)
     * @return string
     */
    public static function hash_file(string $path, ?callable $progress = null): string {
        $size = (int) filesize($path);
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new \tool_moodleclone\local\backup\backup_exception('unreadable', basename($path));
        }
        $hash = hash_init('sha256');
        $done = 0;
        try {
            while (!feof($fh)) {
                $chunk = fread($fh, zip_writer::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($hash, $chunk);
                $done += strlen($chunk);
                if ($progress !== null && $done % (64 * zip_writer::CHUNK) === 0) {
                    $progress($size > 0 ? $done / $size : 1.0);
                }
            }
        } finally {
            fclose($fh);
        }
        return hash_final($hash);
    }
}
