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

namespace tool_moodleclone\local\backup;

/**
 * Source locations, derived only from Moodle configuration.
 *
 * Every path is the realpath() of a $CFG value, so symlinked roots are
 * resolved once here and all later containment checks compare real paths.
 * Nothing here can come from a request.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_paths {
    /** @var string[] $CFG settings that hold regenerable or runtime data, excluded wherever they live. */
    public const RUNTIME_SETTINGS = ['tempdir', 'cachedir', 'localcachedir', 'backuptempdir', 'trashdir'];

    /** @var string Real $CFG->dirroot. */
    public $dirroot;

    /** @var string Real $CFG->dataroot. */
    public $dataroot;

    /** @var string|null Real file pool directory ($CFG->filedir or dataroot/filedir). */
    public $filedir;

    /** @var bool True when $CFG->filedir points outside dataroot/filedir. */
    public $customfiledir = false;

    /** @var string|null Real trash directory ($CFG->trashdir or dataroot/trashdir). */
    public $trashdir;

    /** @var string[] Real paths of the runtime directories that exist. */
    public $runtimedirs = [];

    /**
     * Build from a configuration object.
     *
     * @param \stdClass|null $cfg Defaults to $CFG.
     * @return self
     */
    public static function from_config(?\stdClass $cfg = null): self {
        global $CFG;
        $cfg = $cfg ?? $CFG;
        $paths = new self();
        $paths->dirroot = self::real((string) $cfg->dirroot);
        $paths->dataroot = self::real((string) $cfg->dataroot);
        if ($paths->dirroot === null) {
            throw new backup_exception('sourcemissing', 'dirroot');
        }
        if ($paths->dataroot === null) {
            throw new backup_exception('sourcemissing', 'dataroot');
        }
        $defaultfiledir = $paths->dataroot . '/filedir';
        $paths->filedir = self::real(!empty($cfg->filedir) ? (string) $cfg->filedir : $defaultfiledir);
        $paths->customfiledir = $paths->filedir !== null && $paths->filedir !== self::real($defaultfiledir);
        $paths->trashdir = self::real(!empty($cfg->trashdir) ? (string) $cfg->trashdir : $paths->dataroot . '/trashdir');
        foreach (self::RUNTIME_SETTINGS as $setting) {
            if (!empty($cfg->$setting) && ($real = self::real((string) $cfg->$setting)) !== null) {
                $paths->runtimedirs[] = $real;
            }
        }
        $paths->runtimedirs = array_values(array_unique($paths->runtimedirs));
        return $paths;
    }

    /**
     * Paths inside $root that must not be collected from it, relative to $root.
     *
     * @param string $root Real path.
     * @param string[] $absolute Real paths.
     * @return string[]
     */
    public static function relative_inside(string $root, array $absolute): array {
        $relative = [];
        foreach ($absolute as $path) {
            if ($path !== null && strpos($path, $root . '/') === 0) {
                $relative[] = substr($path, strlen($root) + 1);
            }
        }
        return array_values(array_unique($relative));
    }

    /**
     * realpath() with forward slashes, or null.
     *
     * @param string $path
     * @return string|null
     */
    private static function real(string $path): ?string {
        if ($path === '') {
            return null;
        }
        $real = realpath($path);
        return $real === false ? null : rtrim(str_replace('\\', '/', $real), '/');
    }
}
