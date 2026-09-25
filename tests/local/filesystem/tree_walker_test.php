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

use tool_moodleclone\fixture_helper;
use tool_moodleclone\local\backup\backup_exception;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/fixture_helper.php');

/**
 * Tests for the tree walker.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\filesystem\tree_walker
 */
class tree_walker_test extends \advanced_testcase {
    /**
     * Skip on platforms without symlinks.
     *
     * @return void
     */
    private function require_symlinks(): void {
        if (!function_exists('symlink') || DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Symlinks are not available on this platform');
        }
    }

    /**
     * Walk and index entries by relative path.
     *
     * @param tree_walker $walker
     * @return tree_entry[]
     */
    private function walk(tree_walker $walker): array {
        $entries = [];
        foreach ($walker->walk() as $entry) {
            $entries[$entry->relative] = $entry;
        }
        return $entries;
    }

    public function test_walks_nested_files_in_sorted_order(): void {
        $root = make_request_directory();
        fixture_helper::make_tree($root, ['b.txt' => 'b', 'a/z.txt' => 'z', 'a/deep/er/file.txt' => 'deep', 'empty' => null]);

        $entries = $this->walk(new tree_walker($root));

        $this->assertSame(['a', 'a/deep', 'a/deep/er', 'a/deep/er/file.txt', 'a/z.txt', 'b.txt', 'empty'], array_keys($entries));
        $this->assertSame(tree_entry::DIRECTORY, $entries['a/deep']->type);
        $this->assertSame(tree_entry::FILE, $entries['a/deep/er/file.txt']->type);
        $this->assertSame(4, $entries['a/deep/er/file.txt']->size);
        $this->assertSame(fileinode($root . '/b.txt'), $entries['b.txt']->ino);
    }

    public function test_filter_prunes_directories(): void {
        $root = make_request_directory();
        fixture_helper::make_tree($root, ['keep/a.txt' => 'a', 'skip/b.txt' => 'b', 'config.php' => 'secret']);

        $entries = $this->walk(new tree_walker($root, function (string $relative) {
            return $relative !== 'skip' && $relative !== 'config.php';
        }));

        $this->assertSame(['keep', 'keep/a.txt'], array_keys($entries));
    }

    public function test_internal_symlinks_are_kept_as_relative_links(): void {
        $this->require_symlinks();
        $root = make_request_directory();
        $real = str_replace('\\', '/', realpath($root));
        fixture_helper::make_tree($root, [
            'vendor/tool' => 'x',
            'vendor/bin/tool' => ['link' => '../tool'],
            'abslink' => ['link' => $real . '/vendor/tool'],
            'dangling' => ['link' => 'does/not/exist'],
        ]);

        $entries = $this->walk(new tree_walker($root));

        $this->assertSame(tree_entry::SYMLINK, $entries['vendor/bin/tool']->type);
        $this->assertSame('../tool', $entries['vendor/bin/tool']->linktarget);
        $this->assertSame('vendor/tool', $entries['abslink']->linktarget, 'Absolute internal targets become relative');
        $this->assertSame('does/not/exist', $entries['dangling']->linktarget);
    }

    /**
     * Links that leave the root.
     *
     * @return array
     */
    public static function external_link_provider(): array {
        return [
            'absolute outside' => ['/etc'],
            'relative climbing out' => ['../../outside'],
        ];
    }

    /**
     * External symlinks stop the walk.
     *
     * @dataProvider external_link_provider
     * @param string $target
     */
    public function test_external_symlink_is_rejected(string $target): void {
        $this->require_symlinks();
        $root = make_request_directory();
        fixture_helper::make_tree($root, ['a.txt' => 'a', 'sub/evil' => ['link' => $target]]);

        try {
            $this->walk(new tree_walker($root));
            $this->fail('External symlink accepted');
        } catch (backup_exception $e) {
            $this->assertSame('externalsymlink', $e->reason);
            $this->assertStringContainsString('sub/evil', $e->a);
        }
    }

    public function test_symlink_chain_escaping_is_rejected(): void {
        $this->require_symlinks();
        $root = make_request_directory();
        $outside = make_request_directory();
        // The "hop" link looks internal lexically but points at "out", which leaves the tree.
        fixture_helper::make_tree($root, ['out' => ['link' => $outside], 'hop' => ['link' => 'out']]);

        $this->expectException(backup_exception::class);
        $this->walk(new tree_walker($root));
    }

    public function test_symlinked_directory_is_not_descended(): void {
        $this->require_symlinks();
        $root = make_request_directory();
        fixture_helper::make_tree($root, ['real/inside.txt' => 'x', 'alias' => ['link' => 'real']]);

        $entries = $this->walk(new tree_walker($root));

        $this->assertArrayHasKey('alias', $entries);
        $this->assertArrayNotHasKey('alias/inside.txt', $entries, 'Links are archived as links, never followed');
    }

    public function test_unsafe_names_stop_the_walk(): void {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Backslashes are separators on Windows');
        }
        $root = make_request_directory();
        file_put_contents($root . '/bad\\name.php', 'x');

        try {
            $this->walk(new tree_walker($root));
            $this->fail('Unsafe name accepted');
        } catch (backup_exception $e) {
            $this->assertSame('unsafename', $e->reason);
        }
    }

    public function test_special_files_are_skipped_with_warning(): void {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo() is not available');
        }
        $root = make_request_directory();
        posix_mkfifo($root . '/fifo', 0600);
        file_put_contents($root . '/file.txt', 'x');
        $warnings = [];

        $entries = $this->walk(new tree_walker($root, null, function (string $code, string $relative) use (&$warnings) {
            $warnings[] = "{$code}:{$relative}";
        }));

        $this->assertSame(['file.txt'], array_keys($entries));
        $this->assertSame(['special:fifo'], $warnings);
    }

    public function test_missing_root(): void {
        $this->expectException(backup_exception::class);
        new tree_walker(make_request_directory() . '/missing');
    }
}
