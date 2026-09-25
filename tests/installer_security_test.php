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

// phpcs:disable moodle.Strings.ForbiddenStrings.Found -- The SQL under test is MySQL, which quotes identifiers with backticks.

use MoodleCloneInstaller\installer_exception;
use MoodleCloneInstaller\manifest_check;
use MoodleCloneInstaller\moodle_steps;
use MoodleCloneInstaller\package;
use MoodleCloneInstaller\paths;
use MoodleCloneInstaller\sql_guard;
use MoodleCloneInstaller\sql_reader;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/installer_fixture.php');

/**
 * The Phase 3 security tests of the standalone installer: paths, hostile packages, the SQL allow-list, the SQL reader,
 * the manifest check and serialized-aware URL rewriting. They must keep passing whatever changes around authorization.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \MoodleCloneInstaller\paths
 * @covers     \MoodleCloneInstaller\package
 * @covers     \MoodleCloneInstaller\sql_guard
 * @covers     \MoodleCloneInstaller\sql_reader
 * @covers     \MoodleCloneInstaller\manifest_check
 * @covers     \MoodleCloneInstaller\moodle_steps
 */
class installer_security_test extends \basic_testcase {
    /** @var string */
    private $dir;

    protected function setUp(): void {
        installer_fixture::load();
        $this->dir = installer_fixture::make_dir();
    }

    // Paths.

    public function test_safe_and_unsafe_relative_paths(): void {
        foreach (['moodle/index.php', 'moodledata/filedir/aa/bb/x', "moodle/\u{00fc}n\u{00ef}.txt"] as $path) {
            $this->assertTrue(paths::is_safe_relative($path), $path);
        }
        foreach (['', '../x', 'a/../b', '/etc/passwd', 'C:/x', 'a\\b', "a\0b", "a\nb", 'a//b', 'a/', "bad\xff"] as $path) {
            $this->assertFalse(paths::is_safe_relative($path), json_encode($path));
        }
    }

    public function test_absolute_paths(): void {
        $this->assertSame('/var/moodle/data', paths::normalise_absolute('/var/www/../moodle//data/'));
        $this->assertNull(paths::normalise_absolute('relative/x'));
        $this->assertNull(paths::normalise_absolute('/..'));
        $this->assertTrue(paths::is_inside('/a/b/c', '/a/b'));
        $this->assertFalse(paths::is_inside('/a/bc', '/a/b'));
        $this->assertTrue(paths::is_inside('/a/b', '/a/b'));
    }

    // Hostile packages: scan() must refuse each, and accept a good one.

    /**
     * Build a package from the fixture and run the installer's package scan on it.
     *
     * @param array $options installer_fixture::build_package() options.
     * @return array Package information.
     */
    private function scan(array $options = []): array {
        $path = $this->dir . '/moodle-clone-2026-09-24-113503.zip';
        installer_fixture::build_package($path, $options);
        return package::scan($path, ['installer.php', 'moodleclone-installer-key.php', 'moodleclone-installer-auth.php']);
    }

    public function test_a_valid_package_is_accepted(): void {
        foreach ([3, 2] as $format) {
            $info = $this->scan(['format' => $format]);
            $this->assertSame($format, $info['manifest']['format']);
            $this->assertSame(3, $info['counts']['moodle']['files']);
            $this->assertTrue($info['hashtaccess']);
        }
    }

    /**
     * Package options that must be refused, with the message each refusal must contain.
     *
     * @return array
     */
    public static function hostile_package_provider(): array {
        return [
            'path traversal' => [['extra' => ['moodle/../../evil.php' => 'x']], 'Unsafe entry name'],
            'traversal at the start' => [['extra' => ['../evil.php' => 'x']], 'Unsafe entry name'],
            'absolute path' => [['extra' => ['/etc/cron.d/evil' => 'x']], 'Unsafe entry name'],
            'backslash' => [['extra' => ['moodle\\evil.php' => 'x']], 'Unsafe entry name'],
            'symbolic link' => [['links' => ['moodle/link' => '/etc/passwd']], 'symbolic link'],
            'config.php' => [['extra' => ['moodle/config.php' => '<?php // source config']], 'config.php'],
            'overwriting the installer' => [['extra' => ['moodle/installer.php' => '<?php // evil']], 'overwrite the installer'],
            'overwriting the auth state' => [
                ['extra' => ['moodle/moodleclone-installer-auth.php' => '{}']],
                'overwrite the installer',
            ],
            'a file at the top level' => [['extra' => ['evil.php' => 'x']], 'Unexpected entry'],
            'tampered manifest counts' => [['manifest' => function (array $m) {
                $m['statistics']['moodle']['files'] = 999;
                return $m;
            }], 'does not match manifest.json'],
            'a checksum line missing' => [['checksums' => function (array $lines) {
                array_pop($lines);
                return $lines;
            }], 'checksums.sha256'],
            'checksum lines in the wrong order' => [['checksums' => function (array $lines) {
                return array_reverse($lines);
            }], 'checksums.sha256'],
            'a checksum for something not in the archive' => [['checksums' => function (array $lines) {
                $lines[] = str_repeat('a', 64) . '  moodle/ghost.php';
                return $lines;
            }], 'checksums.sha256'],
            'a secret in the manifest' => [['manifest' => function (array $m) {
                $m['generator']['secret'] = 'x';
                return $m;
            }], 'forbidden key'],
            'a partial package' => [['manifest' => function (array $m) {
                $m['package_contents']['database'] = false;
                return $m;
            }], 'only complete packages'],
            'a truncated file' => [['truncate' => 250], 'not a readable ZIP'],
        ];
    }

