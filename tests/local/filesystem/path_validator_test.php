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

namespace tool_moodleclone\local\filesystem;

/**
 * Tests for path validation.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\filesystem\path_validator
 */
class path_validator_test extends \advanced_testcase {

    /**
     * Relative paths and whether they are safe.
     *
     * @return array
     */
    public function relative_path_provider(): array {
        return [
            'simple file' => ['manifest.json', true],
            'nested' => ['moodle/admin/index.php', true],
            'spaces and unicode' => ['moodledata/lang/fr/ça va.txt', true],
            'dot file' => ['moodledata/.htaccess', true],
            'dots inside name' => ['a..b/c', true],
            'empty' => ['', false],
            'parent' => ['../config.php', false],
            'nested parent' => ['moodle/../../etc/passwd', false],
            'trailing parent' => ['moodle/..', false],
            'current dir' => ['./manifest.json', false],
            'absolute' => ['/etc/passwd', false],
            'windows drive' => ['C:/Windows/win.ini', false],
            'windows drive relative' => ['C:evil', false],
            'backslash traversal' => ['moodle\\..\\..\\evil.php', false],
            'double slash' => ['moodle//index.php', false],
            'trailing slash' => ['moodle/', false],
            'null byte' => ["manifest.json\0.php", false],
            'newline' => ["a\nb", false],
            'too long' => [str_repeat('a/', 2100) . 'a', false],
        ];
    }

    /**
     * Relative path classification.
     *
     * @dataProvider relative_path_provider
     * @param string $path
     * @param bool $safe
     */
    public function test_is_safe_relative_path(string $path, bool $safe): void {
        $this->assertSame($safe, path_validator::is_safe_relative_path($path));
    }

    public function test_require_safe_relative_path_throws_with_reason(): void {
        try {
            path_validator::require_safe_relative_path('../x');
            $this->fail('Traversal accepted');
        } catch (invalid_path_exception $e) {
            $this->assertSame('unsaferelativepath', $e->reason);
            $this->assertStringNotContainsString('../x', $e->getMessage(), 'The offending path is not echoed');
        }
    }

    public function test_is_within(): void {
        $base = make_request_directory();
        mkdir($base . '/inner');
        touch($base . '/inner/file.txt');

        $this->assertTrue(path_validator::is_within($base, $base));
        $this->assertTrue(path_validator::is_within($base, $base . '/inner/file.txt'));
        $this->assertTrue(path_validator::is_within($base . '/', $base . '/inner/../inner/file.txt'));
        $this->assertFalse(path_validator::is_within($base . '/inner', $base));
        $this->assertFalse(path_validator::is_within($base, $base . '/missing'));
        $this->assertFalse(path_validator::is_within($base, dirname($base)));
    }

    public function test_is_within_rejects_sibling_with_common_prefix(): void {
        $parent = make_request_directory();
        mkdir($parent . '/data');
        mkdir($parent . '/data-evil');
        $this->assertFalse(path_validator::is_within($parent . '/data', $parent . '/data-evil'));
    }

    public function test_resolve_within_returns_path_for_new_file(): void {
        $base = make_request_directory();
        $resolved = path_validator::resolve_within($base, 'work/manifest.json');
        $this->assertSame(str_replace('\\', '/', realpath($base)) . '/work/manifest.json', $resolved);
    }

    public function test_resolve_within_rejects_traversal(): void {
        $this->expectException(invalid_path_exception::class);
        path_validator::resolve_within(make_request_directory(), '../outside.txt');
    }

    public function test_resolve_within_rejects_missing_base(): void {
        $this->expectException(invalid_path_exception::class);
        path_validator::resolve_within(make_request_directory() . '/missing', 'a.txt');
    }

    public function test_resolve_within_rejects_symlink_escape(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
        $base = make_request_directory();
        $outside = make_request_directory();
        symlink($outside, $base . '/link');

        try {
            path_validator::resolve_within($base, 'link/stolen.txt');
            $this->fail('Symlink escape accepted');
        } catch (invalid_path_exception $e) {
            $this->assertSame('pathescapesbase', $e->reason);
        }
    }

    /**
     * Symlink targets and where they resolve.
     *
     * @return array
     */
    public function link_target_provider(): array {
        return [
            'sibling' => ['vendor/bin/tool', '../tool', 'vendor/tool'],
            'same dir' => ['a/link', 'file', 'a/file'],
            'root itself' => ['a/link', '..', ''],
            'dot segments' => ['a/link', './b/../c', 'a/c'],
            'absolute inside' => ['link', '/srv/moodle/lib/x.php', 'lib/x.php'],
            'absolute via configured root' => ['link', '/var/www/moodle/lib/x.php', 'lib/x.php'],
            'absolute outside' => ['link', '/etc/passwd', null],
            'absolute prefix trick' => ['link', '/srv/moodle-evil/x', null],
            'climbing out' => ['a/link', '../../x', null],
            'empty' => ['link', '', null],
        ];
    }

    /**
     * Lexical resolution of link targets.
     *
     * @dataProvider link_target_provider
     * @param string $link
     * @param string $target
     * @param string|null $expected
     */
    public function test_resolve_link_target(string $link, string $target, ?string $expected): void {
        $this->assertSame($expected, path_validator::resolve_link_target('/srv/moodle', $link, $target, '/var/www/moodle'));
    }

    public function test_relative_path(): void {
        $this->assertSame('../tool', path_validator::relative_path('vendor/bin', 'vendor/tool'));
        $this->assertSame('.', path_validator::relative_path('a', 'a'));
        $this->assertSame('../..', path_validator::relative_path('a/b', ''));
        $this->assertSame('lib/x.php', path_validator::relative_path('', 'lib/x.php'));
    }

    public function test_normalise_segments(): void {
        $this->assertSame('a/c', path_validator::normalise_segments('a/./b/../c'));
        $this->assertSame('', path_validator::normalise_segments('a/..'));
        $this->assertNull(path_validator::normalise_segments('..'));
        $this->assertNull(path_validator::normalise_segments("a/b\\c"));
    }
}
