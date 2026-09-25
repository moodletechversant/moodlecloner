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
 * Tests for the streaming checksum writer.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\checksum_writer
 */
class checksum_writer_test extends \advanced_testcase {
    public function test_output_is_parseable_and_in_insertion_order(): void {
        $path = make_request_directory() . '/checksums.spool';
        $writer = new checksum_writer($path);
        $writer->add('moodle/b.php', hash('sha256', 'b'));
        $writer->add('database.sql.gz', strtoupper(hash('sha256', 'db')));
        $this->assertSame(2, $writer->count());
        $this->assertSame($path, $writer->close());

        $this->assertSame(
            hash('sha256', 'b') . "  moodle/b.php\n" . hash('sha256', 'db') . "  database.sql.gz\n",
            file_get_contents($path)
        );
        $parsed = checksums::from_string(file_get_contents($path));
        $this->assertSame(hash('sha256', 'db'), $parsed->get('database.sql.gz'));
    }

    /**
     * Invalid lines.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        $hash = hash('sha256', 'x');
        return [
            'traversal' => ['../x', $hash],
            'absolute' => ['/x', $hash],
            'itself' => ['checksums.sha256', $hash],
            'newline in name' => ["a\nb", $hash],
            'short hash' => ['a', 'abc'],
        ];
    }

    /**
     * Invalid lines are rejected before anything is written.
     *
     * @dataProvider invalid_provider
     * @param string $path
     * @param string $hash
     */
    public function test_invalid_entries_rejected(string $path, string $hash): void {
        $spool = make_request_directory() . '/checksums.spool';
        $writer = new checksum_writer($spool);
        try {
            $writer->add($path, $hash);
            $this->fail('Invalid checksum line accepted');
        } catch (invalid_package_exception $e) {
            $writer->close();
            $this->assertSame('', file_get_contents($spool));
        }
    }
}
