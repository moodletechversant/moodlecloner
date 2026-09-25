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

namespace tool_moodleclone\local\database;

/**
 * Gzip-compresses everything written to it and passes the compressed bytes on.
 *
 * Output is buffered in 1 MB blocks before compression, so memory stays
 * constant however large the dump is.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gzip_sink implements sink {
    /** @var int Buffer size before compressing. */
    private const BUFFER = 1048576;

    /** @var callable fn(string $compressed): void */
    private $target;

    /** @var resource|\DeflateContext|null */
    private $context;

    /** @var string */
    private $buffer = '';

    /** @var int Uncompressed bytes written. */
    private $bytes = 0;

    /**
     * Constructor.
     *
     * @param callable $target Receives compressed data.
     * @param int $level zlib compression level.
     */
    public function __construct(callable $target, int $level = 6) {
        $this->target = $target;
        $this->context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $level]);
    }

    /**
     * Append data.
     *
     * @param string $data
     * @return void
     */
    public function write(string $data): void {
        if ($this->context === null) {
            throw new \coding_exception('Sink is closed');
        }
        $this->buffer .= $data;
        $this->bytes += strlen($data);
        if (strlen($this->buffer) >= self::BUFFER) {
            $this->emit(deflate_add($this->context, $this->buffer, ZLIB_NO_FLUSH));
            $this->buffer = '';
        }
    }

    /**
     * Finish the gzip stream.
     *
     * @return void
     */
    public function close(): void {
        if ($this->context === null) {
            return;
        }
        $this->emit(deflate_add($this->context, $this->buffer, ZLIB_FINISH));
        $this->buffer = '';
        $this->context = null;
    }

    /**
     * Uncompressed bytes written so far.
     *
     * @return int
     */
    public function get_bytes(): int {
        return $this->bytes;
    }

    /**
     * Pass compressed output on.
     *
     * @param string $compressed
     * @return void
     */
    private function emit(string $compressed): void {
        if ($compressed !== '') {
            ($this->target)($compressed);
        }
    }
}
