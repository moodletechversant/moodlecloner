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

namespace tool_moodleclone\local\environment;

/**
 * Tests for the environment collector.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\environment\collector
 * @covers     \tool_moodleclone\local\environment\snapshot
 */
class collector_test extends \advanced_testcase {
    /**
     * Fake configuration.
     *
     * @param string $dataroot
     * @return \stdClass
     */
    private function fake_cfg(string $dataroot): \stdClass {
        return (object) [
            'version' => '2022112800.00',
            'release' => '4.1 (Build: 20221128)',
            'branch' => '401',
            'dbtype' => 'pgsql',
            'dbname' => 'sourcedb',
            'dbuser' => 'sourceuser',
            'dbpass' => 'never-collected-pw',
            'prefix' => 'abc_',
            'wwwroot' => 'https://source.example.com',
            'dirroot' => '/srv/moodle',
            'dataroot' => $dataroot,
        ];
    }

    public function test_collects_configuration_values(): void {
        global $DB;
        $dataroot = make_request_directory();

        $snapshot = (new collector($this->fake_cfg($dataroot), $DB))->collect();

        $this->assertSame('2022112800.00', $snapshot->moodleversion);
        $this->assertSame('4.1 (Build: 20221128)', $snapshot->moodlerelease);
        $this->assertSame('401', $snapshot->moodlebranch);
        $this->assertSame(PHP_VERSION, $snapshot->phpversion);
        $this->assertSame('pgsql', $snapshot->dbtype);
        $this->assertSame($DB->get_dbfamily(), $snapshot->dbfamily);
        $this->assertSame('sourcedb', $snapshot->dbname);
        $this->assertSame('sourceuser', $snapshot->dbuser);
        $this->assertSame('abc_', $snapshot->prefix);
        $this->assertSame('https://source.example.com', $snapshot->wwwroot);
        $this->assertSame('/srv/moodle', $snapshot->dirroot);
        $this->assertSame($dataroot, $snapshot->dataroot);
        $this->assertStringStartsWith(PHP_OS_FAMILY, $snapshot->os);
        $this->assertIsInt($snapshot->freediskspace);
        $this->assertGreaterThan(0, $snapshot->freediskspace);
        $this->assertArrayHasKey('pgsql', $snapshot->extensions, 'Driver extension is reported');
        $this->assertSame(extension_loaded('zip'), $snapshot->extensions['zip']);
    }

    public function test_password_is_never_collected(): void {
        global $DB;
        $snapshot = (new collector($this->fake_cfg(make_request_directory()), $DB))->collect();

        $this->assertStringNotContainsString('never-collected-pw', serialize($snapshot));
        $this->assertStringNotContainsString('never-collected-pw', json_encode(get_object_vars($snapshot)));
    }

    public function test_missing_dataroot_gives_unknown_disk_space(): void {
        global $DB;
        $snapshot = (new collector($this->fake_cfg('/nonexistent/' . random_string(10)), $DB))->collect();
        $this->assertNull($snapshot->freediskspace);
    }

    public function test_real_environment(): void {
        global $CFG, $DB;
        $snapshot = (new collector())->collect();
        $this->assertSame((string) $CFG->version, $snapshot->moodleversion);
        $this->assertSame($CFG->prefix, $snapshot->prefix);
        $this->assertSame($DB->get_dbfamily(), $snapshot->dbfamily);
    }

    public function test_required_extensions_include_driver(): void {
        $this->assertContains('mysqli', collector::get_required_extensions('mariadb'));
        $this->assertContains('pgsql', collector::get_required_extensions('pgsql'));
        $this->assertSame(collector::REQUIRED_EXTENSIONS, collector::get_required_extensions('unknown'));
    }

    public function test_missing_extensions(): void {
        $snapshot = new snapshot();
        $snapshot->extensions = ['zip' => true, 'zlib' => false];
        $this->assertSame(['zlib', 'json'], $snapshot->get_missing_extensions(['zip', 'zlib', 'json']));
    }
}
