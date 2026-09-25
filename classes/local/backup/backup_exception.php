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
 * A backup failure an administrator can act on.
 *
 * The error code maps to the language string "error:<code>". Any path passed
 * as $a is relative to a source root and has control characters replaced, so
 * it is safe to show in logs, the job record and the admin page.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_exception extends \moodle_exception {
    /** @var string Short reason code, e.g. "externalsymlink" (Exception::$code is left untouched). */
    public $reason;

    /**
     * Constructor.
     *
     * @param string $code Error code without the "error:" prefix.
     * @param string|\stdClass|array|null $a Language string parameter.
     * @param string|null $debuginfo Extra detail; must already be free of secrets.
     */
    public function __construct(string $code, $a = null, ?string $debuginfo = null) {
        $this->reason = $code;
        if (is_string($a)) {
            $a = self::printable($a);
        }
        parent::__construct('error:' . $code, 'tool_moodleclone', '', $a, $debuginfo);
    }

    /**
     * One-line description of any exception for logs and the job record: the
     * message plus, for Moodle exceptions, the debug detail. Callers must pass
     * the result through the redactor before storing or printing it.
     *
     * @param \Throwable $e
     * @return string
     */
    public static function describe(\Throwable $e): string {
        $message = $e->getMessage();
        if ($e instanceof \moodle_exception && !empty($e->debuginfo) && strpos($message, (string) $e->debuginfo) === false) {
            $message .= ' (' . $e->debuginfo . ')';
        }
        return self::printable($message);
    }

    /**
     * Make an arbitrary path or name safe for one-line output.
     *
     * @param string $text
     * @return string
     */
    public static function printable(string $text): string {
        return preg_replace('/[\x00-\x1F\x7F]/', '?', $text);
    }
}
