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

use tool_moodleclone\local\environment\snapshot;

/**
 * Tests for the manifest and its validator.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\manifest
 * @covers     \tool_moodleclone\local\package\manifest_validator
 */
class manifest_test extends \basic_testcase {

    /**
     * A snapshot with known values.
     *
     * @return snapshot
     */
    private function make_snapshot(): snapshot {
        $s = new snapshot();
        $s->moodleversion = '2022112800.00';
        $s->moodlerelease = '4.1 (Build: 20221128)';
        $s->moodlebranch = '401';
        $s->phpversion = '8.1.2';
        $s->dbtype = 'mysqli';
        $s->dbfamily = 'mysql';
        $s->dbversion = '8.0.36';
        $s->dbname = 'moodle';
        $s->dbuser = 'moodleuser';
        $s->prefix = 'mdl_';
        $s->wwwroot = 'https://lms.example.com';
        $s->dirroot = '/var/www/moodle';
        $s->dataroot = '/var/moodledata';
        $s->os = 'Linux 6.8';
        $s->freediskspace = 1000;
        $s->extensions = ['zip' => true];
        $s->timecollected = 0;
        return $s;
    }

    /**
     * Statistics for all components.
     *
     * @return array
     */
    private function stats(): array {
        return [
            'moodle' => ['files' => 10, 'directories' => 2, 'symlinks' => 1, 'bytes' => 1000],
            'moodledata' => ['files' => 5, 'directories' => 3, 'symlinks' => 0, 'bytes' => 500, 'recovered_from_trash' => 0],
            'database' => ['tables' => 450, 'rows' => 12345],
        ];
    }

    /**
     * Database dump description.
     *
     * @return array
     */
    private function dump(): array {
        return ['format' => 'mysql', 'format_version' => 1, 'compression' => 'gzip', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'consistency' => 'snapshot', 'max_statement_bytes' => 1048576];
    }

    /**
     * Build a manifest.
     *
     * @param array $contents
     * @param int $created
     * @return manifest
     */
    private function build(array $contents = ['moodle' => true, 'moodledata' => true, 'database' => true],
            int $created = 1790000000): manifest {
        $stats = $this->stats();
        foreach ($contents as $key => $included) {
            if (!$included) {
                $stats[$key] = null;
            }
        }
        return manifest::from_snapshot($this->make_snapshot(), $contents, $created,
            ['version' => 2026092401, 'release' => '0.2.0 (Phase 2)'], $stats,
            $contents['database'] ? $this->dump() : null, ['cache', 'sessions']);
    }

    /**
     * A valid manifest array.
     *
     * @return array
     */
    private function valid_data(): array {
        return $this->build()->to_array();
    }

