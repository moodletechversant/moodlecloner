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

namespace tool_moodleclone\local\log;

/**
 * Logger base class that guarantees redaction.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_logger implements logger {
    /** @var redactor */
    private $redactor;

    /**
     * Constructor.
     *
     * @param redactor|null $redactor Defaults to one built from $CFG.
     */
    public function __construct(?redactor $redactor = null) {
        $this->redactor = $redactor ?? redactor::from_config();
    }

    /**
     * Redact and write a message.
     *
     * @param string $level
     * @param string $message
     * @return void
     */
    final public function log(string $level, string $message): void {
        if (!in_array($level, [self::INFO, self::WARNING, self::ERROR], true)) {
            throw new \coding_exception("Invalid log level '{$level}'");
        }
        $this->write($level, $this->redactor->redact($message));
    }

    /**
     * Log at info level.
     *
     * @param string $message
     * @return void
     */
    public function info(string $message): void {
        $this->log(self::INFO, $message);
    }

    /**
     * Log at warning level.
     *
     * @param string $message
     * @return void
     */
    public function warning(string $message): void {
        $this->log(self::WARNING, $message);
    }

    /**
     * Log at error level.
     *
     * @param string $message
     * @return void
     */
    public function error(string $message): void {
        $this->log(self::ERROR, $message);
    }

    /**
     * Write an already redacted message.
     *
     * @param string $level
     * @param string $message
     * @return void
     */
    abstract protected function write(string $level, string $message): void;
}
