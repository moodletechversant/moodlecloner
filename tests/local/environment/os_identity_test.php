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
 * Tests for OS identity checks.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\environment\os_identity
 */
class os_identity_test extends \advanced_testcase {

    /**
     * Skip without posix.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!os_identity::is_supported()) {
            $this->markTestSkipped('OS users cannot be determined on this platform');
        }
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('These tests must not run as root');
        }
    }

    public function test_current_and_owner(): void {
        $current = os_identity::current();
        $this->assertSame(posix_geteuid(), $current['uid']);
        $file = make_request_directory() . '/f';
        touch($file);
        $this->assertSame($current, os_identity::owner($file));
        $this->assertSame(0, os_identity::owner('/')['uid']);
    }

    public function test_no_problem_when_worker_matches_workspace_owner(): void {
        $this->resetAfterTest();
        $base = make_request_directory();
        $expected = os_identity::expected_worker($base);
        $this->assertSame(posix_geteuid(), $expected['uid']);
        $this->assertSame('owner', $expected['source']);
        $this->assertNull(os_identity::worker_problem($base));
    }

    public function test_recorded_web_identity_wins_and_mismatch_is_reported(): void {
        $this->resetAfterTest();
        set_config('webuid', posix_geteuid() + 1, 'tool_moodleclone');
        set_config('webuser', 'webserveruser', 'tool_moodleclone');

        $expected = os_identity::expected_worker(make_request_directory());
        $this->assertSame('web', $expected['source']);
        $problem = os_identity::worker_problem(make_request_directory());
        $this->assertStringContainsString('webserveruser', $problem);
        $this->assertStringContainsString('sudo -u webserveruser', $problem);
    }

    public function test_root_owned_paths_are_not_used_as_expected_worker(): void {
        $this->resetAfterTest();
        // With no recorded web user, a root-owned workspace gives no expectation (hardened setups).
        $this->assertNotSame(0, os_identity::expected_worker('/')['uid'] ?? null);
    }

    public function test_record_web_identity_does_nothing_in_cli(): void {
        $this->resetAfterTest();
        os_identity::record_web_identity();
        $this->assertNull(os_identity::get_web_identity(), 'PHPUnit runs as a CLI script');
    }
}
