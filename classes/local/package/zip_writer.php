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
use tool_moodleclone\local\filesystem\tree_entry;

/**
 * Streaming ZIP writer with ZIP64 support and constant memory use.
 *
 * Why not ZipArchive or the bundled ZipStream library: ZipArchive (libzip)
 * collects entries in memory and does all the work inside close(), with no
 * progress reporting; ZipStream keeps one central directory record per entry
 * in PHP memory. A Moodle site can have millions of files, so this writer:
 *  - reads each source file once, in 1 MB chunks, computing CRC-32 (for the
 *    ZIP format) and SHA-256 (for checksums.sha256) from the same bytes it
 *    writes, so the checksum always matches the archived data;
 *  - writes the local header first and patches CRC and sizes in place
 *    afterwards (the output is a regular, seekable file), so no data
 *    descriptors are needed and any ZIP reader can extract it;
 *  - spools central directory records to a temporary file and appends them
 *    in finish(), so memory does not grow with the number of entries.
 *
 * Entry names must be safe relative paths (path_validator) in UTF-8; the
 * UTF-8 flag (bit 11) is always set. Unix permissions and file types
 * (regular file, directory, symlink) are stored in the external attributes,
 * and the exact modification time in an extended timestamp field (0x5455).
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zip_writer {
    /** @var int Entries whose size might reach this use ZIP64 fields (margin for deflate expansion). */
    public const ZIP64_THRESHOLD = 0xF0000000;

    /** @var int Read size. */
    public const CHUNK = 1048576;

    /** @var int Largest value of a 32-bit field. */
    private const MAX32 = 0xFFFFFFFF;

    /** @var int Largest value of a 16-bit field. */
    private const MAX16 = 0xFFFF;

    /** @var int "Version made by": Unix, spec 4.5. */
    private const MADE_BY = (3 << 8) | 45;

    /** @var int General purpose flag: names are UTF-8. */
    private const FLAG_UTF8 = 0x0800;

    /** @var string */
    private $path;

    /** @var resource Output file. */
    private $fh;

    /** @var string */
    private $spoolpath;

    /** @var resource Central directory spool. */
    private $spool;

    /** @var int Bytes written to the archive so far (= current offset). */
    private $offset = 0;

    /** @var int */
    private $entries = 0;

    /** @var bool Use ZIP64 structures everywhere (tests exercise ZIP64 on small archives with this). */
    private $forcezip64;

    /** @var array|null Entry currently being written. */
    private $current = null;

    /** @var bool */
    private $finished = false;

    /**
     * Create the archive. Refuses to overwrite an existing file.
     *
     * @param string $path Archive to create.
     * @param string $spoolpath Temporary file for central directory records.
     * @param bool $forcezip64
     */
    public function __construct(string $path, string $spoolpath, bool $forcezip64 = false) {
        $this->path = $path;
        $this->spoolpath = $spoolpath;
        $this->forcezip64 = $forcezip64;
        $this->fh = @fopen($path, 'xb');
        if (!$this->fh) {
            throw new backup_exception('cannotcreate', basename($path));
        }
        @chmod($path, 0600);
        $this->spool = @fopen($spoolpath, 'x+b');
        if (!$this->spool) {
            fclose($this->fh);
            @unlink($path);
            throw new backup_exception('cannotcreate', basename($spoolpath));
        }
        @chmod($spoolpath, 0600);
    }

    /**
     * Archive path.
     *
     * @return string
     */
    public function get_path(): string {
        return $this->path;
    }

    /**
     * Number of entries written.
     *
     * @return int
     */
    public function get_entry_count(): int {
        return $this->entries;
    }

    /**
     * Bytes written so far.
     *
     * @return int
     */
    public function get_size(): int {
        return $this->offset;
    }

    /**
     * Whether finish() completed.
     *
     * @return bool
     */
    public function is_finished(): bool {
        return $this->finished;
    }

    /**
     * Add a directory entry.
     *
     * @param string $name Entry name, with or without trailing slash.
     * @param int $mode Permission bits.
     * @param int $mtime
     * @return void
     */
    public function add_directory(string $name, int $mode, int $mtime): void {
        $name = rtrim($name, '/');
        $this->begin($name . '/', false, 0040000 | ($mode & 07777), $mtime, 0, $name);
        $this->end();
    }

    /**
     * Add a symbolic link entry (the data is the link target).
     *
     * @param string $name
     * @param string $target Relative target, already validated by the caller.
     * @param int $mtime
     * @return void
     */
    public function add_symlink(string $name, string $target, int $mtime): void {
        $this->begin($name, false, 0120777, $mtime, strlen($target));
        $this->data($target);
        $this->end();
    }

    /**
     * Add a string as a file entry.
     *
     * @param string $name
     * @param string $data
     * @param bool $compress Deflate when true, store otherwise.
     * @param int $mode Permission bits.
     * @param int|null $mtime Defaults to now.
     * @return array ['sha256' => string, 'size' => int, 'crc' => int]
     */
    public function add_string(string $name, string $data, bool $compress, int $mode = 0644, ?int $mtime = null): array {
        $this->begin($name, $compress, 0100000 | ($mode & 07777), $mtime ?? time(), strlen($data));
        $this->data($data);
        return $this->end();
    }

    /**
     * Add a file from disk, streaming it.
     *
     * When $entry is given the opened file must be the same inode that was
     * lstat()ed while walking, which stops a file being swapped for a symlink
     * between the walk and the read.
     *
     * @param string $name Entry name.
     * @param string $path File to read.
     * @param bool $compress
     * @param callable|null $onchunk fn(int $bytes) after each chunk.
     * @param tree_entry|null $entry
     * @return array ['sha256' => string, 'size' => int, 'crc' => int]
     * @throws vanished_file_exception When the file no longer exists.
     * @throws backup_exception When it cannot be read or was replaced.
     */
    public function add_file(
        string $name,
        string $path,
        bool $compress,
        ?callable $onchunk = null,
        ?tree_entry $entry = null
    ): array {
        $this->require_idle();
        $in = @fopen($path, 'rb');
        if (!$in) {
            clearstatcache(true, $path);
            if (!file_exists($path) && !is_link($path)) {
                throw new vanished_file_exception($name);
            }
            throw new backup_exception('unreadable', $name);
        }
        try {
            $stat = fstat($in);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new backup_exception('notregular', $name);
            }
            if ($entry !== null && ((int) $stat['ino'] !== $entry->ino || (int) $stat['dev'] !== $entry->dev)) {
                throw new backup_exception('replaced', $name);
            }
            $this->begin($name, $compress, 0100000 | ($stat['mode'] & 07777), (int) $stat['mtime'], (int) $stat['size']);
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw new backup_exception('unreadable', $name);
                }
                if ($chunk === '') {
                    break;
                }
                $this->data($chunk);
                if ($onchunk !== null) {
                    $onchunk(strlen($chunk));
                }
            }
            return $this->end();
        } finally {
            fclose($in);
        }
    }

    /**
     * Open an entry of unknown size for streaming writes (always ZIP64-capable).
     *
     * @param string $name
     * @param bool $compress
     * @param int $mode
     * @param int|null $mtime
     * @return zip_entry_stream
     */
    public function open_stream(string $name, bool $compress, int $mode = 0600, ?int $mtime = null): zip_entry_stream {
        $this->begin($name, $compress, 0100000 | ($mode & 07777), $mtime ?? time(), PHP_INT_MAX);
        return new zip_entry_stream($this);
    }

    /**
     * Write data to the open stream entry.
     *
     * Only zip_entry_stream should call this; use zip_entry_stream::write() instead.
     *
     * @param string $data
     * @return void
     */
    public function stream_write(string $data): void {
        if ($this->current === null) {
            throw new \coding_exception('No zip entry is open');
        }
        $this->data($data);
    }

    /**
     * Close the open stream entry.
     *
     * Only zip_entry_stream should call this; use zip_entry_stream::close() instead.
     *
     * @return array
     */
    public function stream_close(): array {
        if ($this->current === null) {
            throw new \coding_exception('No zip entry is open');
        }
        return $this->end();
    }

    /**
     * Write the central directory and end records, then close the archive.
     *
     * @return void
     */
    public function finish(): void {
        $this->require_idle();
        $cdoffset = $this->offset;
        $cdsize = ftell($this->spool);
        rewind($this->spool);
        $copied = stream_copy_to_stream($this->spool, $this->fh);
        if ($copied !== $cdsize) {
            throw new backup_exception('writefailed', basename($this->path));
        }
        $this->offset += $copied;

        $zip64 = $this->forcezip64 || $this->entries >= self::MAX16 || $cdoffset >= self::MAX32 || $cdsize >= self::MAX32;
        if ($zip64) {
            $eocd64offset = $this->offset;
            $this->write(pack(
                'VPvvVVPPPP',
                0x06064b50,
                44,
                self::MADE_BY,
                45,
                0,
                0,
                $this->entries,
                $this->entries,
                $cdsize,
                $cdoffset
            ));
            $this->write(pack('VVPV', 0x07064b50, 0, $eocd64offset, 1));
        }
        $count16 = ($zip64 && ($this->forcezip64 || $this->entries >= self::MAX16)) ? self::MAX16 : $this->entries;
        $size32 = ($zip64 && ($this->forcezip64 || $cdsize >= self::MAX32)) ? self::MAX32 : $cdsize;
        $offset32 = ($zip64 && ($this->forcezip64 || $cdoffset >= self::MAX32)) ? self::MAX32 : $cdoffset;
        $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $count16, $count16, $size32, $offset32, 0));

        if (!fflush($this->fh)) {
            throw new backup_exception('writefailed', basename($this->path));
        }
        if (function_exists('fsync')) {
            // PHP 8.1+: make sure the data is on disk before the package is verified and published.
            fsync($this->fh);
        }
        fclose($this->fh);
        fclose($this->spool);
        @unlink($this->spoolpath);
        $this->fh = null;
        $this->spool = null;
        $this->finished = true;
    }

    /**
     * Close and delete everything written so far.
     *
     * @return void
     */
    public function abort(): void {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
        if (is_resource($this->spool)) {
            fclose($this->spool);
        }
        $this->fh = null;
        $this->spool = null;
        $this->current = null;
        @unlink($this->spoolpath);
        if (!$this->finished) {
            @unlink($this->path);
        }
    }

    /**
     * Start an entry: validate the name and write the local header with placeholders.
     *
     * @param string $name
     * @param bool $compress
     * @param int $unixmode File type and permission bits.
     * @param int $mtime
     * @param int $expectedsize Used to decide on ZIP64; PHP_INT_MAX when unknown.
     * @param string|null $checkname Name to validate (directories are validated without the trailing slash).
     * @return void
     */
    private function begin(
        string $name,
        bool $compress,
        int $unixmode,
        int $mtime,
        int $expectedsize,
        ?string $checkname = null
    ): void {
        $this->require_idle();
        if ($this->finished || !is_resource($this->fh)) {
            throw new \coding_exception('The archive is closed');
        }
        $checkname = $checkname ?? $name;
        if (!path_validator::is_safe_relative_path($checkname) || !preg_match('//u', $name) || strlen($name) > self::MAX16) {
            throw new backup_exception('unsafename', $name);
        }

        $zip64 = $this->forcezip64 || $expectedsize >= self::ZIP64_THRESHOLD;
        $method = $compress ? 8 : 0;
        [$dostime, $dosdate] = self::dos_datetime($mtime);
        $extra = '';
        if ($zip64) {
            $extra .= pack('vvPP', 0x0001, 16, 0, 0);
        }
        $extra .= pack('vvCV', 0x5455, 5, 1, max(0, min($mtime, self::MAX32)));

        $this->current = [
            'name' => $name,
            'offset' => $this->offset,
            'method' => $method,
            'zip64' => $zip64,
            'mode' => $unixmode,
            'mtime' => $mtime,
            'dostime' => $dostime,
            'dosdate' => $dosdate,
            'crc' => hash_init('crc32b'),
            'sha' => hash_init('sha256'),
            'deflate' => $compress ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null,
            'usize' => 0,
            'csize' => 0,
        ];

        $this->write(pack(
            'VvvvvvVVVvv',
            0x04034b50,
            $zip64 ? 45 : 20,
            self::FLAG_UTF8,
            $method,
            $dostime,
            $dosdate,
            0,
            $zip64 ? self::MAX32 : 0,
            $zip64 ? self::MAX32 : 0,
            strlen($name),
            strlen($extra)
        ) . $name . $extra);
    }

    /**
     * Feed entry data.
     *
     * @param string $data
     * @return void
     */
    private function data(string $data): void {
        if ($data === '') {
            return;
        }
        hash_update($this->current['crc'], $data);
        hash_update($this->current['sha'], $data);
        $this->current['usize'] += strlen($data);
        if ($this->current['deflate'] !== null) {
            $data = deflate_add($this->current['deflate'], $data, ZLIB_NO_FLUSH);
        }
        if ($data !== '') {
            $this->write($data);
            $this->current['csize'] += strlen($data);
        }
    }

    /**
     * Finish the entry: flush the compressor, patch the local header, spool the central record.
     *
     * @return array ['sha256' => string, 'size' => int, 'crc' => int]
     */
    private function end(): array {
        $e = $this->current;
        if ($e['deflate'] !== null) {
            $tail = deflate_add($e['deflate'], '', ZLIB_FINISH);
            if ($tail !== '') {
                $this->write($tail);
                $e['csize'] += strlen($tail);
            }
        }
        $crc = hexdec(hash_final($e['crc']));
        $sha = hash_final($e['sha']);

        if (!$e['zip64'] && ($e['usize'] >= self::MAX32 || $e['csize'] >= self::MAX32)) {
            // Only possible if a file grew past 4 GB while being read.
            throw new backup_exception('changedduringread', $e['name']);
        }

        // Patch CRC and sizes into the local header.
        $namelength = strlen($e['name']);
        $this->seek($e['offset'] + 14);
        if ($e['zip64']) {
            $this->write_at(pack('V', $crc));
            $this->seek($e['offset'] + 30 + $namelength + 4);
            $this->write_at(pack('PP', $e['usize'], $e['csize']));
        } else {
            $this->write_at(pack('VVV', $crc, $e['csize'], $e['usize']));
        }
        $this->seek($this->offset);

        // Central directory record.
        $zip64extra = '';
        $usize32 = $e['usize'];
        $csize32 = $e['csize'];
        $offset32 = $e['offset'];
        if ($e['zip64']) {
            $zip64extra .= pack('PP', $e['usize'], $e['csize']);
            $usize32 = self::MAX32;
            $csize32 = self::MAX32;
        }
        if ($this->forcezip64 || $e['offset'] >= self::MAX32) {
            $zip64extra .= pack('P', $e['offset']);
            $offset32 = self::MAX32;
        }
        $extra = '';
        if ($zip64extra !== '') {
            $extra .= pack('vv', 0x0001, strlen($zip64extra)) . $zip64extra;
        }
        $extra .= pack('vvCV', 0x5455, 5, 1, max(0, min($e['mtime'], self::MAX32)));
        $needed = ($zip64extra !== '') ? 45 : 20;
        $isdir = ($e['mode'] & 0170000) === 0040000;
        $record = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            self::MADE_BY,
            $needed,
            self::FLAG_UTF8,
            $e['method'],
            $e['dostime'],
            $e['dosdate'],
            $crc,
            $csize32,
            $usize32,
            $namelength,
            strlen($extra),
            0,
            0,
            0,
            ($e['mode'] << 16) | ($isdir ? 0x10 : 0),
            $offset32
        ) . $e['name'] . $extra;
        if (fwrite($this->spool, $record) !== strlen($record)) {
            throw new backup_exception('writefailed', basename($this->spoolpath));
        }

        $this->entries++;
        $this->current = null;
        return ['sha256' => $sha, 'size' => $e['usize'], 'crc' => $crc];
    }

    /**
     * Append to the archive.
     *
     * @param string $data
     * @return void
     */
    private function write(string $data): void {
        $this->write_at($data);
        $this->offset += strlen($data);
    }

    /**
     * Write at the current file position without moving the logical end.
     *
     * @param string $data
     * @return void
     */
    private function write_at(string $data): void {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($this->fh, $written === 0 ? $data : substr($data, $written));
            if ($result === false || $result === 0) {
                // Typically a full disk.
                throw new backup_exception('writefailed', basename($this->path));
            }
            $written += $result;
        }
    }

    /**
     * Seek the output.
     *
     * @param int $position
     * @return void
     */
    private function seek(int $position): void {
        if (fseek($this->fh, $position) !== 0) {
            throw new backup_exception('writefailed', basename($this->path));
        }
    }

    /**
     * Refuse to start another entry while a stream is open.
     *
     * @return void
     */
    private function require_idle(): void {
        if ($this->current !== null) {
            throw new \coding_exception('A zip entry is still open: ' . $this->current['name']);
        }
    }

    /**
     * MS-DOS time and date for a Unix time, in the server time zone (the extended
     * timestamp field carries the exact UTC time).
     *
     * @param int $time
     * @return int[] [time, date]
     */
    public static function dos_datetime(int $time): array {
        $d = getdate($time);
        if ($d['year'] < 1980) {
            return [0, (1 << 5) | 1];
        }
        if ($d['year'] > 2107) {
            return [(23 << 11) | (59 << 5) | 29, (127 << 9) | (12 << 5) | 31];
        }
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
