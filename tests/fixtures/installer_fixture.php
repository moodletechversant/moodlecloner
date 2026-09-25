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

namespace tool_moodleclone;

// phpcs:disable moodle.Strings.ForbiddenStrings.Found -- The fixture dump is MySQL, which quotes identifiers with backticks.

/**
 * Loads the standalone installer and builds packages for it, for tests.
 *
 * The installer refuses to run inside the plugin tree (it is a template), so
 * tests load a copy from a scratch directory, with its main() disabled.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class installer_fixture {
    /** @var string Where the installer copy lives. */
    private static $dir = null;

    /**
     * Load the installer's classes (once per process) and return the scratch directory holding the copy.
     *
     * @return string
     */
    public static function load(): string {
        if (self::$dir === null) {
            self::$dir = sys_get_temp_dir() . '/mci-installer-tests-' . getmypid();
            @mkdir(self::$dir, 0700, true);
            copy(__DIR__ . '/../../installer/installer.php', self::$dir . '/installer.php');
            define('MOODLECLONE_INSTALLER_NO_MAIN', true);
            require_once(self::$dir . '/installer.php');
            register_shutdown_function(function () {
                self::remove_tree(self::$dir);
            });
        }
        return self::$dir;
    }

    /**
     * A fresh scratch directory.
     *
     * @return string
     */
    public static function make_dir(): string {
        $dir = sys_get_temp_dir() . '/mci-fixture-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        register_shutdown_function(function () use ($dir) {
            self::remove_tree($dir);
        });
        return $dir;
    }

    /**
     * Delete a directory tree.
     *
     * @param string $path
     * @return void
     */
    public static function remove_tree(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove_tree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    /**
     * Build a small, valid clone package.
     *
     * Options:
     * - format: 2 or 3 (default 3)
     * - installer_auth: the manifest's installer_auth array (default: key file); ignored for format 2
     * - extra: name => content entries added to the archive after the code (hostile names are the point)
     * - links: name => target, stored as Unix symbolic links
     * - manifest: callable(array): array changing the manifest
     * - checksums: callable(string[] lines): string[] changing the checksum lines
     * - truncate: cut the finished file to this many bytes
     *
     * @param string $path Where to write the zip.
     * @param array $options
     * @return void
     */
    public static function build_package(string $path, array $options = []): void {
        $format = $options['format'] ?? 3;
        $files = [
            'moodle/index.php' => "<?php echo 'index';\n",
            'moodle/lib/setup.php' => "<?php // setup\n",
            'moodle/.htaccess' => "Options -Indexes\n",
        ];
        $data = ['moodledata/lang/readme.txt' => 'data'];
        $sql = "-- Moodle Clone dump\nSET NAMES utf8mb4;\nDROP TABLE IF EXISTS `mdl_config`;\nCREATE TABLE `mdl_config` (\n" .
            "  `id` bigint NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB;\n" .
            "INSERT INTO `mdl_config` (`id`) VALUES (1);\n-- Moodle Clone dump completed: 1 tables, 1 rows\n";

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $listed = [];
        $add = function (string $name, string $content) use ($zip, &$listed) {
            $zip->addFromString($name, $content);
            $listed[$name] = hash('sha256', $content);
        };
        foreach ($files as $name => $content) {
            $add($name, $content);
        }
        $add('database.sql.gz', gzencode($sql));
        foreach ($data as $name => $content) {
            $add($name, $content);
        }
        $moodlefiles = count($files);
        foreach (($options['extra'] ?? []) as $name => $content) {
            $add($name, $content);
            if (strpos($name, 'moodle/') === 0) {
                $moodlefiles++;
            }
        }
        foreach (($options['links'] ?? []) as $name => $target) {
            $zip->addFromString($name, $target);
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, (0120777 << 16));
            $listed[$name] = hash('sha256', $target);
        }

        $manifest = [
            'format' => $format,
            'product' => 'moodle-clone',
            'created' => '2026-09-24T11:35:03Z',
            'generator' => ['component' => 'tool_moodleclone', 'version' => 2026092403, 'release' => '0.3.0 (Phase 3)'],
            'moodle_version' => '2022112800.00',
            'moodle_release' => '4.1 (Build: 20221128)',
            'moodle_branch' => '401',
            'php_version' => '8.1.34',
            'database_type' => 'mysqli',
            'database_family' => 'mysql',
            'database_version' => '8.0.46',
            'wwwroot' => 'http://school.local.com',
            'dirroot' => '/var/www/html/school',
            'dataroot' => '/var/www/moodle/schooldata',
            'table_prefix' => 'mdl_',
            'package_contents' => ['moodle' => true, 'moodledata' => true, 'database' => true],
            'statistics' => [
                'moodle' => ['files' => $moodlefiles, 'directories' => 0, 'symlinks' => 0, 'bytes' => 100],
                'moodledata' => ['files' => count($data), 'directories' => 0, 'symlinks' => 0, 'bytes' => 4,
                    'recovered_from_trash' => 0],
                'database' => ['tables' => 1, 'rows' => 1],
            ],
            'database_dump' => ['format' => 'mysql', 'format_version' => 1, 'compression' => 'gzip', 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'consistency' => 'snapshot', 'max_statement_bytes' => 100],
            'moodledata_excluded' => [],
        ];
        if ($format === 3) {
            $manifest['installer_auth'] = $options['installer_auth'] ?? ['mode' => 'keyfile'];
        }
        if (isset($options['manifest'])) {
            $manifest = $options['manifest']($manifest);
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $add('manifest.json', $json);

        $lines = [];
        foreach ($listed as $name => $hash) {
            $lines[] = $hash . '  ' . $name;
        }
        if (isset($options['checksums'])) {
            $lines = $options['checksums']($lines);
        }
        $zip->addFromString('checksums.sha256', implode("\n", $lines) . "\n");
        $zip->close();
        if (isset($options['truncate'])) {
            $bytes = (string) file_get_contents($path);
            file_put_contents($path, substr($bytes, 0, (int) $options['truncate']));
        }
    }
}
