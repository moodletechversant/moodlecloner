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
use tool_moodleclone\local\environment\os_identity;
use tool_moodleclone\local\filesystem\invalid_path_exception;
use tool_moodleclone\local\filesystem\path_validator;

/**
 * Directories the plugin writes to, all under $CFG->dataroot/moodleclone/.
 *
 * <pre>
 * moodleclone/            (0700; excluded from backups by content_policy)
 *     work/job-<id>/      one working directory per running job
 *     packages/           finished, verified packages only
 * </pre>
 *
 * Packages only ever appear in packages/ through an atomic rename() from the
 * job's working directory (same filesystem), so a partially written archive
 * is never visible there. Paths are built only from these constants, job ids
 * and names matching layout::FILENAME_PATTERN, never from requests.
 *
 * Permissions: a package contains the whole site, so directories are 0700 and
 * files 0600, owned by the OS user the web server runs as (see os_identity).
 * An existing directory owned by another user is refused rather than used,
 * and one with broader permissions (e.g. after a manual chmod 777) is
 * tightened back to 0700 before anything is written into it.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workspace {

    /** @var string Directory name inside dataroot. */
    public const BASE = 'moodleclone';

    /** @var string Working directories. */
    public const WORK = 'work';

    /** @var string Finished packages. */
    public const PACKAGES = 'packages';

    /** @var string Suffix of the checksum file written next to each package. */
    public const SIDECAR_SUFFIX = '.sha256';

    /** @var string */
    private $base;

    /**
     * Constructor.
     *
     * @param string|null $dataroot Defaults to $CFG->dataroot.
     */
    public function __construct(?string $dataroot = null) {
        global $CFG;
        $this->base = rtrim(str_replace('\\', '/', $dataroot ?? $CFG->dataroot), '/') . '/' . self::BASE;
    }

    /**
     * Base directory.
     *
     * @return string
     */
    public function get_base(): string {
        return $this->base;
    }

    /**
     * Directory holding finished packages.
     *
     * @return string
     */
    public function get_packages_dir(): string {
        return $this->base . '/' . self::PACKAGES;
    }

    /**
     * Working directory of a job.
     *
     * @param int $jobid
     * @return string
     */
    public function get_work_dir(int $jobid): string {
        return $this->base . '/' . self::WORK . '/job-' . $jobid;
    }

    /**
     * Create the directory structure.
     *
     * @return void
     */
    public function prepare(): void {
        self::make_private_dir($this->base);
        self::make_private_dir($this->base . '/' . self::WORK);
        self::make_private_dir($this->get_packages_dir());
    }

    /**
     * Create a fresh, empty working directory for a job.
     *
     * @param int $jobid
     * @return string
     */
    public function create_work_dir(int $jobid): string {
        $this->prepare();
        $dir = $this->get_work_dir($jobid);
        if (file_exists($dir) || is_link($dir)) {
            $this->remove_tree($dir);
        }
        self::make_private_dir($dir);
        return $dir;
    }

    /**
     * Delete a job's working directory, if any.
     *
     * @param int $jobid
     * @return void
     */
    public function remove_work_dir(int $jobid): void {
        $dir = $this->get_work_dir($jobid);
        if (file_exists($dir) || is_link($dir)) {
            $this->remove_tree($dir);
        }
    }

    /**
     * Absolute path of a finished package.
     *
     * @param string $filename Base name as stored in the job record.
     * @return string
     * @throws invalid_path_exception For anything that is not a package name or escapes the directory.
     */
    public function get_package_path(string $filename): string {
        if (!layout::is_valid_filename($filename)) {
            throw new invalid_path_exception('invalidpackagename');
        }
        return path_validator::resolve_within($this->get_packages_dir(), $filename);
    }

    /**
     * Delete a package and its checksum sidecar.
     *
     * @param string $filename
     * @return void
     */
    public function remove_package(string $filename): void {
        if (!is_dir($this->get_packages_dir())) {
            return;
        }
        $path = $this->get_package_path($filename);
        foreach ([$path, $path . self::SIDECAR_SUFFIX] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Remove working directories and package files that no job owns.
     *
     * Must only be called while holding the backup lock.
     *
     * @param int[] $keepjobids Jobs whose working directory must stay.
     * @param string[] $keeppackages Package names that must stay.
     * @return int Number of items removed.
     */
    public function cleanup(array $keepjobids, array $keeppackages): int {
        $removed = 0;
        $workroot = $this->base . '/' . self::WORK;
        if (is_dir($workroot) && !is_link($workroot)) {
            foreach (scandir($workroot) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (preg_match('/^job-(\d+)$/', $name, $m) && in_array((int) $m[1], $keepjobids, true)) {
                    continue;
                }
                $this->remove_tree($workroot . '/' . $name);
                $removed++;
            }
        }
        $packages = $this->get_packages_dir();
        if (is_dir($packages) && !is_link($packages)) {
            foreach (scandir($packages) as $name) {
                $package = substr($name, -strlen(self::SIDECAR_SUFFIX)) === self::SIDECAR_SUFFIX
                    ? substr($name, 0, -strlen(self::SIDECAR_SUFFIX)) : $name;
                // Only touch files this plugin names; leave anything else alone.
                if (!layout::is_valid_filename($package) || in_array($package, $keeppackages, true)) {
                    continue;
                }
                @unlink($packages . '/' . $name);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Recursively delete a path inside the workspace without following symlinks.
     *
     * @param string $path
     * @return void
     */
    public function remove_tree(string $path): void {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = dirname($path);
        // The parent must resolve inside the workspace; the path itself may be a link, which is unlinked, not followed.
        if (!path_validator::is_within($this->base, $parent) || basename($path) === '..' || basename($path) === '.') {
            throw new invalid_path_exception('pathescapesbase');
        }
        $realparent = str_replace('\\', '/', realpath($parent));
        self::remove_recursive($realparent . '/' . basename($path));
    }

    /**
     * lstat-based recursive delete.
     *
     * @param string $path
     * @return void
     */
    private static function remove_recursive(string $path): void {
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0170000) === 0040000) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove_recursive($path . '/' . $name);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    /**
     * Create a directory readable only by the current user, refusing symlinks,
     * directories owned by someone else and (after tightening) broader modes.
     *
     * @param string $dir
     * @return void
     */
    public static function make_private_dir(string $dir): void {
        if (is_link($dir)) {
            throw new backup_exception('workspacesymlink', basename($dir));
        }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                throw new backup_exception('cannotcreatedir', basename($dir));
            }
        }
        if ($problem = self::ownership_problem($dir)) {
            throw new backup_exception('workspaceowner', $problem);
        }
        if (DIRECTORY_SEPARATOR === '/') {
            clearstatcache(true, $dir);
            if ((fileperms($dir) & 0777) !== 0700 && (!@chmod($dir, 0700) || (fileperms($dir) & 0777) !== 0700)) {
                throw new backup_exception('workspacemode', basename($dir));
            }
        }
        if (!is_writable($dir)) {
            throw new backup_exception('cannotcreatedir', basename($dir));
        }
    }

    /**
     * Why the current process must not use an existing workspace path, or null.
     *
     * @param string $path
     * @return \stdClass|null Language string parameters (dir, owner, user).
     */
    public static function ownership_problem(string $path): ?\stdClass {
        $current = os_identity::current();
        if ($current === null || !file_exists($path)) {
            return null;
        }
        $owner = os_identity::owner($path);
        if ($owner === null || $owner['uid'] === $current['uid']) {
            return null;
        }
        return (object) ['dir' => $path, 'owner' => $owner['name'], 'user' => $current['name']];
    }

    /**
     * Problem with the whole workspace for this process, for pre-flight checks.
     *
     * @return string|null
     */
    public function check(): ?string {
        foreach ([$this->base, $this->base . '/' . self::WORK, $this->get_packages_dir()] as $dir) {
            if (is_link($dir)) {
                return get_string('error:workspacesymlink', 'tool_moodleclone', basename($dir));
            }
            if ($problem = self::ownership_problem($dir)) {
                return get_string('error:workspaceowner', 'tool_moodleclone', $problem);
            }
        }
        $dir = $this->base;
        while (!file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return get_string('check:workspace_bad', 'tool_moodleclone');
        }
        return null;
    }
}
