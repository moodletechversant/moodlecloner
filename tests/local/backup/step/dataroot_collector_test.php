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
 * Tests for the moodledata collector.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\backup\step\dataroot_collector
 */
class dataroot_collector_test extends \advanced_testcase {

    /**
     * Run the collector.
     *
     * @param string $dataroot
     * @param array $extra Extra config.
     * @param int|null $snapshottime
     * @return array [names, state]
     */
    private function collect(string $dataroot, array $extra = [], ?int $snapshottime = null): array {
        $state = fixture_helper::make_state(make_request_directory(), $dataroot, null, $extra);
        $state->snapshottime = $snapshottime;
        (new dataroot_collector())->execute($state);
        $state->zip->finish();
        return [fixture_helper::zip_names($state->zip->get_path()), $state];
    }

    public function test_persistent_included_and_regenerable_excluded(): void {
        $dataroot = make_request_directory();
        $hash = fixture_helper::add_pool_file($dataroot . '/filedir', 'course file');
        fixture_helper::make_tree($dataroot, [
            'lang/fr/langconfig.php' => 'fr',
            'someplugin/state.json' => '{}',
            '.htaccess' => 'deny from all',
            'cache/core/x.php' => 'c', 'localcache/y' => 'l', 'sessions/sess_abc' => 's', 'temp/t' => 't',
            'trashdir/aa/bb/aabb' => 'trash', 'lock/l' => 'l', 'muc/config.php' => 'redis password',
            'climaintenance.html' => 'maintenance', 'antivirus_quarantine/v' => 'virus', 'moodleclone/work/x' => 'own',
        ]);

        [$names] = $this->collect($dataroot);

        $this->assertContains('moodledata/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash, $names);
        $this->assertContains('moodledata/lang/fr/langconfig.php', $names);
        $this->assertContains('moodledata/someplugin/state.json', $names, 'Unknown directories are preserved');
        $this->assertContains('moodledata/.htaccess', $names);
        foreach (['cache', 'localcache', 'sessions', 'temp', 'trashdir', 'lock', 'muc', 'climaintenance.html',
                'antivirus_quarantine', 'moodleclone'] as $excluded) {
            foreach ($names as $name) {
                $this->assertStringStartsNotWith('moodledata/' . $excluded, $name, $excluded);
            }
        }
    }

    public function test_external_symlink_rejected(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
        $dataroot = make_request_directory();
        fixture_helper::make_tree($dataroot, ['filedir/aa/bb/x' => 'x', 'repository/share' => ['link' => '/etc']]);
        try {
            $this->collect($dataroot);
            $this->fail('External symlink followed');
        } catch (backup_exception $e) {
            $this->assertSame('externalsymlink', $e->reason);
        }
    }

    public function test_configured_runtime_dirs_inside_dataroot_are_excluded(): void {
        $dataroot = make_request_directory();
        fixture_helper::make_tree($dataroot, ['mytemp/big.tmp' => 'tmp', 'keep/a' => 'a']);

        [$names] = $this->collect($dataroot, ['tempdir' => $dataroot . '/mytemp']);

        $this->assertContains('moodledata/keep/a', $names);
        $this->assertNotContains('moodledata/mytemp/big.tmp', $names);
    }

    public function test_custom_filedir_is_collected_as_filedir(): void {
        $dataroot = make_request_directory();
        $pool = make_request_directory();
        $hash = fixture_helper::add_pool_file($pool, 'pooled elsewhere');
        fixture_helper::add_pool_file($dataroot . '/filedir', 'stale unused copy');

        [$names] = $this->collect($dataroot, ['filedir' => $pool]);

        $this->assertContains('moodledata/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash, $names);
        $this->assertNotContains('moodledata/filedir/' . substr(sha1('stale unused copy'), 0, 2) . '/' .
            substr(sha1('stale unused copy'), 2, 2) . '/' . sha1('stale unused copy'), $names);
    }

    public function test_content_trashed_after_snapshot_is_recovered_into_filedir(): void {
        $dataroot = make_request_directory();
        $recent = fixture_helper::add_pool_file($dataroot . '/trashdir', 'deleted during the backup');
        fixture_helper::make_tree($dataroot, ['trashdir/' . str_repeat('0', 40) => 'content that does not match its hash']);

        [$names, $state] = $this->collect($dataroot, [], time());

        $this->assertContains('moodledata/filedir/' . substr($recent, 0, 2) . '/' . substr($recent, 2, 2) . '/' . $recent,
            $names);
        $this->assertNotContains('moodledata/filedir/00/00/' . str_repeat('0', 40), $names, 'Hash mismatch is skipped');
        $this->assertSame(1, $state->statistics['moodledata']['recovered_from_trash']);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('moodledata/trashdir', $name);
        }
    }

    public function test_content_trashed_before_snapshot_is_not_recovered(): void {
        $dataroot = make_request_directory();
        fixture_helper::add_pool_file($dataroot . '/trashdir', 'deleted long before');

        // A snapshot "in the future" means the file was trashed before it.
        [, $state] = $this->collect($dataroot, [], time() + 3600);

        $this->assertSame(0, $state->statistics['moodledata']['recovered_from_trash']);
    }

    public function test_no_trash_recovery_without_database_snapshot(): void {
        $dataroot = make_request_directory();
        fixture_helper::add_pool_file($dataroot . '/trashdir', 'no database in this package');

        [, $state] = $this->collect($dataroot, [], null);

        $this->assertSame(0, $state->statistics['moodledata']['recovered_from_trash']);
    }
}
