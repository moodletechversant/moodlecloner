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

use tool_moodleclone\fixture_helper;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/fixture_helper.php');

/**
 * Tests for the disk space estimate.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\backup\size_estimator
 */
class size_estimator_test extends \advanced_testcase {
    public function test_tree_counts_match_policy(): void {
        $root = make_request_directory();
        fixture_helper::make_tree($root, ['a/b.txt' => '12345', 'c.txt' => '123', 'config.php' => str_repeat('x', 1000)]);

        $result = (new size_estimator())->estimate_tree($root, content_policy::for_code()->get_filter());

        $this->assertSame(['files' => 2, 'directories' => 1, 'symlinks' => 0, 'bytes' => 8], $result);
    }

    public function test_required_bytes_is_conservative(): void {
        $payload = 10 * 1024 * 1024 * 1024;
        $required = size_estimator::required_bytes($payload, 100000);
        $this->assertGreaterThan($payload, $required, 'Never less than the data itself');
        $this->assertSame((int) ceil($payload * 1.1) + 100000 * 1024 + 256 * 1024 * 1024, $required);
    }

    public function test_estimate_respects_options(): void {
        global $DB;
        $dirroot = make_request_directory();
        $dataroot = make_request_directory();
        fixture_helper::make_tree($dirroot, ['index.php' => str_repeat('x', 100)]);
        fixture_helper::make_tree($dataroot, ['filedir/aa/bb/f' => str_repeat('y', 50), 'cache/c' => str_repeat('z', 999)]);
        $paths = source_paths::from_config(fixture_helper::config($dirroot, $dataroot));
        $options = new options();
        $options->includedatabase = false;

        $estimate = (new size_estimator())->estimate($paths, $options, $DB, $dataroot . '/moodleclone');

        $this->assertSame(100, $estimate['moodle']['bytes']);
        $this->assertSame(50, $estimate['moodledata']['bytes'], 'Excluded directories are not counted');
        $this->assertNull($estimate['database']);
        $this->assertSame(size_estimator::required_bytes(150, 3 + 1 + 4), $estimate['required']);
        $this->assertIsInt($estimate['free']);
    }

    public function test_database_summary(): void {
        global $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MySQL only');
        }
        $summary = (new size_estimator())->database_summary($DB);
        $this->assertGreaterThan(100, $summary['tables']);
        $this->assertSame([], $summary['nontransactional']);
    }
}
