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

namespace tool_moodleclone\local\log;

/**
 * Tests for log redaction.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\log\redactor
 * @covers     \tool_moodleclone\local\log\base_logger
 * @covers     \tool_moodleclone\local\log\memory_logger
 */
class redactor_test extends \basic_testcase {

    public function test_exact_secrets_are_masked(): void {
        $redactor = new redactor(['S3cr3t!', '']);
        $this->assertSame('Connecting with [redacted] failed', $redactor->redact('Connecting with S3cr3t! failed'));
        $this->assertSame('No secret here', $redactor->redact('No secret here'));
    }

    public function test_longer_secret_masked_before_contained_one(): void {
        $redactor = new redactor(['abc', 'abcdef']);
        $this->assertSame('x [redacted] y', $redactor->redact('x abcdef y'));
    }

    /**
     * Key/value pairs that must be masked even when the secret is unknown.
     *
     * @return array
     */
    public function pair_provider(): array {
        return [
            ['mysqldump --password=hunter2 moodle', 'mysqldump --password=[redacted] moodle'],
            ['dbpass: hunter2', 'dbpass: [redacted]'],
            ['token="abc123"', 'token=[redacted]'],
            ['url?api_key=abc&x=1', 'url?api_key=[redacted]&x=1'],
            ['secret = xyz, next', 'secret = [redacted], next'],
            ['Collecting Moodle files', 'Collecting Moodle files'],
            ['3 passes completed', '3 passes completed'],
            ["Access denied for user 'moodle'@'localhost' (using password: YES)",
                "Access denied for user '[redacted]'@'[redacted]' (using password: [redacted])"],
            ['mysql://bob:hunter2@db.example/moodle', 'mysql://[redacted]@db.example/moodle'],
        ];
    }

    /**
     * Pattern based masking.
     *
     * @dataProvider pair_provider
     * @param string $input
     * @param string $expected
     */
    public function test_pairs_are_masked(string $input, string $expected): void {
        $this->assertSame($expected, (new redactor())->redact($input));
    }

    public function test_from_config_reads_secret_properties(): void {
        $cfg = (object) ['dbpass' => 'dbpw-value', 'passwordsaltmain' => 'salt-value', 'dbuser' => 'moodleuser'];
        $redactor = redactor::from_config($cfg);
        $this->assertSame('[redacted] [redacted] moodleuser', $redactor->redact('dbpw-value salt-value moodleuser'));
    }

    public function test_logger_always_redacts(): void {
        $logger = new memory_logger(new redactor(['dbpw-value']));
        $logger->error('Access denied for password dbpw-value');
        $logger->info('Dumping database');

        $this->assertSame([
            ['level' => logger::ERROR, 'message' => 'Access denied for password [redacted]'],
            ['level' => logger::INFO, 'message' => 'Dumping database'],
        ], $logger->get_entries());
    }

    public function test_logger_rejects_unknown_level(): void {
        $this->expectException(\coding_exception::class);
        (new memory_logger(new redactor()))->log('debug', 'x');
    }
}