    /**
     * A hostile package is refused with the expected message.
     *
     * @dataProvider hostile_package_provider
     * @param array $options
     * @param string $message
     */
    public function test_hostile_packages_are_refused(array $options, string $message): void {
        try {
            $this->scan($options);
            $this->fail('the package was accepted');
        } catch (installer_exception $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    // The .sha256 file next to the package.

    public function test_the_sidecar_checksum_file(): void {
        $package = $this->dir . '/moodle-clone-2026-09-24-113503.zip';
        file_put_contents($package, 'x');
        $this->assertNull(package::sidecar_hash($package), 'no file, no checksum');

        $hash = hash('sha256', 'x');
        file_put_contents($package . '.sha256', $hash . '  ' . basename($package) . "\n");
        $this->assertSame($hash, package::sidecar_hash($package));

        file_put_contents($package . '.sha256', $hash . '  moodle-clone-2026-01-01-000000.zip' . "\n");
        try {
            package::sidecar_hash($package);
            $this->fail('a checksum file for another package was accepted');
        } catch (installer_exception $e) {
            $this->assertStringContainsString('not valid', $e->getMessage());
        }

        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            // A file the web server cannot read is not a "bad" file, and the message must say what to fix.
            file_put_contents($package . '.sha256', $hash . '  ' . basename($package) . "\n");
            chmod($package . '.sha256', 0000);
            try {
                package::sidecar_hash($package);
                $this->fail('an unreadable checksum file was accepted');
            } catch (installer_exception $e) {
                $this->assertStringContainsString('cannot be read by the web server', $e->getMessage());
            } finally {
                chmod($package . '.sha256', 0600);
            }
        }
    }

    // The SQL allow-list.

    public function test_the_guard_accepts_what_the_dumper_writes(): void {
        $guard = new sql_guard('mdl_');
        $this->assertSame('set', $guard->check('SET NAMES utf8mb4')['type']);
        $this->assertSame('set', $guard->check("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'")['type']);
        $this->assertSame('mdl_user', $guard->check('DROP TABLE IF EXISTS `mdl_user`')['table']);
        $this->assertSame('create', $guard->check(
            "CREATE TABLE `mdl_user` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) " .
            "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED COMMENT='a;b'"
        )['type']);
        $insert = $guard->check(
            "INSERT INTO `mdl_user` (`id`,`name`,`data`,`amount`) VALUES (1,_utf8mb4 X'6162',X'00ff',-1.5e-7),(2,NULL,X'',0)"
        );
        $this->assertSame('insert', $insert['type']);
        $this->assertSame(2, $insert['rows']);
        $this->assertSame(
            1,
            $guard->check("INSERT INTO `mdl_x` (`a`) VALUES (_utf8mb4 X'" . str_repeat('ab', 3000000) . "')")['rows'],
            'a 6 MB literal (no PCRE limits)'
        );
    }

    /**
     * SQL statements that the guard must refuse.
     *
     * @return array
     */
    public static function hostile_sql_provider(): array {
        return [
            'drop database' => ['DROP DATABASE school'],
            'function call in values' => ["INSERT INTO `mdl_user` (`id`) VALUES (LOAD_FILE('/etc/passwd'))"],
            'quoted string value' => ["INSERT INTO `mdl_user` (`id`) VALUES ('plain text')"],
            'trailing clause' => ["INSERT INTO `mdl_user` (`id`) VALUES (1) ON DUPLICATE KEY UPDATE id=2"],
            'stacked statement' => ["INSERT INTO `mdl_user` (`id`) VALUES (1),(2)); DROP TABLE x"],
            'another prefix' => ["INSERT INTO `other_user` (`id`) VALUES (1)"],
            'select into outfile' => ["SELECT * FROM mysql.user INTO OUTFILE '/tmp/x'"],
            'data directory' => ["CREATE TABLE `mdl_x` (\n  `id` int\n) ENGINE=InnoDB DATA DIRECTORY='/tmp'"],
            'another engine' => ["CREATE TABLE `mdl_x` (\n  `id` int\n) ENGINE=MyISAM"],
            'grant' => ["GRANT ALL ON *.* TO 'x'@'%'"],
            'set global' => ['SET GLOBAL general_log = 1'],
            'set with a stacked statement' => ['SET NAMES utf8mb4; DROP TABLE x'],
            'create select' => ['CREATE TABLE `mdl_x` SELECT * FROM mysql.user'],
        ];
    }

    /**
     * The guard refuses everything except the dump's own statements.
     *
     * @dataProvider hostile_sql_provider
     * @param string $sql
     */
    public function test_the_guard_refuses_everything_else(string $sql): void {
        $this->expectException(installer_exception::class);
        (new sql_guard('mdl_'))->check($sql);
    }

    // The SQL reader.

    public function test_the_reader_splits_on_semicolons_outside_quotes_and_resumes(): void {
        $file = $this->dir . '/dump.sql';
        file_put_contents(
            $file,
            "-- header\nSET NAMES utf8mb4;\n\n-- Table: `mdl_a`\nCREATE TABLE `mdl_a` (\n  `id` int COMMENT 'x;\ny',\n" .
            "  `b` text\n) ENGINE=InnoDB COMMENT='semi;colon';\nINSERT INTO `mdl_a` (`id`) VALUES (1);\n" .
            "-- Moodle Clone dump completed: 1 tables, 1 rows\n"
        );
        $reader = new sql_reader($file, 0);
        $out = [];
        while (($statement = $reader->next()) !== null) {
            $out[] = $statement;
        }
        $this->assertCount(6, $out);
        $this->assertStringContainsString("COMMENT 'x;\ny'", $out[3]);
        $this->assertSame("COMMENT='semi;colon'", substr($out[3], -20));

        $first = new sql_reader($file, 0);
        $first->next();
        $first->next();
        $this->assertSame('-- Table: `mdl_a`', (new sql_reader($file, $first->offset()))->next());
    }

    public function test_the_reader_refuses_a_truncated_statement(): void {
        $file = $this->dir . '/dump.sql';
        file_put_contents($file, "INSERT INTO `mdl_a` (`id`) VALUES (1)\n");
        $this->expectException(installer_exception::class);
        (new sql_reader($file, 0))->next();
    }

    // Serialized-aware URL rewriting (never a blind REPLACE).

    public function test_urls_are_rewritten_safely(): void {
        $old = 'http://school.local.com';
        $new = 'http://clone-test.local.com';
        $this->assertSame(
            "<a href=\"{$new}/course/view.php?id=2\">x</a>",
            moodle_steps::replace_value("<a href=\"{$old}/course/view.php?id=2\">x</a>", $old, $new)
        );
        $result = moodle_steps::replace_value(
            serialize(['url' => "{$old}/mod/page", 'n' => 5, 'nested' => ["{$old}/x" => 'k']]),
            $old,
            $new
        );
        $this->assertSame(
            ['url' => "{$new}/mod/page", 'n' => 5, 'nested' => ["{$new}/x" => 'k']],
            unserialize($result),
            'serialized string lengths are fixed'
        );
        $this->assertNull(
            moodle_steps::replace_value(serialize((object) ['u' => "{$old}/x"]), $old, $new),
            'objects are left alone'
        );
        $this->assertSame('{"u":"http:\/\/clone-test.local.com\/x"}', moodle_steps::replace_value(
            '{"u":"http:\/\/school.local.com\/x"}',
            'http:\/\/school.local.com',
            'http:\/\/clone-test.local.com'
        ));
        $this->assertSame("s:not serialized {$new}", moodle_steps::replace_value("s:not serialized {$old}", $old, $new));
    }

    // The manifest check.

    public function test_the_manifest_check(): void {
        $dir = $this->dir;
        $path = $dir . '/m.zip';
        installer_fixture::build_package($path);
        $zip = new \ZipArchive();
        $zip->open($path);
        $valid = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame([], manifest_check::validate($valid));
        $bad = $valid;
        $bad['database_type'] = "mysqli'; system('id'); //";
        $this->assertNotEmpty(manifest_check::validate($bad), 'an odd dbtype');
        $bad = $valid;
        $bad['package_contents']['database'] = false;
        $this->assertNotEmpty(manifest_check::validate($bad), 'a partial package');
        $bad = $valid;
        $bad['generator']['secret'] = 'x';
        $this->assertNotEmpty(manifest_check::validate($bad), 'a secret-looking key');
        $bad = $valid;
        $bad['format'] = 1;
        $this->assertNotEmpty(manifest_check::validate($bad), 'format 1');
        $bad = $valid;
        $bad['other'] = ['salt' => 'x'];
        $this->assertNotEmpty(manifest_check::validate($bad), 'a salt outside installer_auth');
        $bad = $valid;
        $bad['installer_auth'] = [
            'mode' => 'password',
            'kdf' => 'pbkdf2-sha256',
            'iterations' => 600000,
            'salt' => 'x',
            'verifier' => 'y',
        ];
        $this->assertNotEmpty(manifest_check::validate($bad), 'a malformed verifier');
    }
}