    public function test_from_snapshot_maps_fields(): void {
        $manifest = $this->build(['moodle' => true, 'moodledata' => false, 'database' => true]);

        $this->assertSame(3, $manifest->get('format'));
        $this->assertSame('moodle-clone', $manifest->get('product'));
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1790000000), $manifest->get('created'));
        $this->assertSame(['component' => 'tool_moodleclone', 'version' => 2026092401, 'release' => '0.2.0 (Phase 2)'],
            $manifest->get('generator'));
        $this->assertSame('2022112800.00', $manifest->get('moodle_version'));
        $this->assertSame('mysqli', $manifest->get('database_type'));
        $this->assertSame('mysql', $manifest->get('database_family'));
        $this->assertSame('8.0.36', $manifest->get('database_version'));
        $this->assertSame('https://lms.example.com', $manifest->get('wwwroot'));
        $this->assertSame('/var/moodledata', $manifest->get('dataroot'));
        $this->assertSame('mdl_', $manifest->get('table_prefix'));
        $this->assertTrue($manifest->includes(manifest::CONTENT_MOODLE));
        $this->assertFalse($manifest->includes(manifest::CONTENT_MOODLEDATA));
        $this->assertTrue($manifest->includes(manifest::CONTENT_DATABASE));
        $this->assertNull($manifest->get('statistics')['moodledata']);
        $this->assertSame(450, $manifest->get('statistics')['database']['tables']);
        $this->assertSame('snapshot', $manifest->get('database_dump')['consistency']);
        $this->assertSame(['cache', 'sessions'], $manifest->get('moodledata_excluded'));
    }

    public function test_installer_auth_defaults_to_the_key_file_and_carries_a_password_verifier(): void {
        $this->assertSame(['mode' => 'keyfile'], $this->build()->get('installer_auth'));
        $this->assertFalse($this->build()->get_installer_auth()->is_password());

        $auth = installer_auth::from_password('a long installer passphrase');
        $manifest = manifest::from_snapshot($this->make_snapshot(), ['moodle' => true, 'moodledata' => true, 'database' => true],
            1790000000, ['version' => 2026092403, 'release' => '0.3.1'], $this->stats(), $this->dump(), [], $auth);
        $this->assertSame($auth->to_array(), $manifest->get('installer_auth'));
        $this->assertTrue($manifest->get_installer_auth()->verify('a long installer passphrase'));
        // The verifier is public offline-verification data, so the generic secret-looking-key scan skips exactly this object.
        $this->assertSame([], manifest_validator::find_forbidden_keys($manifest->to_array()));
        $this->assertSame(['generator.api_key'], manifest_validator::find_forbidden_keys(
            ['installer_auth' => ['salt' => 'x'], 'generator' => ['api_key' => 'x']]));
        $this->assertSame(['other.salt'], manifest_validator::find_forbidden_keys(['other' => ['salt' => 'x']]));
        $this->assertStringNotContainsString('a long installer passphrase', $manifest->to_json());
    }

    public function test_format_2_manifests_without_installer_auth_are_still_valid(): void {
        $data = $this->valid_data();
        $data['format'] = 2;
        unset($data['installer_auth']);
        $manifest = manifest::from_array($data);
        $this->assertSame(2, $manifest->get('format'));
        $this->assertSame(installer_auth::MODE_KEYFILE, $manifest->get_installer_auth()->get_mode());

        // Format 2 is closed: installer_auth belongs to format 3.
        $data['installer_auth'] = ['mode' => 'keyfile'];
        try {
            manifest::from_array($data);
            $this->fail('installer_auth accepted in format 2');
        } catch (invalid_package_exception $e) {
            $this->assertStringContainsString("unknown key 'installer_auth'", implode(';', $e->errors));
        }
    }

    public function test_manifest_does_not_contain_credentials(): void {
        $json = $this->build()->to_json();
        $this->assertStringNotContainsString('moodleuser', $json);
        $this->assertStringNotContainsString('dbname', $json);
        $this->assertSame([], manifest_validator::find_forbidden_keys(json_decode($json, true)));
    }

    public function test_json_round_trip(): void {
        $original = manifest::from_array($this->valid_data());
        $json = $original->to_json();
        $this->assertStringContainsString('"wwwroot": "https://lms.example.com"', $json, 'Slashes must not be escaped');
        $this->assertSame($original->to_array(), manifest::from_json($json)->to_array());
    }

    public function test_null_database_version_is_allowed(): void {
        $data = $this->valid_data();
        $data['database_version'] = null;
        $this->assertNull(manifest::from_array($data)->get('database_version'));
    }

    public function test_from_json_rejects_non_object(): void {
        $this->expectException(invalid_package_exception::class);
        manifest::from_json('"just a string"');
    }

    public function test_from_json_rejects_malformed_json(): void {
        $this->expectException(invalid_package_exception::class);
        manifest::from_json('{"format": 1,');
    }

    public function test_get_unknown_field_is_coding_error(): void {
        $this->expectException(\coding_exception::class);
        manifest::from_array($this->valid_data())->get('dbpass');
    }

    /**
     * Mutations that each make a valid manifest invalid.
     *
     * @return array
     */
    public function invalid_manifest_provider(): array {
        return [
            'missing field' => [function(array $d) {
                unset($d['wwwroot']);
                return $d;
            }, "missing key 'wwwroot'"],
            'unknown field' => [function(array $d) {
                $d['extra'] = 1;
                return $d;
            }, "unknown key 'extra'"],
            'password at top level' => [function(array $d) {
                $d['dbpass'] = 'x';
                return $d;
            }, "forbidden key 'dbpass'"],
            'secret nested' => [function(array $d) {
                $d['generator']['api_key'] = 'x';
                return $d;
            }, "forbidden key 'generator.api_key'"],
            'wrong type' => [function(array $d) {
                $d['format'] = '1';
                return $d;
            }, "'format' must be of type int"],
            'future format' => [function(array $d) {
                $d['format'] = 4;
                return $d;
            }, 'unsupported format 4'],
            'installer_auth missing in format 3' => [function(array $d) {
                unset($d['installer_auth']);
                return $d;
            }, "missing key 'installer_auth'"],
            'installer_auth with an unknown key' => [function(array $d) {
                $d['installer_auth']['plaintext'] = 'x';
                return $d;
            }, "unknown key 'installer_auth.plaintext'"],
            'installer_auth keyfile with a verifier' => [function(array $d) {
                $d['installer_auth'] = ['mode' => 'keyfile', 'verifier' => 'x'];
                return $d;
            }, "unknown key 'installer_auth.verifier'"],
            'installer_auth unknown mode' => [function(array $d) {
                $d['installer_auth']['mode'] = 'none';
                return $d;
            }, 'installer_auth.mode must be one of'],
            'installer_auth iterations too low' => [function(array $d) {
                $d['installer_auth'] = ['mode' => 'password', 'kdf' => 'pbkdf2-sha256', 'iterations' => 1,
                    'salt' => base64_encode(str_repeat('a', 16)), 'verifier' => base64_encode(str_repeat('b', 32))];
                return $d;
            }, 'installer_auth.iterations must be an integer between'],
            'phase 1 draft format' => [function(array $d) {
                $d['format'] = 1;
                return $d;
            }, 'unsupported format 1'],
            'statistics for excluded component' => [function(array $d) {
                $d['package_contents']['moodledata'] = false;
                return $d;
            }, 'statistics.moodledata must be null'],
            'statistics missing' => [function(array $d) {
                $d['statistics']['moodle'] = null;
                return $d;
            }, 'statistics.moodle is required'],
            'negative statistic' => [function(array $d) {
                $d['statistics']['moodle']['bytes'] = -1;
                return $d;
            }, 'must not be negative'],
            'dump missing' => [function(array $d) {
                $d['database_dump'] = null;
                return $d;
            }, "'database_dump' is required"],
            'dump without database' => [function(array $d) {
                $d['package_contents']['database'] = false;
                $d['statistics']['database'] = null;
                return $d;
            }, "'database_dump' must be null"],
            'dump claims no consistency' => [function(array $d) {
                $d['database_dump']['consistency'] = 'none';
                return $d;
            }, 'consistency'],
            'secret inside dump object' => [function(array $d) {
                $d['database_dump']['dbpassword'] = 'x';
                return $d;
            }, "forbidden key 'database_dump.dbpassword'"],
            'excluded path traversal' => [function(array $d) {
                $d['moodledata_excluded'][] = '../etc';
                return $d;
            }, 'moodledata_excluded'],
            'wrong product' => [function(array $d) {
                $d['product'] = 'duplicator';
                return $d;
            }, "'product' must be"],
            'bad date' => [function(array $d) {
                $d['created'] = '2026-13-45T00:00:00Z';
                return $d;
            }, "'created' must be"],
            'wrong generator' => [function(array $d) {
                $d['generator']['component'] = 'tool_other';
                return $d;
            }, "'generator' must"],
            'bad moodle version' => [function(array $d) {
                $d['moodle_version'] = '4.1';
                return $d;
            }, "'moodle_version'"],
            'unknown db family' => [function(array $d) {
                $d['database_family'] = 'sqlite';
                return $d;
            }, "'database_family'"],
            'wwwroot trailing slash' => [function(array $d) {
                $d['wwwroot'] = 'https://lms.example.com/';
                return $d;
            }, "'wwwroot'"],
            'wwwroot not http' => [function(array $d) {
                $d['wwwroot'] = 'file:///etc/passwd';
                return $d;
            }, "'wwwroot'"],
            'relative dataroot' => [function(array $d) {
                $d['dataroot'] = '../moodledata';
                return $d;
            }, "'dataroot' must be an absolute path"],
            'prefix injection' => [function(array $d) {
                $d['table_prefix'] = 'mdl_; DROP TABLE x';
                return $d;
            }, "'table_prefix'"],
            'contents not boolean' => [function(array $d) {
                $d['package_contents']['database'] = 'yes';
                return $d;
            }, 'package_contents.database must be a boolean'],
            'contents missing key' => [function(array $d) {
                unset($d['package_contents']['moodle']);
                return $d;
            }, 'package_contents.moodle must be a boolean'],
            'contents empty selection' => [function(array $d) {
                $d['package_contents'] = ['moodle' => false, 'moodledata' => false, 'database' => false];
                return $d;
            }, 'at least one component'],
        ];
    }

    /**
     * Each invalid manifest is rejected with a specific error.
     *
     * @dataProvider invalid_manifest_provider
     * @param callable $mutate
     * @param string $expectederror Substring expected in one of the errors.
     */
    public function test_invalid_manifest_rejected(callable $mutate, string $expectederror): void {
        $data = $mutate($this->valid_data());

        $errors = manifest_validator::validate($data);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($expectederror, implode("\n", $errors));

        try {
            manifest::from_array($data);
            $this->fail('Invalid manifest was accepted');
        } catch (invalid_package_exception $e) {
            $this->assertSame($errors, $e->errors);
        }
    }

    public function test_valid_manifest_has_no_errors(): void {
        $this->assertSame([], manifest_validator::validate($this->valid_data()));
    }
}
