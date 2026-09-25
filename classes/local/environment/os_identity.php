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
 * Which operating system user may create packages.
 *
 * Packages are created with mode 0600 in 0700 directories, so only the OS
 * user that created them can read them. The web server must be able to read
 * them (download) and cron must be able to clean them up, therefore every
 * worker (the scheduled task under cron, or cli/backup.php) must run as the
 * same OS user as the web server. Permissions are never widened to paper
 * over a mismatch.
 *
 * The web server's identity is recorded whenever an administrator opens the
 * Moodle Clone page or queues a backup (only a web request can know it).
 * Before that has happened, the owner of the plugin's workspace (or of
 * dataroot) is used, unless it is root. Where the posix extension is not
 * available (e.g. Windows) the check cannot be made and is skipped.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class os_identity {

    /**
     * Whether OS users can be determined here.
     *
     * @return bool
     */
    public static function is_supported(): bool {
        return function_exists('posix_geteuid') && function_exists('posix_getpwuid') && DIRECTORY_SEPARATOR === '/';
    }

    /**
     * The effective OS user of this process.
     *
     * @return array|null ['uid' => int, 'name' => string]
     */
    public static function current(): ?array {
        if (!self::is_supported()) {
            return null;
        }
        $uid = posix_geteuid();
        return ['uid' => $uid, 'name' => self::name($uid)];
    }

    /**
     * User name for a uid (the number itself when unknown).
     *
     * @param int $uid
     * @return string
     */
    public static function name(int $uid): string {
        $info = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        return $info && !empty($info['name']) ? (string) $info['name'] : (string) $uid;
    }

    /**
     * Owner of a path.
     *
     * @param string $path
     * @return array|null ['uid' => int, 'name' => string]
     */
    public static function owner(string $path): ?array {
        if (!self::is_supported()) {
            return null;
        }
        clearstatcache(true, $path);
        $uid = @fileowner($path);
        return $uid === false ? null : ['uid' => (int) $uid, 'name' => self::name((int) $uid)];
    }

    /**
     * Remember the web server's OS user. Does nothing outside web requests.
     *
     * @return void
     */
    public static function record_web_identity(): void {
        if ((defined('CLI_SCRIPT') && CLI_SCRIPT) || !($current = self::current())) {
            return;
        }
        if ((string) get_config('tool_moodleclone', 'webuid') !== (string) $current['uid']) {
            set_config('webuid', $current['uid'], 'tool_moodleclone');
        }
        if ((string) get_config('tool_moodleclone', 'webuser') !== $current['name']) {
            set_config('webuser', $current['name'], 'tool_moodleclone');
        }
    }

    /**
     * The recorded web server user.
     *
     * @return array|null ['uid' => int, 'name' => string]
     */
    public static function get_web_identity(): ?array {
        $uid = get_config('tool_moodleclone', 'webuid');
        if ($uid === false || $uid === '' || $uid === null) {
            return null;
        }
        $name = get_config('tool_moodleclone', 'webuser');
        return ['uid' => (int) $uid, 'name' => $name ? (string) $name : (string) $uid];
    }

    /**
     * The OS user a worker must run as.
     *
     * @param string|null $workspacebase Defaults to dataroot/moodleclone.
     * @return array|null ['uid', 'name', 'source' => 'web'|'owner'], or null when unknown.
     */
    public static function expected_worker(?string $workspacebase = null): ?array {
        global $CFG;
        if (!self::is_supported()) {
            return null;
        }
        if ($web = self::get_web_identity()) {
            return $web + ['source' => 'web'];
        }
        foreach ([$workspacebase ?? $CFG->dataroot . '/moodleclone', $CFG->dataroot] as $path) {
            if (file_exists($path) && ($owner = self::owner($path)) && $owner['uid'] !== 0) {
                return $owner + ['source' => 'owner'];
            }
        }
        return null;
    }

    /**
     * Why this process must not run backups, or null when it may.
     *
     * @param string|null $workspacebase
     * @return string|null Human readable problem.
     */
    public static function worker_problem(?string $workspacebase = null): ?string {
        global $CFG;
        $current = self::current();
        if ($current === null) {
            return null;
        }
        $command = 'sudo -u %s php ' . $CFG->dirroot . '/admin/tool/moodleclone/cli/backup.php --execute';
        if ($current['uid'] === 0) {
            $expected = self::expected_worker($workspacebase);
            return get_string('identity:root', 'tool_moodleclone', sprintf($command, $expected['name'] ?? 'www-data'));
        }
        $expected = self::expected_worker($workspacebase);
        if ($expected === null || $expected['uid'] === $current['uid']) {
            return null;
        }
        return get_string('identity:mismatch', 'tool_moodleclone', (object) [
            'current' => $current['name'],
            'expected' => $expected['name'],
            'source' => get_string('identity:source_' . $expected['source'], 'tool_moodleclone'),
            'command' => sprintf($command, $expected['name']),
        ]);
    }
}
