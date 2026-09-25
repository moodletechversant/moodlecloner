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
use tool_moodleclone\local\environment\snapshot;

/**
 * Tests for package verification (the gate before a package is published).
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\package_verifier
 */
class package_verifier_test extends \advanced_testcase {
    /**
     * Source snapshot.
     *
     * @return snapshot
     */
    private function snapshot(): snapshot {
        $s = new snapshot();
        $s->moodleversion = '2022112800';
        $s->moodlerelease = '4.1';
        $s->moodlebranch = '401';
        $s->phpversion = '8.1.0';
        $s->dbtype = 'mysqli';
        $s->dbfamily = 'mysql';
        $s->dbversion = '8.0.36';
        $s->prefix = 'mdl_';
        $s->wwwroot = 'https://lms.example.com';
        $s->dirroot = '/var/www/moodle';
        $s->dataroot = '/var/moodledata';
        return $s;
    }

    /**
     * Write a package by hand, optionally broken in one way.
     *
     * @param string $dir
     * @param array $break One of: skipline, extraline, wrongstats, nomarker, truncatedgz, wrongorder.
     * @return array [path, entry count]
     */
    private function build(string $dir, array $break = []): array {
        $zip = new zip_writer($dir . '/p.zip', $dir . '/cd.spool');
        $lines = [];
        $zip->add_directory('moodle', 0755, time());
        foreach (['moodle/index.php' => '<?php', 'moodle/lib/a.php' => 'a'] as $name => $content) {
            $lines[$name] = $zip->add_string($name, $content, true)['sha256'];
        }
        $sql = "SET NAMES utf8mb4;\n" . (in_array('nomarker', $break) ? '' : mysql_dumper::COMPLETION_MARKER . ": 0 tables\n");
        $gz = gzencode($sql);
        if (in_array('truncatedgz', $break)) {
            $gz = substr($gz, 0, -8);
        }
        $lines['database.sql.gz'] = $zip->add_string('database.sql.gz', $gz, false)['sha256'];
        $lines['moodledata/filedir/aa/bb/x'] = $zip->add_string('moodledata/filedir/aa/bb/x', 'pool', false)['sha256'];

        $stats = [
            'moodle' => ['files' => 2, 'directories' => 1, 'symlinks' => 0, 'bytes' => 6],
            'moodledata' => ['files' => 1, 'directories' => 0, 'symlinks' => 0, 'bytes' => 4, 'recovered_from_trash' => 0],
            'database' => ['tables' => 0, 'rows' => 0],
        ];
        if (in_array('wrongstats', $break)) {
            $stats['moodle']['files'] = 3;
        }
        $manifest = manifest::from_snapshot(
            $this->snapshot(),
            ['moodle' => true, 'moodledata' => true, 'database' => true],
            time(),
            ['version' => 2026092401, 'release' => '0.2.0'],
            $stats,
            ['format' => 'mysql', 'format_version' => 1, 'compression' => 'gzip', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'consistency' => 'snapshot',
            'max_statement_bytes' => 0],
            ['cache']
        );
        $lines['manifest.json'] = $zip->add_string('manifest.json', $manifest->to_json(), true)['sha256'];

        if (in_array('skipline', $break)) {
            unset($lines['moodle/lib/a.php']);
        }
        if (in_array('extraline', $break)) {
            $lines['moodle/ghost.php'] = hash('sha256', 'ghost');
        }
        if (in_array('wrongorder', $break)) {
            $lines = array_reverse($lines, true);
        }
        $list = '';
        foreach ($lines as $name => $hash) {
            $list .= $hash . '  ' . $name . "\n";
        }
        $zip->add_string('checksums.sha256', $list, true);
        $zip->finish();
        return [$dir . '/p.zip', $zip->get_entry_count()];
    }

    public function test_valid_package_passes(): void {
        [$path, $count] = $this->build(make_request_directory());
        $fractions = [];
        $manifest = (new package_verifier(function (float $f) use (&$fractions) {
            $fractions[] = $f;
        }))->verify($path, $count);
        $this->assertSame(3, $manifest->get('format'));
        $this->assertSame(1.0, end($fractions));
    }

    /**
     * Ways a package can be incomplete or inconsistent.
     *
     * @return array
     */
    public static function broken_provider(): array {
        return [
            'file missing from checksums' => ['skipline', 'does not match the archive'],
            'checksum for missing file' => ['extraline', 'not in the archive'],
            'statistics do not match' => ['wrongstats', 'statistics.moodle.files'],
            'dump without completion marker' => ['nomarker', 'completion marker'],
            'truncated dump' => ['truncatedgz', 'database.sql.gz'],
            'checksums out of order' => ['wrongorder', 'does not match the archive'],
        ];
    }

    /**
     * Broken packages are rejected.
     *
     * @dataProvider broken_provider
     * @param string $break
     * @param string $expected Substring of the error.
     */
    public function test_broken_package_rejected(string $break, string $expected): void {
        [$path, $count] = $this->build(make_request_directory(), [$break]);
        try {
            (new package_verifier())->verify($path, $count);
            $this->fail('Broken package accepted');
        } catch (invalid_package_exception $e) {
            $this->assertStringContainsString($expected, implode("\n", $e->errors));
        }
    }

    public function test_wrong_entry_count_rejected(): void {
        [$path, $count] = $this->build(make_request_directory());
        $this->expectException(invalid_package_exception::class);
        (new package_verifier())->verify($path, $count + 1);
    }

    public function test_tampered_content_rejected(): void {
        [$path, $count] = $this->build(make_request_directory());
        // The 'pool' entry is stored uncompressed; change it in place.
        $data = file_get_contents($path);
        $offset = strpos($data, 'pool');
        $this->assertNotFalse($offset);
        $data[$offset] = 'P';
        file_put_contents($path, $data);

        $this->expectException(invalid_package_exception::class);
        (new package_verifier())->verify($path, $count);
    }

    public function test_truncated_archive_rejected(): void {
        [$path, $count] = $this->build(make_request_directory());
        $handle = fopen($path, 'r+');
        ftruncate($handle, (int) (filesize($path) / 2));
        fclose($handle);

        $this->expectException(invalid_package_exception::class);
        (new package_verifier())->verify($path, $count);
    }

    public function test_hash_file(): void {
        $path = make_request_directory() . '/f';
        file_put_contents($path, str_repeat('abc', 100000));
        $this->assertSame(hash_file('sha256', $path), package_verifier::hash_file($path));
    }
}
