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

use tool_moodleclone\local\filesystem\invalid_path_exception;

/**
 * Tests for the package layout.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\layout
 */
class layout_test extends \basic_testcase {
    public function test_filename_uses_utc_timestamp(): void {
        // 2026-09-24 13:45:01 UTC.
        $this->assertSame('moodle-clone-2026-09-24-134501.zip', layout::filename(1790257501));
    }

    public function test_generated_filenames_are_valid(): void {
        $this->assertTrue(layout::is_valid_filename(layout::filename(time())));
    }

    public function test_invalid_filenames(): void {
        $this->assertFalse(layout::is_valid_filename('moodle-clone.zip'));
        $this->assertFalse(layout::is_valid_filename('../moodle-clone-2026-09-24-134501.zip'));
        $this->assertFalse(layout::is_valid_filename('moodle-clone-2026-09-24-134501.zip.php'));
        $this->assertFalse(layout::is_valid_filename('backup-2026-09-24-134501.zip'));
    }

    public function test_entry_names(): void {
        $this->assertSame('moodle/lib/moodlelib.php', layout::code_entry('lib/moodlelib.php'));
        $this->assertSame('moodledata/filedir/ab/cd/abcd', layout::data_entry('filedir/ab/cd/abcd'));
    }

    public function test_code_entry_rejects_traversal(): void {
        $this->expectException(invalid_path_exception::class);
        layout::code_entry('../config.php');
    }

    public function test_data_entry_rejects_absolute(): void {
        $this->expectException(invalid_path_exception::class);
        layout::data_entry('/etc/passwd');
    }

    public function test_allowed_entries(): void {
        $this->assertTrue(layout::is_allowed_entry('manifest.json'));
        $this->assertTrue(layout::is_allowed_entry('database.sql.gz'));
        $this->assertTrue(layout::is_allowed_entry('checksums.sha256'));
        $this->assertTrue(layout::is_allowed_entry('moodle/index.php'));
        $this->assertTrue(layout::is_allowed_entry('moodledata/filedir/00/11/0011'));

        $this->assertFalse(layout::is_allowed_entry('installer.php'), 'Unexpected top-level files are rejected');
        $this->assertFalse(layout::is_allowed_entry('moodle'), 'A directory name alone is not a file entry');
        $this->assertFalse(layout::is_allowed_entry('moodlex/index.php'));
        $this->assertFalse(layout::is_allowed_entry('moodle/../../etc/passwd'));
        $this->assertFalse(layout::is_allowed_entry('/moodle/index.php'));
        $this->assertFalse(layout::is_allowed_entry('moodle\\..\\evil.php'));
    }

    public function test_directory_entries(): void {
        $this->assertTrue(layout::is_allowed_directory_entry('moodle/'));
        $this->assertTrue(layout::is_allowed_directory_entry('moodledata/filedir/aa/'));
        $this->assertFalse(layout::is_allowed_directory_entry('moodle'));
        $this->assertFalse(layout::is_allowed_directory_entry('other/'));
        $this->assertFalse(layout::is_allowed_directory_entry('moodle/../'));
    }

    public function test_component_of(): void {
        $this->assertSame(manifest::CONTENT_MOODLE, layout::component_of('moodle/index.php'));
        $this->assertSame(manifest::CONTENT_MOODLEDATA, layout::component_of('moodledata/'));
        $this->assertSame(manifest::CONTENT_DATABASE, layout::component_of('database.sql.gz'));
        $this->assertNull(layout::component_of('manifest.json'));
        $this->assertNull(layout::component_of('moodlex/a'));
    }
}
