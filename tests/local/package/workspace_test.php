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
use tool_moodleclone\local\filesystem\invalid_path_exception;

/**
 * Tests for the workspace directories.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\workspace
 */
class workspace_test extends \advanced_testcase {
    public function test_prepare_creates_private_directories(): void {
        $dataroot = make_request_directory();
        $workspace = new workspace($dataroot);
        $dir = $workspace->create_work_dir(5);

        $this->assertSame($dataroot . '/moodleclone/work/job-5', $dir);
        foreach ([$dataroot . '/moodleclone', $dir, $workspace->get_packages_dir()] as $path) {
            $this->assertDirectoryExists($path);
            if (DIRECTORY_SEPARATOR === '/') {
                $this->assertSame(0700, fileperms($path) & 0777, $path);
            }
        }
    }

    public function test_create_work_dir_starts_empty(): void {
        $workspace = new workspace(make_request_directory());
        $dir = $workspace->create_work_dir(1);
        file_put_contents($dir . '/leftover.part', 'x');
        $this->assertSame($dir, $workspace->create_work_dir(1));
        $this->assertFileDoesNotExist($dir . '/leftover.part');
    }

    /**
     * Names that are not packages.
     *
     * @return array
     */
    public static function bad_name_provider(): array {
        return [['../../config.php'], ['moodle-clone-2026-09-24-134501.zip/../../x'], ['.htaccess'],
            ['moodle-clone-2026-09-24-134501.zip.part'], ['/etc/passwd']];
    }

    /**
     * Only package names resolve to a path.
     *
     * @dataProvider bad_name_provider
     * @param string $name
     */
    public function test_package_path_rejects_other_names(string $name): void {
        $workspace = new workspace(make_request_directory());
        $workspace->prepare();
        $this->expectException(invalid_path_exception::class);
        $workspace->get_package_path($name);
    }

    public function test_package_path(): void {
        $dataroot = make_request_directory();
        $workspace = new workspace($dataroot);
        $workspace->prepare();
        $this->assertSame(
            str_replace('\\', '/', realpath($dataroot)) . '/moodleclone/packages/moodle-clone-2026-09-24-134501.zip',
            $workspace->get_package_path('moodle-clone-2026-09-24-134501.zip')
        );
    }

    public function test_remove_tree_does_not_follow_symlinks(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
        $outside = make_request_directory();
        file_put_contents($outside . '/precious.txt', 'keep me');
        $workspace = new workspace(make_request_directory());
        $dir = $workspace->create_work_dir(2);
        symlink($outside, $dir . '/link');

        $workspace->remove_work_dir(2);

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFileExists($outside . '/precious.txt');
    }

    public function test_remove_tree_refuses_paths_outside(): void {
        $workspace = new workspace(make_request_directory());
        $workspace->prepare();
        $this->expectException(invalid_path_exception::class);
        $workspace->remove_tree(make_request_directory());
    }

    public function test_symlinked_workspace_is_refused(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
        $dataroot = make_request_directory();
        symlink(make_request_directory(), $dataroot . '/moodleclone');
        $this->expectException(backup_exception::class);
        (new workspace($dataroot))->prepare();
    }

    public function test_cleanup_keeps_owned_files_only(): void {
        $workspace = new workspace(make_request_directory());
        $workspace->create_work_dir(1);
        $workspace->create_work_dir(2);
        $packages = $workspace->get_packages_dir();
        $keep = 'moodle-clone-2026-01-01-000000.zip';
        $orphan = 'moodle-clone-2026-01-02-000000.zip';
        foreach ([$keep, $keep . '.sha256', $orphan, $orphan . '.sha256', 'README.txt'] as $name) {
            file_put_contents($packages . '/' . $name, 'x');
        }

        $removed = $workspace->cleanup([2], [$keep]);

        $this->assertSame(3, $removed);
        $this->assertDirectoryDoesNotExist($workspace->get_work_dir(1));
        $this->assertDirectoryExists($workspace->get_work_dir(2));
        $this->assertFileExists($packages . '/' . $keep);
        $this->assertFileExists($packages . '/' . $keep . '.sha256');
        $this->assertFileDoesNotExist($packages . '/' . $orphan);
        $this->assertFileExists($packages . '/README.txt', 'Files the plugin did not name are left alone');
    }

    public function test_broader_permissions_are_tightened(): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Unix permissions only');
        }
        $dataroot = make_request_directory();
        mkdir($dataroot . '/moodleclone', 0777);
        chmod($dataroot . '/moodleclone', 0777);
        mkdir($dataroot . '/moodleclone/packages', 0777);
        chmod($dataroot . '/moodleclone/packages', 0777);

        (new workspace($dataroot))->prepare();

        clearstatcache();
        $this->assertSame(0700, fileperms($dataroot . '/moodleclone') & 0777, 'chmod 777 is undone');
        $this->assertSame(0700, fileperms($dataroot . '/moodleclone/packages') & 0777);
    }

    public function test_directory_owned_by_another_user_is_refused(): void {
        if (!\tool_moodleclone\local\environment\os_identity::is_supported() || posix_geteuid() === 0) {
            $this->markTestSkipped('Needs posix and a non-root user');
        }
        // The "/" directory belongs to root, so it stands in for a directory owned by someone else.
        $problem = workspace::ownership_problem('/');
        $this->assertSame('root', $problem->owner);
        $this->expectException(backup_exception::class);
        workspace::make_private_dir('/');
    }

    public function test_check_reports_nothing_for_a_fresh_workspace(): void {
        $this->assertNull((new workspace(make_request_directory()))->check());
    }
}
