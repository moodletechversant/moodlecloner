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
 * Tests for the checksum list.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\checksums
 */
class checksums_test extends \advanced_testcase {

    public function test_serialisation_is_sorted_sha256sum_format(): void {
        $checksums = new checksums();
        $checksums->add('moodle/b.php', hash('sha256', 'b'));
        $checksums->add('manifest.json', strtoupper(hash('sha256', 'm')));
        $checksums->add('moodle/a.php', hash('sha256', 'a'));

        $expected = hash('sha256', 'm') . "  manifest.json\n" .
            hash('sha256', 'a') . "  moodle/a.php\n" .
            hash('sha256', 'b') . "  moodle/b.php\n";
        $this->assertSame($expected, $checksums->to_string());
        $this->assertCount(3, $checksums);
    }

    public function test_round_trip(): void {
        $checksums = new checksums();
        $checksums->add('moodledata/filedir/x y.txt', hash('sha256', 'x'));
        $checksums->add('database.sql.gz', hash('sha256', 'db'));

        $parsed = checksums::from_string($checksums->to_string());
        $this->assertSame($checksums->to_string(), $parsed->to_string());
        $this->assertSame(hash('sha256', 'x'), $parsed->get('moodledata/filedir/x y.txt'));
    }

    public function test_parse_accepts_binary_marker_and_crlf(): void {
        $hash = hash('sha256', 'a');
        $parsed = checksums::from_string("{$hash} *moodle/a.php\r\n");
        $this->assertSame($hash, $parsed->get('moodle/a.php'));
    }

    /**
     * Malformed checksum files.
     *
     * @return array
     */
    public function malformed_provider(): array {
        $hash = hash('sha256', 'a');
        return [
            'short hash' => ["abc  moodle/a.php\n"],
            'no separator' => [$hash . "moodle/a.php\n"],
            'traversal' => ["{$hash}  ../../etc/passwd\n"],
            'absolute' => ["{$hash}  /etc/passwd\n"],
            'self reference' => ["{$hash}  checksums.sha256\n"],
            'duplicate' => ["{$hash}  a\n{$hash}  a\n"],
        ];
    }

    /**
     * Malformed content is rejected.
     *
     * @dataProvider malformed_provider
     * @param string $content
     */
    public function test_malformed_rejected(string $content): void {
        $this->expectException(invalid_package_exception::class);
        checksums::from_string($content);
    }

    public function test_add_rejects_invalid_hash(): void {
        $this->expectException(invalid_package_exception::class);
        (new checksums())->add('a.txt', str_repeat('g', 64));
    }

    public function test_add_file_and_verify(): void {
        $dir = make_request_directory();
        $file = $dir . '/data.bin';
        file_put_contents($file, 'original content');

        $checksums = new checksums();
        $hash = $checksums->add_file('moodledata/data.bin', $file);
        $this->assertSame(hash('sha256', 'original content'), $hash);
        $this->assertTrue($checksums->verify_file('moodledata/data.bin', $file));

        file_put_contents($file, 'tampered content');
        $this->assertFalse($checksums->verify_file('moodledata/data.bin', $file));
        $this->assertFalse($checksums->verify_file('moodledata/unknown.bin', $file));
        $this->assertFalse($checksums->verify_file('moodledata/data.bin', $dir . '/missing.bin'));
    }

    public function test_hash_file_missing_file(): void {
        $this->expectException(\moodle_exception::class);
        checksums::hash_file(make_request_directory() . '/missing');
    }
}
