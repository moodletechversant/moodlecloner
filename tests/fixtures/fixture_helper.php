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

use tool_moodleclone\local\backup\backup_state;
use tool_moodleclone\local\backup\options;
use tool_moodleclone\local\backup\source_paths;
use tool_moodleclone\local\environment\collector;
use tool_moodleclone\local\log\memory_logger;
use tool_moodleclone\local\log\redactor;
use tool_moodleclone\local\package\checksum_writer;
use tool_moodleclone\local\package\workspace;
use tool_moodleclone\local\package\zip_writer;

/**
 * Builds fixture source trees and backup states for tests.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixture_helper {
    /**
     * Create a tree. Spec values: string = file content, null = directory, ['link' => target] = symlink.
     *
     * @param string $root
     * @param array $spec Relative path => value.
     * @return void
     */
    public static function make_tree(string $root, array $spec): void {
        foreach ($spec as $path => $value) {
            $full = $root . '/' . $path;
            if ($value === null) {
                @mkdir($full, 0777, true);
                continue;
            }
            @mkdir(dirname($full), 0777, true);
            if (is_array($value)) {
                symlink($value['link'], $full);
            } else {
                file_put_contents($full, $value);
            }
        }
    }

    /**
     * Put content into a file pool directory at its content-hash location.
     *
     * @param string $pool filedir or trashdir path.
     * @param string $content
     * @return string The SHA-1 content hash.
     */
    public static function add_pool_file(string $pool, string $content): string {
        $hash = sha1($content);
        $dir = $pool . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/' . $hash, $content);
        return $hash;
    }

    /**
     * Configuration object pointing at fixture roots.
     *
     * @param string $dirroot
     * @param string $dataroot
     * @param array $extra Other $CFG-style settings (filedir, trashdir, tempdir, ...).
     * @return \stdClass
     */
    public static function config(string $dirroot, string $dataroot, array $extra = []): \stdClass {
        global $CFG;
        $cfg = clone $CFG;
        foreach (array_merge(source_paths::RUNTIME_SETTINGS, ['filedir']) as $setting) {
            unset($cfg->$setting);
        }
        $cfg->dirroot = $dirroot;
        $cfg->dataroot = $dataroot;
        foreach ($extra as $key => $value) {
            $cfg->$key = $value;
        }
        return $cfg;
    }

    /**
     * A ready-to-run backup state for fixture roots (archive and spools under dataroot/moodleclone).
     *
     * @param string $dirroot
     * @param string $dataroot
     * @param options|null $options
     * @param array $extra Extra config settings.
     * @param bool $forcezip64
     * @return backup_state
     */
    public static function make_state(
        string $dirroot,
        string $dataroot,
        ?options $options = null,
        array $extra = [],
        bool $forcezip64 = false
    ): backup_state {
        global $DB;
        $cfg = self::config($dirroot, $dataroot, $extra);
        $state = new backup_state(
            (new collector($cfg, $DB))->collect(),
            $options ?? new options(),
            new memory_logger(new redactor()),
            time()
        );
        $state->sources = source_paths::from_config($cfg);
        $state->workspace = new workspace($dataroot);
        $state->workdir = $state->workspace->create_work_dir(1);
        $state->zip = new zip_writer($state->workdir . '/package.zip.part', $state->workdir . '/cd.spool', $forcezip64);
        $state->checksums = new checksum_writer($state->workdir . '/checksums.spool');
        return $state;
    }

    /**
     * Entry names of a finished archive.
     *
     * @param string $path
     * @return string[]
     */
    public static function zip_names(string $path): array {
        $zip = new \ZipArchive();
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        return $names;
    }
}
