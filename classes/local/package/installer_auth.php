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

/**
 * How the standalone installer authorizes whoever opens it (manifest field "installer_auth").
 *
 * Two modes exist, and every package has exactly one:
 *
 * - password (the default in the UI and the CLI): the administrator chooses an
 *   installer password when the package is created. Only a salted PBKDF2-HMAC-SHA256
 *   verifier is kept, so the installer can check a password offline, in a
 *   browser, on a server that has no connection to the source site and no
 *   Moodle. The plaintext exists only in memory during the request that hashes
 *   it: it is never written to the package, the job record, the manifest, a log
 *   or any configuration.
 * - keyfile: the installer writes a random key into the destination folder and
 *   asks for it, so whoever installs must be able to read a file on the
 *   destination server (SSH, hosting file manager or FTP). It is the explicit
 *   alternative for administrators who do not want a password inside the package.
 *
 * PBKDF2 is used because it is part of core PHP (hash_pbkdf2), so the
 * destination can verify it whatever extensions it has; Argon2 and libsodium
 * are optional there and a package that cannot be verified would lock the
 * administrator out.
 *
 * The password is used byte for byte as typed (no trimming or normalisation).
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class installer_auth {
    /** @var string Mode: the administrator chose an installer password. */
    public const MODE_PASSWORD = 'password';

    /** @var string Mode: the installer asks for a key stored in a file on the destination server. */
    public const MODE_KEYFILE = 'keyfile';

    /** @var string[] All modes. */
    public const MODES = [self::MODE_PASSWORD, self::MODE_KEYFILE];

    /** @var string Key derivation function name recorded in the manifest. */
    public const KDF = 'pbkdf2-sha256';

    /** @var int Iterations for new verifiers (OWASP's 2023 minimum for PBKDF2-HMAC-SHA256 is 600000). */
    public const ITERATIONS = 600000;

    /** @var int Lowest iteration count the installer accepts. */
    public const MIN_ITERATIONS = 100000;

    /** @var int Highest iteration count the installer accepts (a hostile package must not be able to stall the server). */
    public const MAX_ITERATIONS = 2000000;

    /** @var int Salt length in bytes. */
    public const SALT_BYTES = 16;

    /** @var int Verifier length in bytes. */
    public const VERIFIER_BYTES = 32;

    /** @var int Shortest acceptable password, in characters. */
    public const MIN_LENGTH = 12;

    /** @var int Longest acceptable password, in bytes. */
    public const MAX_BYTES = 1024;

    /** @var int Fewest distinct characters a password must contain. */
    public const MIN_DISTINCT = 6;

    /** @var array Validated data, as stored in the manifest. */
    private $data;

    /**
     * Use the static factories.
     *
     * @param array $data
     */
    private function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * Protect the installer with a password.
     *
     * @param string $password Plaintext; it is not kept.
     * @return self
     * @throws \InvalidArgumentException When the password is unacceptable (see {@see password_problems()}).
     */
    public static function from_password(
        #[\SensitiveParameter]
        string $password
    ): self {
        if (self::password_problems($password, $password)) {
            throw new \InvalidArgumentException('The installer password is not acceptable');
        }
        $salt = random_bytes(self::SALT_BYTES);
        return new self([
            'mode' => self::MODE_PASSWORD,
            'kdf' => self::KDF,
            'iterations' => self::ITERATIONS,
            'salt' => base64_encode($salt),
            'verifier' => base64_encode(self::derive($password, $salt, self::ITERATIONS)),
        ]);
    }

    /**
     * Protect the installer with a key file on the destination server.
     *
     * @return self
     */
    public static function keyfile(): self {
        return new self(['mode' => self::MODE_KEYFILE]);
    }

    /**
     * Rebuild from manifest or job data.
     *
     * @param array $data
     * @return self
     * @throws \InvalidArgumentException When the data is not a valid description.
     */
    public static function from_array(array $data): self {
        if ($errors = self::validate($data)) {
            throw new \InvalidArgumentException('Invalid installer_auth: ' . implode('; ', $errors));
        }
        return new self($data);
    }

    /**
     * Problems with an installer_auth description. Also used by the manifest validator.
     *
     * The description holds public offline-verification data (a salt and a
     * derived verifier), which is why the manifest's generic "looks like a
     * secret" key scan does not apply to it; this strict schema does instead.
     *
     * @param array $data
     * @return string[] Empty when valid.
     */
    public static function validate(array $data): array {
        $mode = $data['mode'] ?? null;
        if (!in_array($mode, self::MODES, true)) {
            return ["installer_auth.mode must be one of: " . implode(', ', self::MODES)];
        }
        $expected = $mode === self::MODE_PASSWORD ? ['mode', 'kdf', 'iterations', 'salt', 'verifier'] : ['mode'];
        $errors = [];
        foreach (array_diff(array_keys($data), $expected) as $key) {
            $errors[] = "unknown key 'installer_auth.{$key}'";
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $data)) {
                $errors[] = "missing key 'installer_auth.{$key}'";
            }
        }
        if ($errors || $mode === self::MODE_KEYFILE) {
            return $errors;
        }
        if ($data['kdf'] !== self::KDF) {
            $errors[] = "installer_auth.kdf must be '" . self::KDF . "'";
        }
        if (
            !is_int($data['iterations']) ||
            $data['iterations'] < self::MIN_ITERATIONS ||
            $data['iterations'] > self::MAX_ITERATIONS
        ) {
            $errors[] = 'installer_auth.iterations must be an integer between ' . self::MIN_ITERATIONS .
                ' and ' . self::MAX_ITERATIONS;
        }
        foreach (['salt' => self::SALT_BYTES, 'verifier' => self::VERIFIER_BYTES] as $key => $bytes) {
            $decoded = is_string($data[$key]) ? base64_decode($data[$key], true) : false;
            if ($decoded === false || strlen($decoded) !== $bytes || base64_encode($decoded) !== $data[$key]) {
                $errors[] = "installer_auth.{$key} must be base64 of {$bytes} bytes";
            }
        }
        return $errors;
    }

    /**
     * Problems with a chosen password.
     *
     * @param string $password
     * @param string $confirmation The second entry of the password.
     * @return string[] Codes, each the suffix of the lang string "error:installerpassword_<code>". Empty when acceptable.
     */
    public static function password_problems(
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        string $confirmation
    ): array {
        $problems = [];
        if (!hash_equals($password, $confirmation)) {
            $problems[] = 'mismatch';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($length < self::MIN_LENGTH) {
            $problems[] = 'short';
        } else if (strlen($password) > self::MAX_BYTES) {
            $problems[] = 'long';
        } else if (
            count(array_unique(preg_split('//u', $password, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($password))) <
                self::MIN_DISTINCT
        ) {
            $problems[] = 'weak';
        }
        return $problems;
    }

    /**
     * The mode.
     *
     * @return string
     */
    public function get_mode(): string {
        return $this->data['mode'];
    }

    /**
     * Whether the installer will ask for a password.
     *
     * @return bool
     */
    public function is_password(): bool {
        return $this->data['mode'] === self::MODE_PASSWORD;
    }

    /**
     * Data for manifest.json (and, until the worker starts, the job record).
     *
     * @return array
     */
    public function to_array(): array {
        return $this->data;
    }

    /**
     * Whether a password matches. Constant-time comparison; costs one PBKDF2 derivation.
     *
     * @param string $password
     * @return bool
     */
    public function verify(
        #[\SensitiveParameter]
        string $password
    ): bool {
        if (!$this->is_password()) {
            return false;
        }
        $derived = self::derive($password, base64_decode($this->data['salt'], true), $this->data['iterations']);
        return hash_equals(base64_decode($this->data['verifier'], true), $derived);
    }

    /**
     * PBKDF2-HMAC-SHA256.
     *
     * @param string $password
     * @param string $salt Raw bytes.
     * @param int $iterations
     * @return string Raw bytes.
     */
    private static function derive(
        #[\SensitiveParameter]
        string $password,
        string $salt,
        int $iterations
    ): string {
        return hash_pbkdf2('sha256', $password, $salt, $iterations, self::VERIFIER_BYTES, true);
    }
}
