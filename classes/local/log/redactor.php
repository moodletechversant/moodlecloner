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
 * Removes secrets from log messages.
 *
 * Two layers: exact known secret values (e.g. the database password, taken
 * from $CFG only to be able to scrub it) and generic "password=..." style
 * patterns that catch secrets the plugin does not know about, such as those
 * embedded in driver error messages.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class redactor {

    /** @var string Replacement text. */
    public const MASK = '[redacted]';

    /** @var string[] $CFG properties whose values must never appear in output. */
    public const SECRET_CONFIG = ['dbpass', 'passwordsaltmain', 'cronremotepassword'];

    /** @var string Matches "key=value" / "key: value" pairs whose key names a secret. */
    private const PAIR_PATTERN = '/\b((?:db)?pass(?:word|wd)?|pwd|secret|token|api[_-]?key|salt)(\s*[=:]\s*)(["\']?)[^\s"\',;&)]+\3/i';

    /** @var string MySQL "Access denied for user 'name'@'host'": hides the database account. */
    private const DB_USER_PATTERN = "/user '[^']*'@'[^']*'/i";

    /** @var string Credentials embedded in a URL or DSN, e.g. mysql://user:pass@host. */
    private const URL_CREDENTIALS_PATTERN = '#://[^/\s:@]+:[^/\s@]+@#';

    /** @var string[] Secret values to scrub. */
    private $secrets = [];

    /**
     * Constructor.
     *
     * @param string[] $secrets Exact values to remove. Empty strings are ignored.
     */
    public function __construct(array $secrets = []) {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $this->secrets[] = $secret;
            }
        }
        // Replace longer secrets first so one secret containing another is fully masked.
        usort($this->secrets, function(string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });
    }

    /**
     * Build a redactor for the secrets present in a configuration object.
     *
     * @param \stdClass|null $cfg Defaults to global $CFG.
     * @return self
     */
    public static function from_config(?\stdClass $cfg = null): self {
        global $CFG;
        $cfg = $cfg ?? $CFG;
        $secrets = [];
        foreach (self::SECRET_CONFIG as $name) {
            if (isset($cfg->$name)) {
                $secrets[] = (string) $cfg->$name;
            }
        }
        return new self($secrets);
    }

    /**
     * Return the message with secrets masked.
     *
     * @param string $message
     * @return string
     */
    public function redact(string $message): string {
        if ($this->secrets) {
            $message = str_replace($this->secrets, self::MASK, $message);
        }
        $message = preg_replace(self::DB_USER_PATTERN, "user '" . self::MASK . "'@'" . self::MASK . "'", $message);
        $message = preg_replace(self::URL_CREDENTIALS_PATTERN, '://' . self::MASK . '@', $message);
        return preg_replace(self::PAIR_PATTERN, '$1$2' . self::MASK, $message);
    }
}
