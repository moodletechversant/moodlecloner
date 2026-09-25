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

/**
 * An open zip entry of unknown size (used for the streamed database dump).
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zip_entry_stream {
    /** @var zip_writer|null Null once closed. */
    private $writer;

    /**
     * Constructor.
     *
     * @param zip_writer $writer
     */
    public function __construct(zip_writer $writer) {
        $this->writer = $writer;
    }

    /**
     * Append data.
     *
     * @param string $data
     * @return void
     */
    public function write(string $data): void {
        if ($this->writer === null) {
            throw new \coding_exception('Zip entry stream is closed');
        }
        $this->writer->stream_write($data);
    }

    /**
     * Finish the entry.
     *
     * @return array ['sha256' => string, 'size' => int, 'crc' => int]
     */
    public function close(): array {
        if ($this->writer === null) {
            throw new \coding_exception('Zip entry stream is closed');
        }
        $result = $this->writer->stream_close();
        $this->writer = null;
        return $result;
    }
}
