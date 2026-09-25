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
 * The stages of a backup, in execution order.
 *
 * Each stage has a language string "stage:<name>" used for progress messages.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stage {

    /** @var string Size estimate and environment checks, before any file is written. */
    public const PREFLIGHT = 'preflight';

    /** @var string Setup (log label only). */
    public const STARTING = 'starting';

    /** @var string Moodle code tree. */
    public const CODE = 'code';

    /** @var string Persistent moodledata. */
    public const DATAROOT = 'dataroot';

    /** @var string Database dump. */
    public const DATABASE = 'database';

    /** @var string manifest.json. */
    public const MANIFEST = 'manifest';

    /** @var string checksums.sha256. */
    public const CHECKSUMS = 'checksums';

    /** @var string Writing the zip archive. */
    public const ARCHIVE = 'archive';

    /** @var string Moving the package into place and cleaning up. */
    public const FINALISING = 'finalising';

    /**
     * @var string[] All stages in order. The database is dumped before moodledata is
     * collected so that files deleted after the database snapshot can still be
     * recovered from the trash (see step\dataroot_collector).
     */
    public const ALL = [
        self::PREFLIGHT, self::STARTING, self::CODE, self::DATABASE, self::DATAROOT,
        self::MANIFEST, self::CHECKSUMS, self::ARCHIVE, self::FINALISING,
    ];

    /**
     * Human readable description of a stage.
     *
     * @param string $stage
     * @return string
     */
    public static function get_label(string $stage): string {
        if (!in_array($stage, self::ALL, true)) {
            throw new \coding_exception("Unknown backup stage '{$stage}'");
        }
        return get_string('stage:' . $stage, 'tool_moodleclone');
    }
}
