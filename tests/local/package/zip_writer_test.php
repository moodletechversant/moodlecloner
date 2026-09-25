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

/**
 * Tests for the streaming zip writer, read back with ZipArchive (libzip).
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\zip_writer
 * @covers     \tool_moodleclone\local\package\zip_entry_stream
 */
class zip_writer_test extends \advanced_testcase {

    /**
     * Write a mixed archive.
     *
     * @param string $dir
     * @param bool $forcezip64
     * @return array [archive path, source file, results]
     */
    private function write_sample(string $dir, bool $forcezip64): array {
        $source = $dir . '/source.bin';
        file_put_contents($source, random_bytes(200000) . str_repeat('compressible ', 50000));
        chmod($source, 0751);
        $writer = new zip_writer($dir . '/out.zip', $dir . '/cd.spool', $forcezip64);
        $writer->add_directory('moodle/', 0755, 1700000000);
        $results = [];
        $results['file'] = $writer->add_file('moodle/source.bin', $source, true);
        $results['stored'] = $writer->add_file('moodle/stored.bin', $source, false);
        $results['string'] = $writer->add_string('moodle/ünïcode ✓.txt', "hello\n", true);
        $results['empty'] = $writer->add_string('moodle/empty.txt', '', true);
        $writer->add_symlink('moodle/link', 'source.bin', 1700000000);
        $stream = $writer->open_stream('database.sql.gz', false);
        $stream->write('part one ');
        $stream->write('part two');
        $results['stream'] = $stream->close();
        $this->assertSame(7, $writer->get_entry_count());
        $writer->finish();
        $this->assertTrue($writer->is_finished());
        $this->assertFileDoesNotExist($dir . '/cd.spool', 'The central directory spool is removed');
        return [$dir . '/out.zip', $source, $results];
    }

    /**
     * Normal and forced-ZIP64 archives.
     *
     * @return array
     */
    public function zip64_provider(): array {
        return ['standard' => [false], 'forced zip64' => [true]];
    }

    /**
     * Archives written in both modes are read back identically by libzip.
     *
     * @dataProvider zip64_provider
     * @param bool $forcezip64
     */
    public function test_round_trip_with_ziparchive(bool $forcezip64): void {
        [$archive, $source, $results] = $this->write_sample(make_request_directory(), $forcezip64);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archive, \ZipArchive::CHECKCONS));
        $this->assertSame(7, $zip->numFiles);
        $this->assertSame(file_get_contents($source), $zip->getFromName('moodle/source.bin'));
        $this->assertSame(file_get_contents($source), $zip->getFromName('moodle/stored.bin'));
        $this->assertSame("hello\n", $zip->getFromName('moodle/ünïcode ✓.txt'));
        $this->assertSame('', $zip->getFromName('moodle/empty.txt'));
        $this->assertSame('part one part two', $zip->getFromName('database.sql.gz'));

        $this->assertSame(\ZipArchive::CM_DEFLATE, $zip->statName('moodle/source.bin')['comp_method']);
        $this->assertSame(\ZipArchive::CM_STORE, $zip->statName('moodle/stored.bin')['comp_method']);

        $zip->getExternalAttributesName('moodle/source.bin', $opsys, $attr);
        $this->assertSame(\ZipArchive::OPSYS_UNIX, $opsys);
        $this->assertSame(0100751, $attr >> 16, 'Unix type and permissions are preserved');
        $zip->getExternalAttributesName('moodle/link', $opsys, $attr);
        $this->assertSame(0120777, $attr >> 16);
        $this->assertSame('source.bin', $zip->getFromName('moodle/link'));
        $zip->close();

        $this->assertSame(hash_file('sha256', $source), $results['file']['sha256']);
        $this->assertSame(hash('sha256', 'part one part two'), $results['stream']['sha256']);
        $this->assertSame(hash('sha256', ''), $results['empty']['sha256']);
    }

    public function test_refuses_to_overwrite(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/out.zip', 'existing');
        try {
            new zip_writer($dir . '/out.zip', $dir . '/cd.spool');
            $this->fail('Existing archive overwritten');
        } catch (backup_exception $e) {
            $this->assertSame('existing', file_get_contents($dir . '/out.zip'));
        }
    }

    public function test_abort_removes_partial_archive(): void {
        $dir = make_request_directory();
        $writer = new zip_writer($dir . '/out.zip', $dir . '/cd.spool');
        $writer->add_string('moodle/a.txt', 'a', true);
        $writer->abort();
        $this->assertFileDoesNotExist($dir . '/out.zip');
        $this->assertFileDoesNotExist($dir . '/cd.spool');
    }

    /**
     * Names that must never enter an archive.
     *
     * @return array
     */
    public function unsafe_name_provider(): array {
        return [['../evil.php'], ['/etc/passwd'], ['moodle\\..\\x'], ["a\0b"], ["bad\xff\xfeutf8"]];
    }

    /**
     * Unsafe names are rejected.
     *
     * @dataProvider unsafe_name_provider
     * @param string $name
     */
    public function test_unsafe_names_rejected(string $name): void {
        $dir = make_request_directory();
        $writer = new zip_writer($dir . '/out.zip', $dir . '/cd.spool');
        $this->expectException(backup_exception::class);
        $writer->add_string($name, 'x', false);
    }

    public function test_vanished_file(): void {
        $dir = make_request_directory();
        $writer = new zip_writer($dir . '/out.zip', $dir . '/cd.spool');
        $this->expectException(vanished_file_exception::class);
        $writer->add_file('moodle/gone.txt', $dir . '/gone.txt', true);
    }

    public function test_replaced_file_detected(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/a.txt', 'a');
        $entry = new \tool_moodleclone\local\filesystem\tree_entry();
        $entry->ino = fileinode($dir . '/a.txt') + 1;
        $entry->dev = (int) stat($dir . '/a.txt')['dev'];
        $writer = new zip_writer($dir . '/out.zip', $dir . '/cd.spool');
        try {
            $writer->add_file('moodle/a.txt', $dir . '/a.txt', true, null, $entry);
            $this->fail('Swapped file accepted');
        } catch (backup_exception $e) {
            $this->assertSame('replaced', $e->reason);
        }
    }

    public function test_only_one_stream_at_a_time(): void {
        $dir = make_request_directory();
        $writer = new zip_writer($dir . '/out.zip', $dir . '/cd.spool');
        $writer->open_stream('database.sql.gz', false);
        $this->expectException(\coding_exception::class);
        $writer->add_string('manifest.json', '{}', true);
    }

    public function test_progress_callback_counts_bytes(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/big.bin', str_repeat('x', 3 * zip_writer::CHUNK + 5));
        $writer = new zip_writer($dir . '/out.zip', $dir . '/cd.spool');
        $seen = 0;
        $writer->add_file('moodle/big.bin', $dir . '/big.bin', true, function(int $bytes) use (&$seen) {
            $seen += $bytes;
        });
        $this->assertSame(3 * zip_writer::CHUNK + 5, $seen);
    }

    public function test_dos_datetime(): void {
        $this->assertSame([0, (1 << 5) | 1], zip_writer::dos_datetime(0), 'Dates before 1980 are clamped');
        [$time, $date] = zip_writer::dos_datetime(mktime(13, 45, 30, 9, 24, 2026));
        $this->assertSame((13 << 11) | (45 << 5) | 15, $time);
        $this->assertSame(((2026 - 1980) << 9) | (9 << 5) | 24, $date);
    }
}
