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

namespace tool_moodleclone\local\backup\step;

use tool_moodleclone\fixture_helper;
use tool_moodleclone\local\backup\backup_exception;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../fixtures/fixture_helper.php');

/**
 * Tests for the Moodle code collector.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\backup\step\code_collector
 * @covers     \tool_moodleclone\local\backup\step\tree_collector
 */
class code_collector_test extends \advanced_testcase {

    /**
     * Collect a fixture code tree and return archive names and checksum lines.
     *
     * @param array $tree
     * @return array [names, checksum spool content, state]
     */
    private function collect(array $tree): array {
        $dirroot = make_request_directory();
        $dataroot = make_request_directory();
        fixture_helper::make_tree($dirroot, $tree);
        $state = fixture_helper::make_state($dirroot, $dataroot);
        (new code_collector())->execute($state);
        $state->zip->finish();
        $spool = $state->checksums->close();
        return [fixture_helper::zip_names($state->zip->get_path()), file_get_contents($spool), $state];
    }

    public function test_config_php_is_excluded_and_code_included(): void {
        [$names, $checksums, $state] = $this->collect([
            'config.php' => '<?php $CFG->dbpass = "secret";',
            'config-dist.php' => 'dist',
            'index.php' => 'index',
            'lib/moodlelib.php' => 'lib',
            'mod/forum/lib.php' => 'forum',
            'mod/forum/classes/deep/nested/thing.php' => 'deep',
            'admin/tool/example/config.php' => 'plugin config',
            '.git/HEAD' => 'ref',
            'node_modules/x/index.js' => 'js',
        ]);

        $this->assertNotContains('moodle/config.php', $names);
        $this->assertContains('moodle/config-dist.php', $names);
        $this->assertContains('moodle/index.php', $names);
        $this->assertContains('moodle/mod/forum/classes/deep/nested/thing.php', $names);
        $this->assertContains('moodle/admin/tool/example/config.php', $names, 'Only the root config.php is excluded');
        $this->assertNotContains('moodle/.git/HEAD', $names);
        $this->assertNotContains('moodle/node_modules/x/index.js', $names);
        $this->assertStringContainsString(hash('sha256', 'deep') . '  moodle/mod/forum/classes/deep/nested/thing.php', $checksums);
        $this->assertStringNotContainsString('  moodle/config.php' . "\n", $checksums);
        $this->assertSame(6, $state->statistics['moodle']['files']);
    }

    public function test_external_symlink_fails_the_step(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
        $this->expectException(backup_exception::class);
        $this->collect(['index.php' => 'x', 'theme/stolen' => ['link' => '/etc']]);
    }

    public function test_nested_dataroot_is_not_collected_as_code(): void {
        $dirroot = make_request_directory();
        fixture_helper::make_tree($dirroot, ['index.php' => 'x', 'moodledata/filedir/aa/bb/f' => 'data']);
        $state = fixture_helper::make_state($dirroot, $dirroot . '/moodledata');
        (new code_collector())->execute($state);
        $state->zip->finish();

        $names = fixture_helper::zip_names($state->zip->get_path());
        $this->assertContains('moodle/index.php', $names);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('moodle/moodledata', $name);
        }
    }
}
