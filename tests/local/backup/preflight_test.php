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

namespace tool_moodleclone\local\backup;

use tool_moodleclone\local\environment\snapshot;

/**
 * Tests for the pre-flight checks.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\backup\preflight
 */
class preflight_test extends \advanced_testcase {
    /**
     * A snapshot describing a healthy server.
     *
     * @return snapshot
     */
    private function healthy_snapshot(): snapshot {
        global $CFG;
        $s = new snapshot();
        $s->dbtype = 'mysqli';
        $s->dbfamily = 'mysql';
        $s->dbversion = '8.0.46';
        $s->dirroot = $CFG->dirroot;
        $s->dataroot = $CFG->dataroot;
        $s->freediskspace = 5 * 1024 * 1024 * 1024;
        $s->extensions = ['zip' => true, 'zlib' => true, 'json' => true, 'hash' => true, 'mysqli' => true];
        return $s;
    }

    /**
     * Index results by check name.
     *
     * @param array[] $results
     * @return array[]
     */
    private function by_check(array $results): array {
        return array_column($results, null, 'check');
    }

    public function test_healthy_server_with_complete_pipeline(): void {
        $results = (new preflight($this->healthy_snapshot(), new manager([])))->run();
        $this->assertSame(
            ['extensions', 'dirroot', 'dataroot', 'database', 'diskspace', 'pipeline'],
            array_column($results, 'check')
        );
        $this->assertSame(['ok'], array_values(array_unique(array_column($results, 'status'))));
        $this->assertFalse(preflight::has_errors($results));
    }

    public function test_missing_driver_extension_is_an_error(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot->extensions['mysqli'] = false;
        unset($snapshot->extensions['zip']);

        $results = $this->by_check((new preflight($snapshot, new manager([])))->run());
        $this->assertSame(preflight::ERROR, $results['extensions']['status']);
        $this->assertStringContainsString('zip, mysqli', $results['extensions']['message']);
    }

    public function test_unreadable_dataroot_is_an_error(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot->dataroot = make_request_directory() . '/does-not-exist';

        $results = (new preflight($snapshot, new manager([])))->run();
        $this->assertSame(preflight::ERROR, $this->by_check($results)['dataroot']['status']);
        $this->assertTrue(preflight::has_errors($results));
    }

    public function test_unknown_disk_space_is_a_warning(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot->freediskspace = null;

        $results = (new preflight($snapshot, new manager([])))->run();
        $this->assertSame(preflight::WARNING, $this->by_check($results)['diskspace']['status']);
        $this->assertFalse(preflight::has_errors($results));
    }

    public function test_default_pipeline_is_complete(): void {
        $results = $this->by_check((new preflight($this->healthy_snapshot(), manager::create_default()))->run());
        $this->assertSame(preflight::OK, $results['pipeline']['status']);
    }

    public function test_incomplete_pipeline_is_an_error(): void {
        $stub = new class extends step\not_implemented_step {
            /**
             * Get the stage this step implements.
             */
            public function get_stage(): string {
                return stage::DATABASE;
            }
        };
        $results = $this->by_check((new preflight($this->healthy_snapshot(), new manager([$stub])))->run());
        $this->assertSame(preflight::ERROR, $results['pipeline']['status']);
        $this->assertStringContainsString(get_string('stage:database', 'tool_moodleclone'), $results['pipeline']['message']);
    }

    public function test_non_mysql_database_is_an_error_only_when_dumped(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot->dbtype = 'pgsql';
        $snapshot->dbfamily = 'postgres';
        $snapshot->extensions['pgsql'] = true;

        $results = $this->by_check((new preflight($snapshot, new manager([])))->run());
        $this->assertSame(preflight::ERROR, $results['database']['status']);

        $options = new options();
        $options->includedatabase = false;
        $results = $this->by_check((new preflight($snapshot, new manager([]), null, $options))->run());
        $this->assertArrayNotHasKey('database', $results);
    }

    public function test_untested_mysql_driver_is_a_warning(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot->dbtype = 'mariadb';
        $results = $this->by_check((new preflight($snapshot, new manager([])))->run());
        $this->assertSame(preflight::WARNING, $results['database']['status']);
    }

    public function test_non_innodb_tables_block_the_backup(): void {
        $summary = ['tables' => 3, 'rows' => 0, 'bytes' => 0, 'nontransactional' => ['mdl_legacy (MyISAM)'], 'views' => []];
        $results = $this->by_check((new preflight($this->healthy_snapshot(), new manager([]), null, null, $summary))->run());
        $this->assertSame(preflight::ERROR, $results['engines']['status']);
        $this->assertStringContainsString('mdl_legacy (MyISAM)', $results['engines']['message']);
    }

    /**
     * Estimates with free space around the requirement.
     *
     * @return array
     */
    public static function disk_provider(): array {
        return [
            'enough' => [2000, 1000, preflight::OK],
            'insufficient' => [999, 1000, preflight::ERROR],
            'unknown' => [null, 1000, preflight::WARNING],
        ];
    }

    /**
     * Disk space is compared with the estimate.
     *
     * @dataProvider disk_provider
     * @param int|null $free
     * @param int $required
     * @param string $expected
     */
    public function test_disk_space_against_estimate(?int $free, int $required, string $expected): void {
        $estimate = ['moodle' => null, 'moodledata' => null, 'database' => null, 'entries' => 3,
            'required' => $required, 'free' => $free];
        $results = $this->by_check((new preflight($this->healthy_snapshot(), new manager([]), $estimate))->run());
        $this->assertSame($expected, $results['diskspace']['status']);
        $this->assertSame($expected === preflight::ERROR, preflight::has_errors(array_values($results)));
    }

    public function test_workspace_must_be_writable_and_not_a_link(): void {
        $base = make_request_directory() . '/moodleclone';
        $results = $this->by_check((new preflight($this->healthy_snapshot(), new manager([]), null, null, null, $base))->run());
        $this->assertSame(preflight::OK, $results['workspace']['status']);

        if (function_exists('symlink') && DIRECTORY_SEPARATOR === '/') {
            symlink(make_request_directory(), $base);
            $results = $this->by_check((new preflight(
                $this->healthy_snapshot(),
                new manager([]),
                null,
                null,
                null,
                $base
            ))->run());
            $this->assertSame(preflight::ERROR, $results['workspace']['status']);
        }
    }
}
