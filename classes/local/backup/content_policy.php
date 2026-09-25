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

use tool_moodleclone\local\filesystem\path_validator;

/**
 * Decides which files of a source tree go into a package.
 *
 * Decisions are made on the first path segment (plus a list of exact paths),
 * which is cheap enough to evaluate for millions of files and easy to audit.
 * Anything not explicitly excluded is included: losing persistent data a
 * plugin keeps in an unexpected place is worse than a slightly larger package.
 *
 * See README.md, "Moodledata policy", for the reasoning behind each entry.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_policy {
    /** @var string[] Top-level name => reason string identifier (component tool_moodleclone). */
    private $excludeddirs;

    /** @var string[] Exact relative path => reason string identifier. */
    private $excludedpaths;

    /** @var string[] Relative directory => reason; the directory and everything below it is excluded. */
    private $excludedprefixes;

    /**
     * Constructor.
     *
     * @param string[] $excludeddirs Top-level name => reason string identifier.
     * @param string[] $excludedpaths Exact relative path => reason string identifier.
     * @param string[] $excludedprefixes Relative directory => reason string identifier.
     */
    public function __construct(array $excludeddirs, array $excludedpaths = [], array $excludedprefixes = []) {
        $this->excludeddirs = $excludeddirs;
        $this->excludedpaths = $excludedpaths;
        $this->excludedprefixes = $excludedprefixes;
    }

    /**
     * Policy for $CFG->dataroot.
     *
     * @return self
     */
    public static function for_dataroot(): self {
        return new self([
            'cache' => 'exclude:regenerated',
            'localcache' => 'exclude:regenerated',
            'temp' => 'exclude:temporary',
            'sessions' => 'exclude:sessions',
            'trashdir' => 'exclude:trash',
            'lock' => 'exclude:runtime',
            'muc' => 'exclude:muc',
            'antivirus_quarantine' => 'exclude:quarantine',
            'moodleclone' => 'exclude:ownoutput',
        ], [
            'climaintenance.html' => 'exclude:runtime',
        ]);
    }

    /**
     * Policy for $CFG->dirroot.
     *
     * @return self
     */
    public static function for_code(): self {
        return new self([
            '.git' => 'exclude:vcs',
            'node_modules' => 'exclude:devtools',
        ], [
            'config.php' => 'exclude:config',
        ]);
    }

    /**
     * Policy for this site's dataroot: the fixed list plus directories that
     * config.php moves elsewhere inside dataroot (tempdir, cachedir, ...), and
     * dataroot/filedir when $CFG->filedir points somewhere else (Moodle does not
     * use it then, and the real file pool is collected separately).
     *
     * @param source_paths $paths
     * @return self
     */
    public static function for_site_dataroot(source_paths $paths): self {
        $base = self::for_dataroot();
        $prefixes = [];
        foreach (source_paths::relative_inside($paths->dataroot, $paths->runtimedirs) as $relative) {
            $prefixes[$relative] = 'exclude:configureddir';
        }
        if ($paths->customfiledir) {
            $prefixes['filedir'] = 'exclude:customfiledir';
            foreach (source_paths::relative_inside($paths->dataroot, [$paths->filedir]) as $relative) {
                $prefixes[$relative] = 'exclude:customfiledir';
            }
        }
        return new self($base->excludeddirs, $base->excludedpaths, $prefixes);
    }

    /**
     * Policy for this site's dirroot: the fixed list plus any data directory
     * nested inside the code tree (a discouraged but possible setup).
     *
     * @param source_paths $paths
     * @return self
     */
    public static function for_site_code(source_paths $paths): self {
        $base = self::for_code();
        $prefixes = [];
        $data = array_merge([$paths->dataroot, $paths->filedir, $paths->trashdir], $paths->runtimedirs);
        foreach (source_paths::relative_inside($paths->dirroot, array_filter($data)) as $relative) {
            $prefixes[$relative] = 'exclude:nesteddata';
        }
        return new self($base->excludeddirs, $base->excludedpaths, $prefixes);
    }

    /**
     * Inclusion filter for tree_walker.
     *
     * @return callable fn(string $relative): bool
     */
    public function get_filter(): callable {
        return function (string $relative): bool {
            return $this->should_include($relative);
        };
    }

    /**
     * Every excluded path (top-level names, exact paths and prefixes), sorted, for the manifest.
     *
     * @return string[]
     */
    public function describe_exclusions(): array {
        $all = array_merge(
            array_keys($this->excludeddirs),
            array_keys($this->excludedpaths),
            array_keys($this->excludedprefixes)
        );
        $all = array_values(array_unique(array_map('strval', $all)));
        sort($all, SORT_STRING);
        return $all;
    }

    /**
     * Whether a path relative to the source root belongs in the package.
     *
     * @param string $relative
     * @return bool False for excluded or unsafe paths.
     */
    public function should_include(string $relative): bool {
        return $this->get_exclusion_reason($relative) === null;
    }

    /**
     * Why a path is excluded.
     *
     * @param string $relative Path relative to the source root.
     * @return string|null Reason string identifier, or null when included.
     */
    public function get_exclusion_reason(string $relative): ?string {
        if (!path_validator::is_safe_relative_path($relative)) {
            return 'exclude:unsafe';
        }
        if (isset($this->excludedpaths[$relative])) {
            return $this->excludedpaths[$relative];
        }
        foreach ($this->excludedprefixes as $prefix => $reason) {
            $prefix = (string) $prefix;
            if ($relative === $prefix || strpos($relative, $prefix . '/') === 0) {
                return $reason;
            }
        }
        $top = explode('/', $relative, 2)[0];
        return $this->excludeddirs[$top] ?? null;
    }

    /**
     * Excluded top-level directories.
     *
     * @return string[] Name => reason string identifier.
     */
    public function get_excluded_dirs(): array {
        return $this->excludeddirs;
    }

    /**
     * Excluded exact paths.
     *
     * @return string[] Path => reason string identifier.
     */
    public function get_excluded_paths(): array {
        return $this->excludedpaths;
    }

    /**
     * Excluded directory prefixes.
     *
     * @return string[] Relative directory => reason string identifier.
     */
    public function get_excluded_prefixes(): array {
        return $this->excludedprefixes;
    }
}
