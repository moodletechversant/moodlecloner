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

use tool_moodleclone\local\job\job;

/**
 * Tests for download resolution (shared by download.php and the admin page).
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\package_download
 */
class package_download_test extends \advanced_testcase {
    /** @var string */
    private const NAME = 'moodle-clone-2026-01-01-000000.zip';

    /**
     * A completed job with a package file.
     *
     * @param workspace $workspace
     * @param string $status
     * @return job
     */
    private function job_with_package(workspace $workspace, string $status = job::STATUS_COMPLETED): job {
        $workspace->prepare();
        file_put_contents($workspace->get_packages_dir() . '/' . self::NAME, 'zip');
        $job = new job(0, (object) ['status' => $status, 'filename' => self::NAME]);
        $job->create();
        return $job;
    }

    public function test_completed_readable_package_resolves(): void {
        $this->resetAfterTest();
        $workspace = new workspace(make_request_directory());
        $job = $this->job_with_package($workspace);
        $this->assertSame($workspace->get_package_path(self::NAME), package_download::resolve($job, $workspace));
        $this->assertNull(package_download::problem($job, $workspace));
    }

    public function test_only_completed_jobs(): void {
        $this->resetAfterTest();
        $workspace = new workspace(make_request_directory());
        $job = $this->job_with_package($workspace, job::STATUS_RUNNING);
        $this->expectException(\moodle_exception::class);
        package_download::resolve($job, $workspace);
    }

    public function test_missing_file(): void {
        $this->resetAfterTest();
        $workspace = new workspace(make_request_directory());
        $job = $this->job_with_package($workspace);
        unlink($workspace->get_packages_dir() . '/' . self::NAME);
        $this->assertSame(get_string('error:packagemissing', 'tool_moodleclone'), package_download::problem($job, $workspace));
    }

    public function test_unreadable_file_names_the_owner(): void {
        if (DIRECTORY_SEPARATOR !== '/' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('Needs Unix permissions and a non-root user');
        }
        $this->resetAfterTest();
        $workspace = new workspace(make_request_directory());
        $job = $this->job_with_package($workspace);
        chmod($workspace->get_packages_dir() . '/' . self::NAME, 0000);

        $problem = package_download::problem($job, $workspace);

        chmod($workspace->get_packages_dir() . '/' . self::NAME, 0600);
        $this->assertStringContainsString('cannot be read by the web server', $problem);
        if (function_exists('posix_getpwuid')) {
            $this->assertStringContainsString(posix_getpwuid(posix_geteuid())['name'], $problem);
        }
    }

    public function test_symlinked_package_is_refused(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
        $this->resetAfterTest();
        $workspace = new workspace(make_request_directory());
        $job = $this->job_with_package($workspace);
        $path = $workspace->get_packages_dir() . '/' . self::NAME;
        unlink($path);
        symlink('/etc/passwd', $path);
        $this->expectException(\moodle_exception::class);
        package_download::resolve($job, $workspace);
    }
}
