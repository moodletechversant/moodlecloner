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
 * Tests for the content inclusion policies.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\backup\content_policy
 * @covers     \tool_moodleclone\local\backup\source_paths
 */
class content_policy_test extends \basic_testcase {

    /**
     * Dataroot paths and whether they are packaged.
     *
     * @return array
     */
    public function dataroot_provider(): array {
        return [
            'filedir content' => ['filedir/0a/1b/0a1b2c', true],
            'language packs' => ['lang/fr/langconfig.php', true],
            'htaccess' => ['.htaccess', true],
            'unknown plugin data is kept' => ['someplugin/data.bin', true],
            'filedir-like name is not excluded dir' => ['cachebackup/x', true],
            'cache' => ['cache/core_component.php', false],
            'localcache' => ['localcache/mustache/x.php', false],
            'temp' => ['temp/backup/x', false],
            'sessions' => ['sessions/sess_abc', false],
            'trashdir' => ['trashdir/0a/1b/0a1b2c', false],
            'locks' => ['lock/core_cron.lock', false],
            'muc config' => ['muc/config.php', false],
            'quarantine' => ['antivirus_quarantine/file', false],
            'own output' => ['moodleclone/moodle-clone-2026-09-24-134501.zip', false],
            'maintenance flag' => ['climaintenance.html', false],
            'unsafe path' => ['../config.php', false],
        ];
    }

    /**
     * Dataroot policy decisions.
     *
     * @dataProvider dataroot_provider
     * @param string $path
     * @param bool $included
     */
    public function test_dataroot_policy(string $path, bool $included): void {
        $this->assertSame($included, content_policy::for_dataroot()->should_include($path));
    }

    public function test_code_policy_excludes_config_php_only_at_root(): void {
        $policy = content_policy::for_code();
        $this->assertFalse($policy->should_include('config.php'));
        $this->assertSame('exclude:config', $policy->get_exclusion_reason('config.php'));
        $this->assertTrue($policy->should_include('config-dist.php'));
        $this->assertTrue($policy->should_include('admin/tool/example/config.php'));
        $this->assertFalse($policy->should_include('.git/HEAD'));
        $this->assertFalse($policy->should_include('node_modules/grunt/package.json'));
        $this->assertTrue($policy->should_include('lib/moodlelib.php'));
    }

    public function test_every_reason_has_a_language_string(): void {
        $manager = get_string_manager();
        foreach ([content_policy::for_dataroot(), content_policy::for_code()] as $policy) {
            $reasons = array_merge($policy->get_excluded_dirs(), $policy->get_excluded_paths(), ['exclude:unsafe']);
            foreach ($reasons as $reason) {
                $this->assertTrue($manager->string_exists($reason, 'tool_moodleclone'), $reason);
            }
        }
    }

    public function test_site_dataroot_excludes_configured_runtime_dirs(): void {
        $dataroot = make_request_directory();
        fixture_helper::make_tree($dataroot, ['mytemp' => null, 'mycache' => null, 'keep' => null]);
        $paths = source_paths::from_config(fixture_helper::config(make_request_directory(), $dataroot,
            ['tempdir' => $dataroot . '/mytemp', 'cachedir' => $dataroot . '/mycache', 'localcachedir' => '/elsewhere']));

        $policy = content_policy::for_site_dataroot($paths);

        $this->assertFalse($policy->should_include('mytemp/x'));
        $this->assertFalse($policy->should_include('mycache'));
        $this->assertTrue($policy->should_include('keep/a'));
        $this->assertTrue($policy->should_include('mytemporary/a'), 'Prefixes match whole path segments only');
        $this->assertSame(['mycache', 'mytemp'], array_values(array_intersect($policy->describe_exclusions(),
            ['mytemp', 'mycache'])));
    }

    public function test_site_code_excludes_nested_data(): void {
        $dirroot = make_request_directory();
        fixture_helper::make_tree($dirroot, ['data' => null]);
        $paths = source_paths::from_config(fixture_helper::config($dirroot, $dirroot . '/data'));

        $policy = content_policy::for_site_code($paths);

        $this->assertFalse($policy->should_include('data/filedir/x'));
        $this->assertFalse($policy->should_include('config.php'));
        $this->assertTrue($policy->should_include('lib/data.php'));
    }

    public function test_custom_filedir_excludes_default_location(): void {
        $dataroot = make_request_directory();
        $paths = source_paths::from_config(fixture_helper::config(make_request_directory(), $dataroot,
            ['filedir' => make_request_directory()]));

        $this->assertTrue($paths->customfiledir);
        $this->assertFalse(content_policy::for_site_dataroot($paths)->should_include('filedir/aa/bb/x'));
    }
}
