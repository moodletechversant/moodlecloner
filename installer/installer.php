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

// phpcs:ignoreFile -- standalone installer, deliberately independent of Moodle.

/**
 * Moodle Clone standalone installer.
 *
 * Copy this file and a moodle-clone-YYYY-MM-DD-HHMMSS.zip package (plus its
 * .sha256 file) into an EMPTY directory served by the destination web
 * server, then open installer.php in a browser. It does not need Moodle: it
 * verifies the package, checks the server, asks for the destination
 * settings, restores the code, moodledata and MySQL database, writes a new
 * config.php, then (only then) boots the restored Moodle to migrate paths
 * and links, purge caches and verify the site, and finally deletes itself.
 *
 * Work is done in time-limited slices across requests, so it survives
 * normal PHP and proxy timeouts. See the README of tool_moodleclone.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace MoodleCloneInstaller;

// The copy shipped inside the plugin is a template and must never run in place.
if (is_file(__DIR__ . '/../version.php') &&
        strpos((string) @file_get_contents(__DIR__ . '/../version.php'), "'tool_moodleclone'") !== false) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This is the Moodle Clone installer template. Download it from the Moodle Clone admin page and run it on the destination server.\n";
    exit;
}

const VERSION = '1.1.0';
const FORMATS = [2, 3];
const PRODUCT = 'moodle-clone';
const PACKAGE_PATTERN = '/^moodle-clone-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/';
const KEY_FILE = 'moodleclone-installer-key.php';
const AUTH_FILE = 'moodleclone-installer-auth.php';
const LOCK_FILE = 'moodleclone-installer.lock';
const RUN_LOCK = 'moodleclone-installer.running';
const WORK_DIR = '.moodleclone-restore';
const HTACCESS_MARK = '# moodleclone-installer protection';
const COMPLETION_MARKER = '-- Moodle Clone dump completed';
const CHUNK = 1048576;
const SLICE_SECONDS = 20;
const SESSION_NAME = 'MOODLECLONEINSTALLER';

/** PHP extensions Moodle 4.1 requires (admin/environment.xml), plus mysqli for the database. */
const REQUIRED_EXTENSIONS = ['iconv', 'mbstring', 'curl', 'openssl', 'ctype', 'zip', 'zlib', 'gd', 'simplexml', 'spl',
    'pcre', 'dom', 'xml', 'xmlreader', 'intl', 'json', 'hash', 'fileinfo', 'mysqli'];

/** Optional extensions Moodle 4.1 recommends. */
const OPTIONAL_EXTENSIONS = ['tokenizer', 'soap', 'sodium', 'exif', 'opcache'];

/** Supported PHP range per Moodle branch: [minimum, highest officially supported]. */
const PHP_SUPPORT = ['401' => ['7.4.0', '8.1.99']];

/**
 * HTML-escape.
 *
 * @param mixed $value
 * @return string
 */
function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Human readable byte size.
 *
 * @param int|float $bytes
 * @return string
 */
function size($bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

/**
 * An error to show to the administrator. Messages never contain passwords.
 */
class installer_exception extends \Exception {
}

/**
 * Path rules shared by every filesystem operation.
 */
class paths {

    /**
     * Whether a package-relative path is safe: not empty, forward slashes only,
     * not absolute, no drive letter, no "."/".."/empty segments, no control
     * characters, valid UTF-8.
     *
     * @param string $path
     * @return bool
     */
    public static function is_safe_relative(string $path): bool {
        if ($path === '' || strlen($path) > 4096 || preg_match('/[\x00-\x1F\x7F\\\\]/', $path) ||
                !preg_match('//u', $path) || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalise an absolute path lexically (no filesystem access).
     *
     * @param string $path
     * @return string|null Null when not absolute or it climbs above "/".
     */
    public static function normalise_absolute(string $path): ?string {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!$parts) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return '/' . implode('/', $parts);
    }

    /**
     * The path with its deepest existing ancestor resolved through realpath().
     *
     * @param string $path Normalised absolute path.
     * @return string
     */
    public static function resolve(string $path): string {
        $suffix = '';
        $current = $path;
        while (!file_exists($current) && $current !== '/') {
            $suffix = '/' . basename($current) . $suffix;
            $current = dirname($current);
        }
        $real = realpath($current);
        return rtrim($real === false ? $current : $real, '/') . $suffix;
    }

    /**
     * Whether $child is $parent or inside it (both normalised, resolved).
     *
     * @param string $child
     * @param string $parent
     * @return bool
     */
    public static function is_inside(string $child, string $parent): bool {
        $parent = rtrim($parent, '/');
        return $child === $parent || strpos($child, $parent . '/') === 0 || $parent === '';
    }

    /**
     * Delete a tree without following symbolic links; refuses anything outside $boundary.
     *
     * @param string $path
     * @param string $boundary Directory the path must be strictly inside.
     * @return void
     */
    public static function remove_tree(string $path, string $boundary): void {
        $path = rtrim($path, '/');
        if (!self::is_inside($path, $boundary) || rtrim($path, '/') === rtrim($boundary, '/')) {
            throw new installer_exception('Refusing to delete outside the destination: ' . $path);
        }
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0170000) === 0040000) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove_tree($path . '/' . $name, $boundary);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

/**
 * Validation of manifest.json (package formats 2 and 3), mirroring tool_moodleclone's manifest_validator.
 */
class manifest_check {

    /** @var string[] Required top-level keys and types. */
    private const FIELDS = [
        'format' => 'integer', 'product' => 'string', 'created' => 'string', 'generator' => 'array',
        'moodle_version' => 'string', 'moodle_release' => 'string', 'moodle_branch' => 'string', 'php_version' => 'string',
        'database_type' => 'string', 'database_family' => 'string', 'database_version' => '?string', 'wwwroot' => 'string',
        'dirroot' => 'string', 'dataroot' => 'string', 'table_prefix' => 'string', 'package_contents' => 'array',
        'statistics' => 'array', 'database_dump' => '?array', 'moodledata_excluded' => 'array',
    ];

    /** @var string[] Added by format 3. */
    private const FIELDS_V3 = ['installer_auth' => 'array'];

    /**
     * Problems with a decoded manifest.
     *
     * @param mixed $data
     * @return string[]
     */
    public static function validate($data): array {
        if (!is_array($data)) {
            return ['manifest.json is not a JSON object'];
        }
        $errors = [];
        foreach (self::forbidden_keys($data) as $key) {
            $errors[] = "forbidden key '{$key}'";
        }
        $fields = ($data['format'] ?? null) === 3 ? self::FIELDS + self::FIELDS_V3 : self::FIELDS;
        foreach (array_keys($data) as $key) {
            if (!isset($fields[$key])) {
                $errors[] = "unknown key '{$key}'";
            }
        }
        foreach ($fields as $key => $type) {
            if (!array_key_exists($key, $data)) {
                $errors[] = "missing key '{$key}'";
                continue;
            }
            $nullable = $type[0] === '?';
            $type = ltrim($type, '?');
            if (!($nullable && $data[$key] === null) && gettype($data[$key]) !== $type) {
                $errors[] = "'{$key}' has the wrong type";
            }
        }
        if ($errors) {
            return $errors;
        }
        if (!in_array($data['format'], FORMATS, true)) {
            $errors[] = "unsupported package format {$data['format']} (this installer reads formats " . implode(', ', FORMATS) . ')';
        }
        if (isset($data['installer_auth'])) {
            $errors = array_merge($errors, auth_verifier::validate($data['installer_auth']));
        }
        if ($data['product'] !== PRODUCT) {
            $errors[] = 'not a Moodle Clone package';
        }
        if ($data['database_family'] !== 'mysql' || !in_array($data['database_type'], ['mysqli', 'mariadb', 'auroramysql'], true)) {
            $errors[] = 'only MySQL packages can be restored';
        }
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $data['table_prefix'])) {
            $errors[] = 'invalid table prefix';
        }
        if (!preg_match('/^\d{10}(\.\d{1,2})?$/', $data['moodle_version'])) {
            $errors[] = 'invalid Moodle version';
        }
        foreach (['moodle', 'moodledata', 'database'] as $component) {
            if (($data['package_contents'][$component] ?? null) !== true) {
                $errors[] = "the package does not include {$component}; only complete packages can be installed";
            }
        }
        $dump = $data['database_dump'];
        if (!is_array($dump) || ($dump['format'] ?? null) !== 'mysql' || ($dump['format_version'] ?? null) !== 1 ||
                ($dump['compression'] ?? null) !== 'gzip' || ($dump['consistency'] ?? null) !== 'snapshot' ||
                !is_int($dump['max_statement_bytes'] ?? null)) {
            $errors[] = 'unsupported database dump description';
        }
        foreach (['moodle', 'moodledata', 'database'] as $component) {
            if (!is_array($data['statistics'][$component] ?? null)) {
                $errors[] = "missing statistics for {$component}";
            }
        }
        if (!filter_var($data['wwwroot'], FILTER_VALIDATE_URL)) {
            $errors[] = 'invalid source wwwroot';
        }
        return $errors;
    }

    /**
     * Keys that look like secrets, anywhere in the tree. The top-level installer_auth object (a public salt and a
     * derived verifier) is exempt and validated by its own strict schema.
     *
     * @param array $data
     * @param string $prefix
     * @return string[]
     */
    private static function forbidden_keys(array $data, string $prefix = ''): array {
        $found = [];
        foreach ($data as $key => $value) {
            if ($prefix === '' && $key === 'installer_auth') {
                continue;
            }
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_string($key) && preg_match('/pass(word|wd)?|secret|token|api_?key|private_?key|salt|cookie|session|credential/i', $key)) {
                $found[] = $path;
            }
            if (is_array($value)) {
                $found = array_merge($found, self::forbidden_keys($value, $path));
            }
        }
        return $found;
    }
}

/**
 * How the installer authorizes its user, as described by the package (manifest "installer_auth").
 *
 * password: a salted PBKDF2-HMAC-SHA256 verifier the installer checks offline. keyfile: a key written into the
 * installation directory (the Phase 3 mechanism, and what packages of format 2 use).
 */
class auth_verifier {

    public const MODE_PASSWORD = 'password';
    public const MODE_KEYFILE = 'keyfile';
    public const MIN_ITERATIONS = 100000;
    public const MAX_ITERATIONS = 2000000;

    /**
     * Problems with an installer_auth description (the same rules as tool_moodleclone's installer_auth::validate()).
     *
     * @param mixed $data
     * @return string[]
     */
    public static function validate($data): array {
        if (!is_array($data)) {
            return ['installer_auth is not an object'];
        }
        $mode = $data['mode'] ?? null;
        if ($mode !== self::MODE_PASSWORD && $mode !== self::MODE_KEYFILE) {
            return ['installer_auth.mode is not valid'];
        }
        $expected = $mode === self::MODE_PASSWORD ? ['mode', 'kdf', 'iterations', 'salt', 'verifier'] : ['mode'];
        $errors = [];
        if (array_diff(array_keys($data), $expected) || array_diff($expected, array_keys($data))) {
            return ['installer_auth has unexpected or missing keys'];
        }
        if ($mode === self::MODE_KEYFILE) {
            return [];
        }
        if ($data['kdf'] !== 'pbkdf2-sha256') {
            $errors[] = 'installer_auth.kdf is not supported';
        }
        if (!is_int($data['iterations']) || $data['iterations'] < self::MIN_ITERATIONS || $data['iterations'] > self::MAX_ITERATIONS) {
            $errors[] = 'installer_auth.iterations is out of range';
        }
        foreach (['salt' => 16, 'verifier' => 32] as $key => $bytes) {
            $raw = is_string($data[$key]) ? base64_decode($data[$key], true) : false;
            if ($raw === false || strlen($raw) !== $bytes || base64_encode($raw) !== $data[$key]) {
                $errors[] = "installer_auth.{$key} is not valid";
            }
        }
        return $errors;
    }

    /**
     * Read the authorization description of a package without trusting anything else in it.
     *
     * Used before anyone has authenticated, so it reads a single bounded entry (manifest.json) and returns nothing
     * about the package's content. Anything unexpected returns null, and callers must then refuse (fail closed) and
     * never fall back to a weaker mode.
     *
     * @param string $path
     * @return array|null The validated installer_auth description; null when the package cannot be used.
     */
    public static function from_package(string $path): ?array {
        $zip = new \ZipArchive();
        $flags = defined('ZipArchive::RDONLY') ? \ZipArchive::RDONLY : 0;
        if ($zip->open($path, $flags) !== true) {
            return null;
        }
        try {
            $stat = $zip->statName('manifest.json');
            if ($stat === false || $stat['size'] > 1048576) {
                return null;
            }
            $json = $zip->getFromName('manifest.json', 1048577);
        } finally {
            $zip->close();
        }
        $data = is_string($json) ? json_decode($json, true, 16) : null;
        if (!is_array($data) || ($data['product'] ?? null) !== PRODUCT) {
            return null;
        }
        if (($data['format'] ?? null) === 2) {
            // Packages written before the installer password existed: the key file.
            return ['mode' => self::MODE_KEYFILE];
        }
        if (($data['format'] ?? null) !== 3 || self::validate($data['installer_auth'] ?? null)) {
            return null;
        }
        return $data['installer_auth'];
    }

    /**
     * Whether a password matches a password-mode description. Constant-time comparison.
     *
     * @param array $auth A validated description.
     * @param string $password
     * @return bool
     */
    public static function verify_password(array $auth, string $password): bool {
        if (($auth['mode'] ?? null) !== self::MODE_PASSWORD) {
            return false;
        }
        $derived = hash_pbkdf2('sha256', $password, (string) base64_decode($auth['salt'], true), $auth['iterations'], 32, true);
        return hash_equals((string) base64_decode($auth['verifier'], true), $derived);
    }
}

/**
 * Server-side authorization state that must survive between requests and between sessions: failed attempts, and
 * which installer sessions are authorized.
 *
 * One JSON file in the installation directory, behind a "<?php exit; ?>" line, written under an exclusive lock:
 *
 * - Failed attempts are counted per client (a hash of the remote address) and globally. An attempt is recorded
 *   BEFORE the password is checked and removed only if it was right, so parallel requests cannot all pass the check
 *   while the counter still reads zero. Clients lock out with exponential back-off; a global ceiling stops
 *   distributed guessing. A correct password is refused while locked, so the lock cannot be used as an oracle.
 * - Authorized sessions are recorded as the SHA-256 of a random 256-bit token (the token itself lives only in the
 *   visitor's PHP session), with the packages that visitor may install, an idle and an absolute expiry, and a hash
 *   of the User-Agent. Deleting this file revokes every session at once, which is what finishing an installation
 *   does. Nothing in it is secret: it holds counters and one-way hashes.
 *
 * It fails closed: if the file cannot be read or written, nobody is authorized.
 */
class auth_store {

    public const FREE_FAILURES = 4;
    public const BASE_LOCK = 30;
    public const MAX_LOCK = 900;
    public const FORGET_AFTER = 3600;
    public const GLOBAL_WINDOW = 900;
    public const GLOBAL_MAX = 30;
    public const GLOBAL_LOCK = 300;
    public const IDLE_TTL = 1800;
    public const ABSOLUTE_TTL = 43200;
    public const MAX_SESSIONS = 20;
    public const MAX_CLIENTS = 200;
    private const GUARD = "<?php exit; ?>\n";

    /** @var string */
    private $file;

    /** @var callable */
    private $clock;

    /**
     * @param string $file Path of the state file.
     * @param callable|null $clock Returns the current Unix time (tests).
     */
    public function __construct(string $file, ?callable $clock = null) {
        $this->file = $file;
        $this->clock = $clock ?? 'time';
    }

    /**
     * Reserve one password attempt for a client.
     *
     * @param string $client Identifier of the client (already hashed).
     * @return array ['allowed' => bool, 'retry' => seconds to wait when refused, 'ticket' => string for succeeded()]
     */
    public function begin_attempt(string $client): array {
        $result = ['allowed' => false, 'retry' => 0, 'ticket' => ''];
        $this->transaction(function(array &$state, int $now) use ($client, &$result) {
            $c = $state['clients'][$client] ?? null;
            $c = is_array($c) ? array_map('intval', $c) + ['fails' => 0, 'last' => 0, 'until' => 0] : ['fails' => 0, 'last' => 0, 'until' => 0];
            if ($c['last'] < $now - self::FORGET_AFTER) {
                $c = ['fails' => 0, 'last' => 0, 'until' => 0];
            }
            $state['global']['times'] = array_values(array_filter($state['global']['times'] ?? [], function($t) use ($now) {
                return $t > $now - self::GLOBAL_WINDOW;
            }));
            $wait = max($c['until'] - $now, ($state['global']['until'] ?? 0) - $now);
            if ($wait > 0) {
                $result['retry'] = $wait;
                return;
            }
            $c['fails']++;
            $c['last'] = $now;
            if ($c['fails'] > self::FREE_FAILURES) {
                $c['until'] = $now + min(self::MAX_LOCK, self::BASE_LOCK * (2 ** ($c['fails'] - self::FREE_FAILURES - 1)));
            }
            $state['clients'][$client] = $c;
            $ticket = sprintf('%d.%s', $now, bin2hex(random_bytes(4)));
            $state['global']['times'][] = $now;
            if (count($state['global']['times']) > self::GLOBAL_MAX) {
                $state['global']['until'] = $now + self::GLOBAL_LOCK;
            }
            $result = ['allowed' => true, 'retry' => 0, 'ticket' => $ticket];
        });
        return $result;
    }

    /**
     * The reserved attempt was a correct password: give it back, and forget the client's failures.
     *
     * @param string $client
     * @param string $ticket From begin_attempt().
     * @return void
     */
    public function succeeded(string $client, string $ticket): void {
        $this->transaction(function(array &$state, int $now) use ($client, $ticket) {
            unset($state['clients'][$client]);
            $times = $state['global']['times'] ?? [];
            $key = array_search((int) $ticket, $times, true);
            if ($key !== false) {
                unset($times[$key]);
            }
            $state['global']['times'] = array_values($times);
            if (count($state['global']['times']) <= self::GLOBAL_MAX) {
                unset($state['global']['until']);
            }
        });
    }

    /**
     * Authorize a new session.
     *
     * @param string[] $packages Package file names this visitor may install.
     * @param string $useragent
     * @return string The token to keep in the visitor's session (64 hex characters).
     */
    public function create_session(array $packages, string $useragent): string {
        $token = bin2hex(random_bytes(32));
        $this->transaction(function(array &$state, int $now) use ($token, $packages, $useragent) {
            $sessions = $this->live_sessions($state, $now);
            $sessions[hash('sha256', $token)] = ['idle' => $now + self::IDLE_TTL, 'abs' => $now + self::ABSOLUTE_TTL,
                'packages' => array_values($packages), 'ua' => substr(hash('sha256', $useragent), 0, 32)];
            if (count($sessions) > self::MAX_SESSIONS) {
                uasort($sessions, function($a, $b) {
                    return $b['abs'] <=> $a['abs'];
                });
                $sessions = array_slice($sessions, 0, self::MAX_SESSIONS, true);
            }
            $state['sessions'] = $sessions;
        });
        return $token;
    }

    /**
     * Whether a token is an authorized session, and for which packages. Extends the idle expiry.
     *
     * @param string $token
     * @param string $useragent
     * @return string[]|null Package names, or null when the token is unknown, expired, revoked or from another browser.
     */
    public function session_packages(string $token, string $useragent): ?array {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $found = null;
        $this->transaction(function(array &$state, int $now) use ($token, $useragent, &$found) {
            $state['sessions'] = $this->live_sessions($state, $now);
            $key = hash('sha256', $token);
            $entry = $state['sessions'][$key] ?? null;
            if ($entry === null || !hash_equals($entry['ua'], substr(hash('sha256', $useragent), 0, 32))) {
                return;
            }
            $state['sessions'][$key]['idle'] = min($now + self::IDLE_TTL, $entry['abs']);
            $found = $entry['packages'];
        }, false);
        return $found;
    }

    /**
     * Revoke every session and forget every failure: the state file is removed.
     *
     * @return bool Whether nothing authorizes anyone any more.
     */
    public function destroy(): bool {
        if (!file_exists($this->file)) {
            return true;
        }
        if (@unlink($this->file)) {
            return true;
        }
        // Cannot delete: at least empty it, which revokes all sessions.
        return @file_put_contents($this->file, self::GUARD . "{}\n", LOCK_EX) !== false;
    }

    /**
     * Sessions that have not expired.
     *
     * @param array $state
     * @param int $now
     * @return array
     */
    private function live_sessions(array $state, int $now): array {
        return array_filter($state['sessions'] ?? [], function($s) use ($now) {
            return is_array($s) && is_int($s['idle'] ?? null) && is_int($s['abs'] ?? null) && $s['idle'] > $now &&
                $s['abs'] > $now && is_array($s['packages'] ?? null) && is_string($s['ua'] ?? null);
        });
    }

    /**
     * Read, change and write the state under an exclusive lock.
     *
     * @param callable $change function(array &$state, int $now): void
     * @param bool $create Create the file when missing (false: a missing file means "nobody is authorized").
     * @return void
     */
    private function transaction(callable $change, bool $create = true): void {
        $exists = file_exists($this->file);
        if (!$exists && !$create) {
            return;
        }
        $fh = @fopen($this->file, 'c+');
        if (!$fh || !flock($fh, LOCK_EX)) {
            throw new installer_exception('The installer cannot store its authorization state here. The installation ' .
                'directory must be writable by the web server user.');
        }
        try {
            $raw = (string) stream_get_contents($fh);
            $json = strpos($raw, self::GUARD) === 0 ? substr($raw, strlen(self::GUARD)) : '';
            $state = json_decode($json, true, 8);
            if (!is_array($state)) {
                $state = [];
            }
            $state += ['clients' => [], 'global' => [], 'sessions' => []];
            // A damaged file must not be able to break the checks: anything of the wrong shape is forgotten (which
            // only ever means "nobody is authorized" and "no failures recorded").
            foreach (['clients', 'global', 'sessions'] as $key) {
                if (!is_array($state[$key])) {
                    $state[$key] = [];
                }
            }
            if (!is_array($state['global']['times'] ?? [])) {
                $state['global']['times'] = [];
            }
            $change($state, (int) ($this->clock)());
            if (count($state['clients']) > self::MAX_CLIENTS) {
                uasort($state['clients'], function($a, $b) {
                    return (int) ($b['last'] ?? 0) <=> (int) ($a['last'] ?? 0);
                });
                $state['clients'] = array_slice($state['clients'], 0, self::MAX_CLIENTS, true);
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, self::GUARD . json_encode($state) . "\n");
            fflush($fh);
            @chmod($this->file, 0600);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

/**
 * Structural verification of a package, without extracting anything.
 */
class package {

    /**
     * Open a package read-only with consistency checks.
     *
     * @param string $path
     * @return \ZipArchive
     */
    public static function open(string $path): \ZipArchive {
        $zip = new \ZipArchive();
        $flags = \ZipArchive::CHECKCONS;
        if (defined('ZipArchive::RDONLY')) {
            $flags |= \ZipArchive::RDONLY;
        }
        $result = $zip->open($path, $flags);
        if ($result !== true) {
            throw new installer_exception("The package is not a readable ZIP archive (error {$result}). It may be incomplete or corrupt.");
        }
        return $zip;
    }

    /**
     * Validate every entry, the manifest and the checksum list's structure.
     *
     * Rejects: names that are absolute, contain "..", backslashes or control
     * characters; anything outside the package layout; symbolic links and
     * special files; config.php and names that would overwrite the installer's
     * own files. Checks that checksums.sha256 lists every regular file exactly
     * once in archive order and that the manifest matches the archive.
     *
     * @param string $path
     * @param string[] $reserved Names in the installation directory the package must not overwrite.
     * @return array Package information.
     */
    public static function scan(string $path, array $reserved): array {
        $zip = self::open($path);
        try {
            $counts = [];
            foreach (['moodle', 'moodledata'] as $component) {
                $counts[$component] = ['files' => 0, 'directories' => 0, 'symlinks' => 0, 'bytes' => 0];
            }
            $files = [];
            $hasdatabase = false;
            $databasesize = 0;
            $hashtaccess = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                $isdir = substr($name, -1) === '/';
                $check = $isdir ? substr($name, 0, -1) : $name;
                if (!paths::is_safe_relative($check)) {
                    throw new installer_exception('Unsafe entry name in package: ' . rawurlencode($name));
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                $type = ($attr >> 16) & 0170000;
                if ($opsys === \ZipArchive::OPSYS_UNIX && $type === 0120000) {
                    throw new installer_exception('The package contains a symbolic link (' . rawurlencode($name) .
                        '). Symbolic links are not restored.');
                }
                if ($opsys === \ZipArchive::OPSYS_UNIX && !$isdir && $type !== 0 && $type !== 0100000) {
                    throw new installer_exception('The package contains a special file: ' . rawurlencode($name));
                }
                $top = explode('/', $check, 2)[0];
                if ($isdir) {
                    if (!in_array($top, ['moodle', 'moodledata'], true)) {
                        throw new installer_exception('Unexpected directory in package: ' . rawurlencode($name));
                    }
                    $counts[$top]['directories']++;
                    continue;
                }
                if (in_array($name, ['manifest.json', 'checksums.sha256'], true)) {
                    if ($name === 'manifest.json') {
                        $files[] = $i;
                    }
                    continue;
                }
                if ($name === 'database.sql.gz') {
                    $hasdatabase = true;
                    $databasesize = (int) $stat['size'];
                    $files[] = $i;
                    continue;
                }
                if (!in_array($top, ['moodle', 'moodledata'], true) || strpos($check, '/') === false) {
                    throw new installer_exception('Unexpected entry in package: ' . rawurlencode($name));
                }
                if ($top === 'moodle') {
                    $relative = substr($name, 7);
                    if ($relative === 'config.php') {
                        throw new installer_exception('The package contains config.php. Packages must never include it.');
                    }
                    if (in_array($relative, $reserved, true)) {
                        throw new installer_exception('The package contains a file that would overwrite the installer: ' .
                            rawurlencode($name));
                    }
                    if ($relative === '.htaccess') {
                        $hashtaccess = true;
                    }
                }
                $counts[$top]['files']++;
                $counts[$top]['bytes'] += (int) $stat['size'];
                $files[] = $i;
            }
            if (!$hasdatabase) {
                throw new installer_exception('The package has no database.sql.gz.');
            }

            $json = $zip->getFromName('manifest.json');
            if ($json === false || strlen($json) > 1048576) {
                throw new installer_exception('The package has no valid manifest.json.');
            }
            $manifest = json_decode($json, true, 16);
            $errors = manifest_check::validate($manifest);
            if ($errors) {
                throw new installer_exception('manifest.json is not valid: ' . implode('; ', $errors));
            }
            foreach (['moodle', 'moodledata'] as $component) {
                foreach (['files', 'directories', 'symlinks'] as $key) {
                    if ((int) ($manifest['statistics'][$component][$key] ?? -1) !== $counts[$component][$key]) {
                        throw new installer_exception("The archive does not match manifest.json ({$component} {$key}).");
                    }
                }
            }

            $list = $zip->getStream('checksums.sha256');
            if ($list === false) {
                throw new installer_exception('The package has no checksums.sha256.');
            }
            try {
                $line = 0;
                foreach ($files as $index) {
                    $line++;
                    $text = fgets($list);
                    if ($text === false || !preg_match('/^([0-9a-f]{64})  (.+)\n$/', $text, $m) ||
                            $m[2] !== $zip->getNameIndex($index)) {
                        throw new installer_exception("checksums.sha256 does not match the archive (line {$line}).");
                    }
                }
                if (fgets($list) !== false) {
                    throw new installer_exception('checksums.sha256 lists entries that are not in the archive.');
                }
            } finally {
                fclose($list);
            }

            return [
                'manifest' => $manifest,
                'counts' => $counts,
                'entries' => $zip->numFiles,
                'filecount' => count($files),
                'databasesize' => $databasesize,
                'hashtaccess' => $hashtaccess,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Expected SHA-256 from the .sha256 file next to the package.
     *
     * @param string $path Package path.
     * @return string|null Null when there is no sidecar.
     */
    public static function sidecar_hash(string $path): ?string {
        $sidecar = $path . '.sha256';
        if (!is_file($sidecar)) {
            return null;
        }
        $content = @file_get_contents($sidecar, false, null, 0, 4096);
        if ($content === false) {
            throw new installer_exception('The .sha256 file next to the package cannot be read by the web server. Check its ' .
                'permissions (the web server user must be able to read it), or remove it to continue without it.');
        }
        if (!preg_match('/^([0-9a-f]{64})\s+\*?(\S+)\s*$/', trim($content), $m) || $m[2] !== basename($path)) {
            throw new installer_exception('The .sha256 file next to the package is not valid.');
        }
        return $m[1];
    }
}

/**
 * Reads SQL statements from the dump, one at a time, from a byte offset.
 *
 * A statement ends at a ";" at the end of a line that is outside quotes and
 * backticks. The dump's INSERT statements are single lines of hex literals and
 * CREATE TABLE statements span lines, so this is exact for the format and
 * robust against ";" inside comments or strings.
 */
class sql_reader {

    /** @var resource */
    private $fh;

    /**
     * Constructor.
     *
     * @param string $path
     * @param int $offset Byte offset of the next statement.
     */
    public function __construct(string $path, int $offset) {
        $this->fh = @fopen($path, 'rb');
        if (!$this->fh || fseek($this->fh, $offset) !== 0) {
            throw new installer_exception('The extracted database dump cannot be read.');
        }
    }

    /**
     * Current byte offset (start of the next statement).
     *
     * @return int
     */
    public function offset(): int {
        return (int) ftell($this->fh);
    }

    /**
     * Next statement without its terminating ";", or null at the end.
     *
     * Comment lines ("-- ...") are returned as-is so the caller can see the completion marker.
     *
     * @return string|null
     */
    public function next(): ?string {
        $statement = '';
        $quote = null;
        while (($line = fgets($this->fh)) !== false) {
            if ($statement === '' && $quote === null && strpos($line, '--') === 0) {
                return rtrim($line, "\r\n");
            }
            if ($statement === '' && $quote === null && trim($line) === '') {
                continue;
            }
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $c = $line[$i];
                if ($quote !== null) {
                    if ($c === '\\' && $quote !== '`') {
                        $i++;
                    } else if ($c === $quote) {
                        if ($i + 1 < $length && $line[$i + 1] === $quote) {
                            $i++;
                        } else {
                            $quote = null;
                        }
                    }
                } else if ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                }
            }
            $statement .= $line;
            if ($quote === null && substr(rtrim($line, "\r\n"), -1) === ';') {
                return substr(rtrim($statement, "\r\n"), 0, -1);
            }
        }
        if (trim($statement) !== '') {
            throw new installer_exception('The database dump ends in the middle of a statement.');
        }
        return null;
    }

    /**
     * Close.
     *
     * @return void
     */
    public function close(): void {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }
}

/**
 * Allow-list for dump statements: only the statement shapes tool_moodleclone's
 * dumper writes are executed, and only on tables with the package's prefix.
 * Anything else (another statement type, other tables, function calls in
 * values, DATA DIRECTORY, other engines...) stops the restore.
 */
class sql_guard {

    /** @var string[] Session settings the dump may set, exactly. */
    private const SETS = [
        '/^SET NAMES [a-z0-9_]+$/',
        "/^SET time_zone = '\\+00:00'$/",
        '/^SET foreign_key_checks = [01]$/',
        '/^SET unique_checks = [01]$/',
        "/^SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'$/",
    ];

    /** @var string A literal the dumper can produce. */
    private const LITERAL = "(?:NULL|-?(?:\\d+\\.?\\d*|\\.\\d+)(?:[eE][-+]?\\d+)?|(?:_[a-z0-9]+ )?X'[0-9a-f]*+')";

    /** @var string */
    private $prefix;

    /**
     * Constructor.
     *
     * @param string $prefix Table prefix from the manifest.
     */
    public function __construct(string $prefix) {
        $this->prefix = $prefix;
    }

    /**
     * Classify a statement or throw when it is not allowed.
     *
     * @param string $sql Without the trailing ";".
     * @return array ['type' => set|drop|create|insert, 'table' => ?string, 'rows' => int]
     */
    public function check(string $sql): array {
        foreach (self::SETS as $pattern) {
            if (preg_match($pattern, $sql)) {
                return ['type' => 'set', 'table' => null, 'rows' => 0];
            }
        }
        $table = '`' . preg_quote($this->prefix, '/') . '[a-z0-9_]+`';
        if (preg_match('/^DROP TABLE IF EXISTS (' . $table . ')$/', $sql, $m)) {
            return ['type' => 'drop', 'table' => trim($m[1], '`'), 'rows' => 0];
        }
        if (preg_match('/^CREATE TABLE (' . $table . ') \(/', $sql, $m)) {
            if (!preg_match('/\n\) ENGINE=InnoDB[^\n]*$/', $sql) ||
                    preg_match('/\b(DATA|INDEX) DIRECTORY\b|\bCONNECTION\s*=|\bTABLESPACE\b|\bENCRYPTION\s*=/i', $sql)) {
                throw new installer_exception('The dump contains a table definition with options the installer does not allow: ' .
                    $m[1]);
            }
            return ['type' => 'create', 'table' => trim($m[1], '`'), 'rows' => 0];
        }
        if (preg_match('/^INSERT INTO (' . $table . ') \((`[A-Za-z0-9_]+`(?:,`[A-Za-z0-9_]+`)*+)\) VALUES /', $sql, $m)) {
            $offset = strlen($m[0]);
            $rows = 0;
            $tuple = '/\G\(' . self::LITERAL . '(?:,' . self::LITERAL . ')*+\)(,|$)/A';
            while (true) {
                if (!preg_match($tuple, $sql, $t, 0, $offset)) {
                    throw new installer_exception('The dump contains an INSERT with values the installer does not allow (' .
                        $m[1] . ').');
                }
                $rows++;
                $offset += strlen($t[0]);
                if ($t[1] === '') {
                    break;
                }
            }
            if ($offset !== strlen($sql)) {
                throw new installer_exception('Malformed INSERT statement for ' . $m[1] . '.');
            }
            return ['type' => 'insert', 'table' => trim($m[1], '`'), 'rows' => $rows];
        }
        throw new installer_exception('The dump contains a statement the installer does not allow: ' .
            substr(preg_replace('/\s+/', ' ', $sql), 0, 80));
    }
}

/**
 * MySQL access for the restore (mysqli; Moodle is not loaded yet).
 */
class db {

    /**
     * Connect.
     *
     * @param array $settings dbhost, dbname, dbuser, dbpass.
     * @param bool $selectdb
     * @return \mysqli
     */
    public static function connect(array $settings, bool $selectdb = true): \mysqli {
        mysqli_report(MYSQLI_REPORT_OFF);
        [$host, $port, $socket] = self::parse_host($settings['dbhost']);
        $db = mysqli_init();
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        if (!@$db->real_connect($host, $settings['dbuser'], $settings['dbpass'], $selectdb ? $settings['dbname'] : null,
                $port, $socket)) {
            // The driver message names the user and host, never the password.
            throw new installer_exception('Cannot connect to the database: ' . mysqli_connect_error());
        }
        if (!$db->set_charset('utf8mb4')) {
            throw new installer_exception('The database server does not support utf8mb4.');
        }
        return $db;
    }

    /**
     * Split "host", "host:port" or "localhost:/path/to.sock".
     *
     * @param string $value
     * @return array [host, port|null, socket|null]
     */
    public static function parse_host(string $value): array {
        if (preg_match('/^([^:]+):(\/.+)$/', $value, $m)) {
            return [$m[1], null, $m[2]];
        }
        if (preg_match('/^([^:]+):(\d+)$/', $value, $m)) {
            return [$m[1], (int) $m[2], null];
        }
        return [$value, null, null];
    }

    /**
     * Tables with the prefix.
     *
     * @param \mysqli $db
     * @param string $prefix
     * @return string[]
     */
    public static function prefixed_tables(\mysqli $db, string $prefix): array {
        $tables = [];
        $result = $db->query('SHOW TABLES');
        if ($result) {
            while ($row = $result->fetch_row()) {
                if (strpos($row[0], $prefix) === 0) {
                    $tables[] = $row[0];
                }
            }
        }
        return $tables;
    }

    /**
     * Run a statement or throw with the server's message.
     *
     * @param \mysqli $db
     * @param string $sql
     * @return void
     */
    public static function run(\mysqli $db, string $sql): void {
        if (!$db->query($sql)) {
            throw new installer_exception('Database error ' . $db->errno . ': ' . $db->error . ' (in: ' .
                substr(preg_replace('/\s+/', ' ', $sql), 0, 100) . ')');
        }
    }
}

/**
 * The installer: access control, wizard steps and the sliced restore.
 */
class installer {

    /** @var string Installation directory (becomes the new Moodle dirroot). */
    private $dir;

    /** @var array Session state. */
    private $state;

    /** @var float Request start. */
    private $started;

    /** @var bool The session was destroyed (installation finished); do not save state. */
    private $finished = false;

    /** @var string[] Package file names this visitor is authorized to install (from the server-side session registry). */
    private $authorized = [];

    /** @var auth_store|null */
    private $store = null;

    /** @var string Why the last login attempt was refused (shown on the login page only). */
    private $loginerror = '';

    /**
     * Entry point.
     *
     * @return void
     */
    public static function main(): void {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Open installer.php through the destination web server.\n");
            exit(1);
        }
        (new self())->handle();
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->dir = rtrim(str_replace('\\', '/', __DIR__), '/');
        $this->started = microtime(true);
    }

    /**
     * Handle one request.
     *
     * @return void
     */
    private function handle(): void {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'");

        if (is_file($this->dir . '/' . LOCK_FILE)) {
            $this->page('Installation finished', '<p>This installer has already completed an installation and is locked. ' .
                'Delete <code>' . h(basename(__FILE__)) . '</code>, <code>' . h(LOCK_FILE) . '</code> and any remaining ' .
                'package files from <code>' . h($this->dir) . '</code>.</p>');
            return;
        }

        $this->protect_directory();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
            'secure' => $this->is_https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        $this->state = $_SESSION['mci'] ?? [];
        if (empty($this->state['csrf'])) {
            $this->state['csrf'] = bin2hex(random_bytes(32));
        }

        try {
            $this->route();
        } catch (installer_exception $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('Unexpected error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
        // Every request persists its state (including the CSRF token issued on this page).
        if (!$this->finished && session_status() === PHP_SESSION_ACTIVE) {
            $this->save();
        }
    }

    /**
     * Dispatch.
     *
     * @return void
     */
    private function route(): void {
        if (!$this->is_authorized()) {
            $this->step_access();
            return;
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($this->state['csrf'], (string) ($_POST['csrf'] ?? ''))) {
                throw new installer_exception('The form has expired. Reload the page and try again.');
            }
        } else {
            $action = '';
        }

        $step = $this->state['step'] ?? 'package';
        if ($action === 'reset' && in_array($step, ['failed', 'run'], true)) {
            $this->do_reset();
            return;
        }
        if (is_file($this->dir . '/config.php') && empty($this->state['run']['configwritten']) && $step !== 'done') {
            throw new installer_exception('This directory already contains a Moodle config.php. The installer only installs into a ' .
                'clean directory and never overwrites an existing site.');
        }
        switch ($step) {
            case 'package':
                $this->step_package($action);
                break;
            case 'environment':
                $this->step_environment($action);
                break;
            case 'settings':
                $this->step_settings($action);
                break;
            case 'confirm':
                $this->step_confirm($action);
                break;
            case 'run':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    // A reload: continue only through the form (POST with the CSRF token).
                    $this->progress_page($this->task_label($this->state['run']['task'] ?? ''), $this->overall_progress(),
                        'Continuing...');
                    break;
                }
                $this->step_run();
                break;
            case 'failed':
                $this->show_failed();
                break;
            default:
                $this->state['step'] = 'package';
                $this->step_package('');
        }
    }

    /**
     * The server-side authorization state (failed attempts and authorized sessions).
     *
     * @return auth_store
     */
    private function store(): auth_store {
        if ($this->store === null) {
            $this->store = new auth_store($this->dir . '/' . AUTH_FILE);
        }
        return $this->store;
    }

    /**
     * Whether this visitor's session was authorized by a correct password (or key) and is still valid.
     *
     * The session only carries a random token; whether it is authorized, for which packages, and until when is kept
     * on the server (auth_store), so nothing a visitor can send makes them authorized, and deleting the state file
     * revokes everyone.
     *
     * @return bool
     */
    private function is_authorized(): bool {
        $token = $this->state['authtoken'] ?? '';
        if (!is_string($token) || $token === '') {
            return false;
        }
        $packages = $this->store()->session_packages($token, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($packages === null) {
            // Leave the session as it is: this request may come from someone holding a stolen cookie, and a refused check
            // must not be able to log the real user out. The token simply stays useless (expired, revoked or another browser).
            return false;
        }
        $this->authorized = $packages;
        return true;
    }

    /**
     * The packages next to the installer that this visitor may use.
     *
     * @return string[]
     */
    private function authorized_packages(): array {
        return array_values(array_intersect($this->packages(), $this->authorized));
    }

    /**
     * Identifier of the client for rate limiting: a hash of the remote address.
     *
     * @return string
     */
    private function client_id(): string {
        return substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 16);
    }

    /**
     * Access: authorize the visitor before the installer does anything.
     *
     * The package says how (its manifest's installer_auth): with the installer password chosen when it was created,
     * checked offline against a salted PBKDF2 verifier; or, for the explicit alternative and for packages made before
     * passwords existed, with a key written into this directory. Nothing about the package is shown until then, and
     * a wrong answer looks the same whatever the reason. Attempts are counted on the server before they are checked.
     *
     * @return void
     */
    private function step_access(): void {
        if (is_file($this->dir . '/config.php')) {
            // A finished (or foreign) Moodle lives here: there is nothing to authorize and nothing this installer may do.
            $this->page('Moodle Clone installer', '<p>This folder already contains a Moodle <code>config.php</code>. The ' .
                'installer only installs into an empty folder and never touches an existing site. If the installation has ' .
                'finished, delete <code>' . h(basename(__FILE__)) . '</code> and any package files from this folder.</p>');
            return;
        }
        $packages = $this->packages();
        if (!$packages) {
            $this->page('Moodle Clone installer', '<p>No <code>moodle-clone-YYYY-MM-DD-HHMMSS.zip</code> package was found ' .
                'next to <code>' . h(basename(__FILE__)) . '</code>. Copy the package (and its <code>.sha256</code> file) into ' .
                'this folder, then reload this page.</p>');
            return;
        }
        $profiles = [];
        foreach (array_slice($packages, 0, 8) as $name) {
            $auth = auth_verifier::from_package($this->dir . '/' . $name);
            if ($auth !== null) {
                $profiles[$name] = $auth;
            }
        }
        if (!$profiles) {
            // Fail closed: an unreadable package never falls back to a weaker way in.
            $this->page('Moodle Clone installer', '<p class="err">The package cannot be read. If it is still being ' .
                'uploaded, wait until the upload has finished and reload this page.</p>');
            return;
        }
        $modes = array_column($profiles, 'mode');
        $askpassword = in_array(auth_verifier::MODE_PASSWORD, $modes, true);
        $askkey = in_array(auth_verifier::MODE_KEYFILE, $modes, true);
        if ($askkey) {
            $this->ensure_key_file();
        }

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($this->state['csrf'], (string) ($_POST['csrf'] ?? ''))) {
                $error = 'The form has expired. Reload the page and try again.';
            } else if ($this->try_authorize($profiles)) {
                return;
            } else {
                $error = $this->loginerror;
            }
        }

        $body = '';
        if ($error !== '') {
            $body .= '<p class="err">' . h($error) . '</p>';
        }
        if (!$this->is_https() && !in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
            $body .= '<p class="warn">This connection is not encrypted (no HTTPS): what you type here can be read on the ' .
                'network. Use HTTPS if you can.</p>';
        }
        $body .= '<form method="post" autocomplete="off">' . $this->csrf();
        if ($askpassword) {
            $body .= '<p>Enter the <b>installer password</b> that was chosen when this package was created on the source site. ' .
                'It is checked here, against the package; nothing has to be read on the server.</p>' .
                '<p><label>Installer password <input type="password" name="password" autocomplete="off" size="32" maxlength="1024" ' .
                'autofocus></label></p>';
        }
        if ($askkey) {
            $body .= '<p>' . ($askpassword ? 'For packages made without an installer password: ' : '') . 'this installer has ' .
                'written a one-time key into a file named <code>' . h(KEY_FILE) . '</code>, in the same folder as ' .
                '<code>' . h(basename(__FILE__)) . '</code>. Read the file on the server (hosting file manager, FTP or SSH) ' .
                'and enter the key.</p><p><label>Key <input name="key" autocomplete="off" size="40" maxlength="128"></label></p>';
        }
        $this->page('Moodle Clone installer', $body . '<p><button>Continue</button></p></form>');
    }

    /**
     * Check a submitted password or key, counting the attempt first.
     *
     * On success the visitor's session is authorized and the response is a redirect; on failure $this->loginerror
     * says why, without revealing which part was wrong.
     *
     * @param array[] $profiles Package name => installer_auth description.
     * @return bool
     */
    private function try_authorize(array $profiles): bool {
        $client = $this->client_id();
        $attempt = $this->store()->begin_attempt($client);
        if (!$attempt['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . $attempt['retry']);
            $this->loginerror = 'Too many failed attempts. Try again in ' . $attempt['retry'] . ' seconds.';
            return false;
        }
        $password = (string) ($_POST['password'] ?? '');
        $key = trim((string) ($_POST['key'] ?? ''));
        $expectedkey = null;
        $authorized = [];
        foreach ($profiles as $name => $auth) {
            if ($auth['mode'] === auth_verifier::MODE_PASSWORD) {
                if ($password !== '' && strlen($password) <= 1024 && auth_verifier::verify_password($auth, $password)) {
                    $authorized[] = $name;
                }
                continue;
            }
            if ($expectedkey === null) {
                $expectedkey = trim((string) preg_replace('/^<\?php exit; \?>\s*/', '', (string) @file_get_contents($this->dir .
                    '/' . KEY_FILE)));
            }
            if ($key !== '' && $expectedkey !== '' && hash_equals($expectedkey, $key)) {
                $authorized[] = $name;
            }
        }
        if (!$authorized) {
            usleep(400000);
            http_response_code(403);
            $this->loginerror = 'The password (or key) is not correct.';
            return false;
        }
        $this->store()->succeeded($client, $attempt['ticket']);
        session_regenerate_id(true);
        $this->state['authtoken'] = $this->store()->create_session($authorized, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (empty($this->state['run'])) {
            unset($this->state['package'], $this->state['hash'], $this->state['info']);
            $this->state['step'] = 'package';
        }
        // (A restore already under way, for example after the idle timeout, carries on from where it was.)
        $this->save();
        $this->redirect();
        return true;
    }

    /**
     * Key file for packages that use the key-file mode (created once, only when such a package exists).
     *
     * @return void
     */
    private function ensure_key_file(): void {
        $keyfile = $this->dir . '/' . KEY_FILE;
        if (is_file($keyfile)) {
            return;
        }
        $key = bin2hex(random_bytes(16));
        if (@file_put_contents($keyfile, "<?php exit; ?>\n" . $key . "\n", LOCK_EX) === false) {
            throw new installer_exception('The installer cannot write its key file. The installation directory must be ' .
                'writable by the web server user.');
        }
        @chmod($keyfile, 0600);
    }

    /**
     * Keep the web server from serving anything but the installer while it is here (Apache: a temporary .htaccess).
     *
     * Done on the first request, before anyone has authenticated, so the package (which holds the whole site) and the
     * installer's own files cannot be downloaded by guessing their names. Other web servers ignore .htaccess: keep
     * the package out of the web root there (see the README).
     *
     * @return void
     */
    private function protect_directory(): void {
        $file = $this->dir . '/.htaccess';
        // Only in a folder that holds nothing but the installer and its package(s): never over an installed Moodle, and
        // never in a folder with other content (a deny-all rule would take that down with it).
        if (is_file($file) || !is_writable($this->dir) || is_file($this->dir . '/config.php') || $this->unexpected_files()) {
            return;
        }
        $script = str_replace('"', '', basename(__FILE__));
        @file_put_contents($file, HTACCESS_MARK . "\n# Only the installer is reachable while it is here.\n" .
            "<IfModule mod_authz_core.c>\nRequire all denied\n<Files \"{$script}\">\nRequire all granted\n</Files>\n</IfModule>\n");
    }

    /**
     * Step 1: choose and verify the package.
     *
     * @param string $action
     * @return void
     */
    private function step_package(string $action): void {
        $packages = $this->authorized_packages();
        if (!$packages) {
            throw new installer_exception('No moodle-clone-YYYY-MM-DD-HHMMSS.zip package was found next to the installer in ' .
                $this->dir . '.');
        }
        if ($action === 'package') {
            $chosen = (string) ($_POST['package'] ?? '');
            if (!in_array($chosen, $packages, true)) {
                throw new installer_exception('Choose one of the listed packages.');
            }
            $this->state['package'] = $chosen;
            unset($this->state['hash'], $this->state['info']);
        }
        if (empty($this->state['package']) && count($packages) === 1) {
            $this->state['package'] = $packages[0];
        }
        if (empty($this->state['package'])) {
            $options = '';
            foreach ($packages as $name) {
                $options .= '<p><label><input type="radio" name="package" value="' . h($name) . '" required> ' . h($name) .
                    ' (' . size(filesize($this->dir . '/' . $name)) . ')</label></p>';
            }
            $this->page('Choose the package', '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" ' .
                'value="package">' . $options . '<button>Continue</button></form>');
            return;
        }

        $path = $this->dir . '/' . $this->state['package'];
        // 1a. SHA-256 of the whole package against its .sha256 file (resumable on PHP 8).
        if (empty($this->state['hash']['done'])) {
            $expected = package::sidecar_hash($path);
            if ($expected === null) {
                if ($action !== 'nosidecar') {
                    $this->page('Package checksum', '<p class="warn">There is no <code>' . h($this->state['package']) .
                        '.sha256</code> file, so the transfer of the package itself cannot be verified. Every file inside it is ' .
                        'still verified against checksums.sha256 during the restore.</p><form method="post">' . $this->csrf() .
                        '<input type="hidden" name="action" value="nosidecar"><button>Continue without it</button></form>');
                    return;
                }
                $this->state['hash'] = ['done' => true, 'verified' => false];
            } else if (!$this->hash_package($path, $expected)) {
                return;
            }
        }
        // 1b. Structure, manifest and checksum list.
        if (empty($this->state['info'])) {
            $this->state['info'] = package::scan($path, $this->reserved_names());
        }
        $this->state['step'] = 'environment';
        $this->save();
        $this->redirect();
    }

    /**
     * Hash the package in slices; returns true when done.
     *
     * @param string $path
     * @param string $expected
     * @return bool
     */
    private function hash_package(string $path, string $expected): bool {
        $size = (int) filesize($path);
        $resumable = PHP_VERSION_ID >= 80000;
        $offset = (int) ($this->state['hash']['offset'] ?? 0);
        $context = ($resumable && !empty($this->state['hash']['context'])) ? unserialize($this->state['hash']['context'],
            ['allowed_classes' => [\HashContext::class]]) : hash_init('sha256');
        if (!$resumable || $offset === 0) {
            $context = hash_init('sha256');
            $offset = 0;
        }
        $fh = fopen($path, 'rb');
        fseek($fh, $offset);
        while (!feof($fh)) {
            $chunk = fread($fh, 8 * CHUNK);
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($context, $chunk);
            $offset += strlen($chunk);
            if ($resumable && $this->out_of_time()) {
                fclose($fh);
                $this->state['hash'] = ['offset' => $offset, 'context' => serialize($context)];
                $this->save();
                $this->progress_page('Verifying the package checksum', $size ? $offset / $size : 1, size($offset) . ' of ' .
                    size($size));
                return false;
            }
        }
        fclose($fh);
        if (!hash_equals($expected, hash_final($context))) {
            $this->state['hash'] = [];
            throw new installer_exception('The package does not match its .sha256 file. It was damaged or changed after it was ' .
                'created; copy it again.');
        }
        $this->state['hash'] = ['done' => true, 'verified' => true];
        return true;
    }

    /**
     * Step 2: environment checks.
     *
     * @param string $action
     * @return void
     */
    private function step_environment(string $action): void {
        $checks = $this->environment_checks();
        $errors = array_filter($checks, function($c) {
            return $c[0] === 'error';
        });
        if ($action === 'environment' && !$errors) {
            $this->state['step'] = 'settings';
            $this->save();
            $this->redirect();
            return;
        }
        $info = $this->state['info'];
        $m = $info['manifest'];
        $body = '<table><tr><th>Package</th><td>' . h($this->state['package']) .
            ($this->state['hash']['verified'] ? ' <span class="ok">SHA-256 verified</span>' : ' <span class="warn">no .sha256 file</span>') .
            '</td></tr><tr><th>Created</th><td>' . h($m['created']) . ' by tool_moodleclone ' . h($m['generator']['release']) .
            '</td></tr><tr><th>Source site</th><td>' . h($m['wwwroot']) . '</td></tr><tr><th>Moodle</th><td>' .
            h($m['moodle_release']) . ' (' . h($m['moodle_version']) . ')</td></tr><tr><th>Contents</th><td>' .
            number_format($info['counts']['moodle']['files']) . ' code files (' . size($info['counts']['moodle']['bytes']) . '), ' .
            number_format($info['counts']['moodledata']['files']) . ' data files (' . size($info['counts']['moodledata']['bytes']) .
            '), database: ' . (int) $m['statistics']['database']['tables'] . ' tables, ' .
            number_format($m['statistics']['database']['rows']) . ' rows (' . size($info['databasesize']) . ' compressed)' .
            '</td></tr></table><h2>Server checks</h2>' . $this->check_list($checks);
        if ($errors) {
            $body .= '<p class="err">Fix the errors above, then reload this page.</p>';
        } else {
            $body .= '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" value="environment">' .
                '<button>Continue</button></form>';
        }
        $this->page('Package verified', $body);
    }

    /**
     * Server checks.
     *
     * @return array[] [status, message]
     */
    private function environment_checks(): array {
        $checks = [];
        $m = $this->state['info']['manifest'];
        $range = PHP_SUPPORT[$m['moodle_branch']] ?? null;
        if ($range === null) {
            $checks[] = ['warning', 'This installer has only been tested with Moodle 4.1 packages; this one is ' .
                $m['moodle_release'] . '.'];
        } else if (version_compare(PHP_VERSION, $range[0], '<')) {
            $checks[] = ['error', 'PHP ' . PHP_VERSION . ' is too old for Moodle ' . $m['moodle_release'] . ' (needs ' .
                $range[0] . ' or later).'];
        } else if (version_compare(PHP_VERSION, $range[1], '>')) {
            $checks[] = ['warning', 'PHP ' . PHP_VERSION . ' is newer than Moodle ' . $m['moodle_release'] .
                ' officially supports (up to ' . substr($range[1], 0, 3) . ').'];
        } else {
            $checks[] = ['ok', 'PHP ' . PHP_VERSION . '.'];
        }
        $missing = array_values(array_filter(REQUIRED_EXTENSIONS, function($e) {
            return !extension_loaded($e);
        }));
        $checks[] = $missing ? ['error', 'Missing PHP extensions: ' . implode(', ', $missing) . '.'] :
            ['ok', 'All PHP extensions Moodle requires are loaded.'];
        $optional = array_values(array_filter(OPTIONAL_EXTENSIONS, function($e) {
            return !extension_loaded($e) && !($e === 'opcache' && extension_loaded('Zend OPcache'));
        }));
        if ($optional) {
            $checks[] = ['warning', 'Recommended PHP extensions not loaded: ' . implode(', ', $optional) . '.'];
        }
        if ((int) ini_get('max_input_vars') < 5000 && PHP_VERSION_ID >= 80000) {
            $checks[] = ['warning', 'max_input_vars is ' . ini_get('max_input_vars') . '; Moodle 4.1 on PHP 8 requires 5000 for ' .
                'its own upgrades and some forms.'];
        }
        $memory = $this->bytes((string) ini_get('memory_limit'));
        if ($memory !== -1 && $memory < 256 * 1048576) {
            $checks[] = ['warning', 'memory_limit is ' . ini_get('memory_limit') . '; 256M or more is recommended.'];
        }
        $unexpected = $this->unexpected_files();
        $checks[] = $unexpected ? ['error', 'The installation directory is not empty. Remove: ' .
            implode(', ', array_slice($unexpected, 0, 10)) . (count($unexpected) > 10 ? ', ...' : '') . '.'] :
            ['ok', 'The installation directory contains only the installer and the package.'];
        $checks[] = is_writable($this->dir) ? ['ok', 'The installation directory is writable by the web server.'] :
            ['error', $this->dir . ' is not writable by the web server user.'];
        $need = (int) ($this->state['info']['counts']['moodle']['bytes'] * 1.05) + 64 * 1048576;
        $free = @disk_free_space($this->dir);
        if ($free !== false) {
            $checks[] = $free < $need ? ['error', 'Not enough disk space for the code: ' . size($need) . ' needed, ' .
                size($free) . ' free.'] : ['ok', 'Disk space for the code: ' . size($need) . ' needed, ' . size($free) . ' free.'];
        }
        if (!$this->is_https() && !in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
            $checks[] = ['warning', 'This page is not served over HTTPS; the database password is sent in clear text.'];
        }
        return $checks;
    }

    /**
     * Step 3: destination settings.
     *
     * @param string $action
     * @return void
     */
    private function step_settings(string $action): void {
        $m = $this->state['info']['manifest'];
        $defaults = $this->state['settings'] ?? [
            'wwwroot' => $this->current_url_base(),
            'dataroot' => '',
            'dbhost' => 'localhost',
            'dbname' => '',
            'dbuser' => '',
            'dbpass' => '',
            'noemail' => 1,
            'replaceurls' => 1,
            'deletepackage' => 1,
        ];
        $errors = [];
        if ($action === 'settings') {
            $input = [
                'wwwroot' => rtrim(trim((string) ($_POST['wwwroot'] ?? '')), '/'),
                'dataroot' => trim((string) ($_POST['dataroot'] ?? '')),
                'dbhost' => trim((string) ($_POST['dbhost'] ?? '')),
                'dbname' => trim((string) ($_POST['dbname'] ?? '')),
                'dbuser' => trim((string) ($_POST['dbuser'] ?? '')),
                'dbpass' => (string) ($_POST['dbpass'] ?? ''),
                'noemail' => empty($_POST['noemail']) ? 0 : 1,
                'replaceurls' => empty($_POST['replaceurls']) ? 0 : 1,
                'deletepackage' => empty($_POST['deletepackage']) ? 0 : 1,
            ];
            if ($input['dbpass'] === '' && !empty($this->state['settings']['dbpass'])) {
                $input['dbpass'] = $this->state['settings']['dbpass'];
            }
            $errors = $this->validate_settings($input);
            $defaults = $input;
            if (!$errors) {
                $this->state['settings'] = $input;
                $this->state['step'] = 'confirm';
                $this->save();
                $this->redirect();
                return;
            }
        }
        $field = function($name, $label, $help, $type = 'text') use ($defaults) {
            $value = $type === 'password' ? '' : $defaults[$name];
            return '<p><label>' . h($label) . '<br><input type="' . $type . '" name="' . $name . '" value="' . h($value) .
                '" size="60" autocomplete="off"></label><br><small>' . $help . '</small></p>';
        };
        $check = function($name, $label) use ($defaults) {
            return '<p><label><input type="checkbox" name="' . $name . '" value="1"' . (!empty($defaults[$name]) ? ' checked' : '') .
                '> ' . $label . '</label></p>';
        };
        $body = ($errors ? '<div class="err"><p>' . implode('</p><p>', array_map(__NAMESPACE__ . '\h', $errors)) . '</p></div>' : '') .
            '<form method="post">' . $this->csrf() . '<input type="hidden" name="action" value="settings">' .
            '<h2>Site</h2>' .
            $field('wwwroot', 'Site URL ($CFG->wwwroot)', 'The address of this directory, without a trailing slash. Source: ' .
                h($m['wwwroot'])) .
            $field('dataroot', 'Moodledata directory ($CFG->dataroot)', 'Absolute path outside the web root. It must not exist yet, ' .
                'or be empty; the web server user must be able to create it.') .
            '<h2>Database (MySQL)</h2>' .
            $field('dbhost', 'Host', 'e.g. localhost, db.example.com:3306 or localhost:/run/mysqld/mysqld.sock') .
            $field('dbname', 'Database name', 'An existing database with no tables using the prefix below.') .
            $field('dbuser', 'User', 'A user with CREATE, DROP, ALTER, INDEX, SELECT, INSERT, UPDATE and DELETE on that database.') .
            $field('dbpass', 'Password', !empty($this->state['settings']['dbpass']) ? 'Leave empty to keep the password entered ' .
                'before.' : '', 'password') .
            '<p>Table prefix: <code>' . h($m['table_prefix']) . '</code> (from the package)</p>' .
            '<h2>Options</h2>' .
            $check('noemail', 'Disable all outgoing email on the clone ($CFG->noemailever). Recommended for test copies, so ' .
                'users of the source site are never emailed by the clone.') .
            $check('replaceurls', 'Rewrite links to ' . h($m['wwwroot']) . ' in site content to the new site URL.') .
            $check('deletepackage', 'Delete the package after a successful installation.') .
            '<button>Continue</button></form>';
        $this->page('Destination settings', $body);
    }

    /**
     * Validate settings, including a live database check.
     *
     * @param array $s
     * @return string[]
     */
    private function validate_settings(array $s): array {
        $errors = [];
        $prefix = $this->state['info']['manifest']['table_prefix'];
        $url = parse_url($s['wwwroot']);
        if (!filter_var($s['wwwroot'], FILTER_VALIDATE_URL) || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true) ||
                isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
                preg_match('/[\s\'"\\\\<>]/', $s['wwwroot'])) {
            $errors[] = 'The site URL must be a plain http(s) address such as https://moodle.example.com.';
        }

        $dataroot = paths::normalise_absolute($s['dataroot']);
        if ($dataroot === null || $dataroot === '/' || substr_count($dataroot, '/') < 2) {
            $errors[] = 'The moodledata directory must be an absolute path at least two levels deep.';
        } else {
            $resolved = paths::resolve($dataroot);
            $docroot = paths::resolve(rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? $this->dir)), '/'));
            if (paths::is_inside($resolved, paths::resolve($this->dir)) || paths::is_inside($resolved, $docroot) ||
                    paths::is_inside(paths::resolve($this->dir), $resolved)) {
                $errors[] = 'The moodledata directory must be outside the web root and must not contain it.';
            } else if (is_link($dataroot)) {
                $errors[] = 'The moodledata directory must not be a symbolic link.';
            } else if (file_exists($dataroot)) {
                if (!is_dir($dataroot) || count(array_diff(scandir($dataroot) ?: ['x'], ['.', '..'])) > 0) {
                    $errors[] = 'The moodledata directory exists and is not empty. The installer only uses a new or empty directory.';
                } else if (!is_writable($dataroot)) {
                    $errors[] = 'The moodledata directory is not writable by the web server user.';
                }
            } else if (!is_dir(dirname($dataroot)) || !is_writable(dirname($dataroot))) {
                $errors[] = 'The web server user cannot create ' . $dataroot . ' (its parent directory must exist and be writable).';
            }
            if (!$errors) {
                $free = @disk_free_space(file_exists($dataroot) ? $dataroot : dirname($dataroot));
                $info = $this->state['info'];
                $need = (int) ($info['counts']['moodledata']['bytes'] * 1.05) + $info['databasesize'] * 12 + 256 * 1048576;
                if ($free !== false && $free < $need) {
                    $errors[] = 'Not enough disk space for moodledata and the temporary database dump: about ' . size($need) .
                        ' needed, ' . size($free) . ' free.';
                }
            }
            $s['dataroot'] = $dataroot;
        }

        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $s['dbname'])) {
            $errors[] = 'The database name may contain letters, digits, "_", "$" and "-".';
        }
        if ($s['dbuser'] === '' || strlen($s['dbuser']) > 80 || preg_match('/[\x00-\x1F]/', $s['dbuser'])) {
            $errors[] = 'Enter the database user.';
        }
        if ($s['dbhost'] === '' || !preg_match('/^[A-Za-z0-9._:\/\[\]-]+$/', $s['dbhost'])) {
            $errors[] = 'Enter a valid database host.';
        }
        if ($errors) {
            return $errors;
        }

        try {
            $db = db::connect($s);
        } catch (installer_exception $e) {
            return [$e->getMessage()];
        }
        $version = $db->server_info;
        if (version_compare(preg_replace('/[^0-9.].*$/', '', $version), '5.7', '<')) {
            $errors[] = 'MySQL ' . $version . ' is too old for Moodle 4.1 (5.7 or later).';
        }
        $existing = db::prefixed_tables($db, $prefix);
        if ($existing) {
            $errors[] = 'Database ' . $s['dbname'] . ' already has ' . count($existing) . ' tables with the prefix "' . $prefix .
                '". The installer only restores into a database without them.';
        }
        $packet = (int) $db->query('SELECT @@max_allowed_packet')->fetch_row()[0];
        $needed = (int) $this->state['info']['manifest']['database_dump']['max_statement_bytes'] + 65536;
        if ($packet < $needed) {
            $errors[] = 'The database server\'s max_allowed_packet (' . size($packet) . ') is smaller than the largest statement ' .
                'in the dump (' . size($needed) . ').';
        }
        if (!$errors) {
            $probe = '`' . $prefix . 'mci_probe_' . bin2hex(random_bytes(3)) . '`';
            if (!$db->query("CREATE TABLE {$probe} (id BIGINT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB") ||
                    !$db->query("INSERT INTO {$probe} (id) VALUES (1)") || !$db->query("ALTER TABLE {$probe} ADD INDEX ix (id)") ||
                    !$db->query("DROP TABLE {$probe}")) {
                $errors[] = 'The database user lacks privileges needed for the restore: ' . $db->error;
                @$db->query("DROP TABLE IF EXISTS {$probe}");
            }
        }
        $db->close();
        return $errors;
    }

    /**
     * Step 4: confirmation.
     *
     * @param string $action
     * @return void
     */
    private function step_confirm(string $action): void {
        if ($action === 'back') {
            $this->state['step'] = 'settings';
            $this->save();
            $this->redirect();
            return;
        }
        if ($action === 'start') {
            if ($this->unexpected_files()) {
                throw new installer_exception('The installation directory is no longer clean. Remove the extra files and start again.');
            }
            $this->state['step'] = 'run';
            $this->state['run'] = ['task' => 'prepare', 'log' => []];
            $this->save();
            $this->step_run();
            return;
        }
        $s = $this->state['settings'];
        $m = $this->state['info']['manifest'];
        $body = '<table>' .
            '<tr><th>Package</th><td>' . h($this->state['package']) . '</td></tr>' .
            '<tr><th>Moodle code</th><td>' . h($this->dir) . '</td></tr>' .
            '<tr><th>Site URL</th><td>' . h($s['wwwroot']) . ' (was ' . h($m['wwwroot']) . ')</td></tr>' .
            '<tr><th>Moodledata</th><td>' . h($s['dataroot']) . '</td></tr>' .
            '<tr><th>Database</th><td>' . h($s['dbuser']) . '@' . h($s['dbhost']) . ' / ' . h($s['dbname']) . ', prefix ' .
            h($m['table_prefix']) . '</td></tr>' .
            '<tr><th>Outgoing email</th><td>' . ($s['noemail'] ? 'disabled' : 'enabled') . '</td></tr>' .
            '<tr><th>Links in content</th><td>' . ($s['replaceurls'] ? 'rewritten to the new URL' : 'left unchanged') . '</td></tr>' .
            '</table><p>The restore runs in steps; keep this page open until it finishes. Only this installer is reachable in ' .
            'this directory until then (Apache: a temporary .htaccess).</p>' .
            '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="action" value="back">' .
            '<button class="secondary">Back</button></form> ' .
            '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="action" value="start">' .
            '<button>Install</button></form>';
        $this->page('Ready to install', $body);
    }

    /**
     * Step 5: the restore, in time-limited slices.
     *
     * @return void
     */
    private function step_run(): void {
        @set_time_limit(300);
        @ignore_user_abort(true);
        $lock = fopen($this->dir . '/' . RUN_LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->progress_page('Another installer request is still running', null, 'Waiting for it to finish...');
            return;
        }
        try {
            $task = $this->state['run']['task'];
            switch ($task) {
                case 'prepare':
                    $this->task_prepare();
                    break;
                case 'extract':
                    $this->task_extract();
                    break;
                case 'database':
                    $this->task_database();
                    break;
                case 'config':
                    $this->task_config();
                    break;
                case 'moodle_paths':
                case 'moodle_urls':
                case 'moodle_verify':
                    $this->task_moodle($task);
                    return;
                case 'finish':
                    $this->task_finish();
                    return;
                default:
                    throw new installer_exception('Unknown installer task.');
            }
            $this->save();
            $this->progress_page($this->task_label($this->state['run']['task']), $this->overall_progress(),
                $this->state['run']['detail'] ?? '');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Create moodledata and the work directory, protect the web directory, extract checksums.sha256.
     *
     * @return void
     */
    private function task_prepare(): void {
        $s = $this->state['settings'];
        $run = &$this->state['run'];
        if (!file_exists($s['dataroot'])) {
            if (!@mkdir($s['dataroot'], 02770)) {
                throw new installer_exception('Cannot create ' . $s['dataroot'] . '.');
            }
            $run['createddataroot'] = true;
        }
        @chmod($s['dataroot'], 02770);
        $run['dataroot'] = $s['dataroot'];
        $work = $s['dataroot'] . '/' . WORK_DIR;
        if (!is_dir($work) && !@mkdir($work, 0700)) {
            throw new installer_exception('Cannot create ' . $work . '.');
        }
        // The protective .htaccess is normally already there (protect_directory() writes it on the first request).
        $this->protect_directory();
        $run['htaccess'] = is_file($this->dir . '/.htaccess') &&
            strpos((string) file_get_contents($this->dir . '/.htaccess'), HTACCESS_MARK) === 0;
        $zip = package::open($this->package_path());
        $in = $zip->getStream('checksums.sha256');
        $out = fopen($work . '/checksums.sha256', 'xb');
        if (!$in || !$out || stream_copy_to_stream($in, $out) === false) {
            throw new installer_exception('Cannot extract checksums.sha256.');
        }
        fclose($in);
        fclose($out);
        $zip->close();
        $run['task'] = 'extract';
        $run['index'] = 0;
        $run['listoffset'] = 0;
        $run['bytes'] = 0;
        $run['detail'] = '';
    }

    /**
     * Extract and verify entries in archive order until the time slice ends.
     *
     * @return void
     */
    private function task_extract(): void {
        $run = &$this->state['run'];
        $info = $this->state['info'];
        $dataroot = $run['dataroot'];
        $work = $dataroot . '/' . WORK_DIR;
        $zip = package::open($this->package_path());
        $list = fopen($work . '/checksums.sha256', 'rb');
        fseek($list, $run['listoffset']);
        try {
            while ($run['index'] < $zip->numFiles) {
                $i = $run['index'];
                $name = (string) $zip->getNameIndex($i);
                $stat = $zip->statIndex($i);
                if ($name === 'checksums.sha256') {
                    $run['index']++;
                    continue;
                }
                if (substr($name, -1) === '/') {
                    $target = $this->target_for(substr($name, 0, -1), $dataroot);
                    if ($target !== null && !is_dir($target)) {
                        $this->make_dirs($target, strpos($name, 'moodledata/') === 0);
                    }
                    $run['index']++;
                    continue;
                }
                $line = fgets($list);
                if ($line === false || !preg_match('/^([0-9a-f]{64})  (.+)\n$/', $line, $m) || $m[2] !== $name) {
                    throw new installer_exception('checksums.sha256 is out of step with the archive at ' . rawurlencode($name) . '.');
                }
                if ($name === 'manifest.json') {
                    $this->hash_entry($zip, $name, null, $m[1]);
                } else if ($name === 'database.sql.gz') {
                    $this->extract_database($zip, $m[1], $work . '/database.sql');
                } else if ($name === 'moodle/.htaccess') {
                    $this->hash_entry($zip, $name, $work . '/package.htaccess', $m[1]);
                } else {
                    $target = $this->target_for($name, $dataroot);
                    $isdata = strpos($name, 'moodledata/') === 0;
                    $this->make_dirs(dirname($target), $isdata);
                    $this->hash_entry($zip, $name, $target, $m[1]);
                    $exec = ((($zip->getExternalAttributesIndex($i, $opsys, $attr) ? $attr : 0) >> 16) & 0111) !== 0;
                    @chmod($target, $isdata ? 0660 : ($exec ? 0755 : 0644));
                    if (!empty($stat['mtime'])) {
                        @touch($target, (int) $stat['mtime']);
                    }
                }
                $run['bytes'] += (int) $stat['size'];
                $run['index']++;
                $run['listoffset'] = ftell($list);
                if ($this->out_of_time()) {
                    break;
                }
            }
            if ($run['index'] >= $zip->numFiles) {
                if (fgets($list) !== false) {
                    throw new installer_exception('checksums.sha256 lists files that were not extracted.');
                }
                if (!is_file($work . '/database.sql')) {
                    throw new installer_exception('The database dump was not extracted.');
                }
                $run['task'] = 'database';
                $run['sqloffset'] = 0;
                $run['sets'] = [];
                $run['tables'] = [];
                $run['rows'] = 0;
                $run['complete'] = false;
            }
            $run['detail'] = number_format($run['index']) . ' of ' . number_format($zip->numFiles) . ' entries, ' . size($run['bytes']);
        } finally {
            fclose($list);
            $zip->close();
        }
    }

    /**
     * Where an entry goes: moodle/X into the installation directory, moodledata/X into dataroot.
     *
     * @param string $name Entry name without trailing slash.
     * @param string $dataroot
     * @return string|null Null for "moodle" and "moodledata" themselves.
     */
    private function target_for(string $name, string $dataroot): ?string {
        if (!paths::is_safe_relative($name)) {
            throw new installer_exception('Unsafe entry name: ' . rawurlencode($name));
        }
        if ($name === 'moodle' || $name === 'moodledata') {
            return null;
        }
        if (strpos($name, 'moodle/') === 0) {
            return $this->dir . '/' . substr($name, 7);
        }
        if (strpos($name, 'moodledata/') === 0) {
            return $dataroot . '/' . substr($name, 11);
        }
        throw new installer_exception('Unexpected entry: ' . rawurlencode($name));
    }

    /**
     * Create missing directories, refusing to pass through anything that is not a real directory.
     *
     * @param string $dir
     * @param bool $isdata
     * @return void
     */
    private function make_dirs(string $dir, bool $isdata): void {
        if (is_dir($dir) && !is_link($dir)) {
            return;
        }
        $base = $isdata ? $this->state['run']['dataroot'] : $this->dir;
        if (!paths::is_inside($dir, $base)) {
            throw new installer_exception('Refusing to create a directory outside the destination.');
        }
        $this->make_dirs(dirname($dir), $isdata);
        if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
            throw new installer_exception('A file is in the way of directory ' . $dir . '.');
        }
        if (!@mkdir($dir, $isdata ? 02770 : 0755) && !is_dir($dir)) {
            throw new installer_exception('Cannot create directory ' . $dir . '.');
        }
        @chmod($dir, $isdata ? 02770 : 0755);
    }

    /**
     * Stream an entry to a file (or nowhere), verifying its SHA-256.
     *
     * The file is written under a temporary name and renamed into place only
     * when its checksum matches; an existing file is never overwritten.
     *
     * @param \ZipArchive $zip
     * @param string $name
     * @param string|null $target
     * @param string $expected
     * @return void
     */
    private function hash_entry(\ZipArchive $zip, string $name, ?string $target, string $expected): void {
        $in = $zip->getStream($name);
        if ($in === false) {
            throw new installer_exception('Cannot read ' . rawurlencode($name) . ' from the package.');
        }
        $out = null;
        $part = null;
        if ($target !== null) {
            if (file_exists($target) || is_link($target)) {
                throw new installer_exception('The package would overwrite an existing file: ' . $target . '.');
            }
            $part = $target . '.mci-part';
            @unlink($part);
            $out = @fopen($part, 'xb');
            if (!$out) {
                throw new installer_exception('Cannot write ' . $target . '.');
            }
        }
        $hash = hash_init('sha256');
        try {
            while (!feof($in)) {
                $chunk = fread($in, CHUNK);
                if ($chunk === false) {
                    throw new installer_exception('Cannot read ' . rawurlencode($name) . ' from the package.');
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($hash, $chunk);
                if ($out !== null && fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new installer_exception('Writing ' . $target . ' failed. The disk may be full.');
                }
            }
        } finally {
            fclose($in);
            if ($out !== null) {
                fclose($out);
            }
        }
        if (!hash_equals($expected, hash_final($hash))) {
            if ($part !== null) {
                @unlink($part);
            }
            throw new installer_exception('Checksum mismatch for ' . rawurlencode($name) . '. The package is damaged.');
        }
        if ($part !== null && !@rename($part, $target)) {
            throw new installer_exception('Cannot move ' . $target . ' into place.');
        }
    }

    /**
     * Verify database.sql.gz and decompress it to the work directory.
     *
     * @param \ZipArchive $zip
     * @param string $expected
     * @param string $target
     * @return void
     */
    private function extract_database(\ZipArchive $zip, string $expected, string $target): void {
        $in = $zip->getStream('database.sql.gz');
        $out = @fopen($target . '.part', 'wb');
        if (!$in || !$out) {
            throw new installer_exception('Cannot extract the database dump.');
        }
        @chmod($target . '.part', 0600);
        $hash = hash_init('sha256');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $tail = '';
        try {
            while (!feof($in)) {
                $chunk = fread($in, CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($hash, $chunk);
                $plain = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
                if ($plain === false) {
                    throw new installer_exception('database.sql.gz is not a valid gzip stream.');
                }
                if ($plain !== '' && fwrite($out, $plain) !== strlen($plain)) {
                    throw new installer_exception('Writing the database dump failed. The disk may be full.');
                }
                $tail = substr($tail . $plain, -4096);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if (!hash_equals($expected, hash_final($hash))) {
            throw new installer_exception('Checksum mismatch for database.sql.gz. The package is damaged.');
        }
        if (inflate_get_status($inflate) !== ZLIB_STREAM_END || strpos($tail, COMPLETION_MARKER) === false) {
            throw new installer_exception('database.sql.gz is incomplete (no completion marker).');
        }
        if (!rename($target . '.part', $target)) {
            throw new installer_exception('Cannot move the database dump into place.');
        }
    }

    /**
     * Execute dump statements until the time slice ends; verify the result at the end.
     *
     * @return void
     */
    private function task_database(): void {
        $run = &$this->state['run'];
        $s = $this->state['settings'];
        $m = $this->state['info']['manifest'];
        $path = $run['dataroot'] . '/' . WORK_DIR . '/database.sql';
        $db = db::connect($s);
        foreach ($run['sets'] as $set) {
            // Session settings from the dump's header apply to every connection.
            db::run($db, $set);
        }
        $guard = new sql_guard($m['table_prefix']);
        $reader = new sql_reader($path, (int) $run['sqloffset']);
        try {
            while (($statement = $reader->next()) !== null) {
                if (strpos($statement, '--') === 0) {
                    if (strpos($statement, COMPLETION_MARKER) === 0) {
                        $run['complete'] = true;
                    }
                    $run['sqloffset'] = $reader->offset();
                    continue;
                }
                $kind = $guard->check($statement);
                db::run($db, $statement);
                if ($kind['type'] === 'set') {
                    $run['sets'][] = $statement;
                } else if ($kind['type'] === 'create') {
                    $run['tables'][$kind['table']] = true;
                } else if ($kind['type'] === 'insert') {
                    $run['rows'] += $kind['rows'];
                }
                $run['sqloffset'] = $reader->offset();
                if ($this->out_of_time()) {
                    break;
                }
            }
            $finished = $statement === null;
        } finally {
            $reader->close();
        }
        $size = (int) filesize($path);
        $run['detail'] = count($run['tables']) . ' tables, ' . number_format($run['rows']) . ' rows (' .
            size($run['sqloffset']) . ' of ' . size($size) . ')';
        if (!$finished) {
            $db->close();
            return;
        }

        // Verify: completion marker, table count and row count match the manifest.
        if (!$run['complete']) {
            throw new installer_exception('The database dump has no completion marker; it is truncated.');
        }
        $expectedtables = (int) $m['statistics']['database']['tables'];
        $expectedrows = (int) $m['statistics']['database']['rows'];
        $actual = count(db::prefixed_tables($db, $m['table_prefix']));
        if ($actual !== $expectedtables || count($run['tables']) !== $expectedtables) {
            throw new installer_exception("The database has {$actual} tables after the restore; the package has {$expectedtables}.");
        }
        if ($run['rows'] !== $expectedrows) {
            throw new installer_exception("{$run['rows']} rows were restored; the package has {$expectedrows}.");
        }
        $config = $db->query('SELECT COUNT(*) FROM `' . $m['table_prefix'] . 'config`');
        if (!$config || (int) $config->fetch_row()[0] === 0) {
            throw new installer_exception('The restored database has no Moodle configuration.');
        }
        $db->close();
        @unlink($path);
        $run['task'] = 'config';
        $run['detail'] = '';
    }

    /**
     * Write a new config.php (the source's is never in the package).
     *
     * @return void
     */
    private function task_config(): void {
        $run = &$this->state['run'];
        $s = $this->state['settings'];
        $m = $this->state['info']['manifest'];
        $options = [];
        [, $port, $socket] = db::parse_host($s['dbhost']);
        [$host] = db::parse_host($s['dbhost']);
        $options = ['dbpersist' => 0, 'dbport' => $port === null ? '' : $port, 'dbsocket' => $socket === null ? '' : $socket];
        $lines = [
            '<?php  // Moodle configuration file, written by the Moodle Clone installer ' . VERSION . ' on ' . gmdate('Y-m-d H:i:s') . ' UTC.',
            '',
            'unset($CFG);',
            'global $CFG;',
            '$CFG = new stdClass();',
            '',
            '$CFG->dbtype    = ' . var_export($m['database_type'], true) . ';',
            '$CFG->dblibrary = \'native\';',
            '$CFG->dbhost    = ' . var_export($host, true) . ';',
            '$CFG->dbname    = ' . var_export($s['dbname'], true) . ';',
            '$CFG->dbuser    = ' . var_export($s['dbuser'], true) . ';',
            '$CFG->dbpass    = ' . var_export($s['dbpass'], true) . ';',
            '$CFG->prefix    = ' . var_export($m['table_prefix'], true) . ';',
            '$CFG->dboptions = ' . str_replace("\n", ' ', var_export($options, true)) . ';',
            '',
            '$CFG->wwwroot   = ' . var_export($s['wwwroot'], true) . ';',
            '$CFG->dataroot  = ' . var_export($s['dataroot'], true) . ';',
            '$CFG->admin     = \'admin\';',
            '',
            '$CFG->directorypermissions = 02770;',
        ];
        if ($s['noemail']) {
            $lines[] = '';
            $lines[] = '// This is a copy of ' . str_replace(["\r", "\n", '?>'], '', $m['wwwroot']) . ': never send email from it.';
            $lines[] = '$CFG->noemailever = true;';
        }
        $lines[] = '';
        $lines[] = 'require_once(__DIR__ . \'/lib/setup.php\');';
        $lines[] = '';
        $lines[] = '// There is no php closing tag in this file,';
        $lines[] = '// it is intentional because it prevents trailing whitespace problems!';
        $content = implode("\n", $lines) . "\n";
        $tmp = $this->dir . '/config.php.mci-part';
        if (file_put_contents($tmp, $content, LOCK_EX) === false || !@chmod($tmp, 0640) ||
                !@rename($tmp, $this->dir . '/config.php')) {
            @unlink($tmp);
            throw new installer_exception('Cannot write ' . $this->dir . '/config.php.');
        }
        $run['configwritten'] = true;
        $run['task'] = 'moodle_paths';
    }

    /**
     * Run one step inside the restored Moodle (this request only).
     *
     * @param string $task
     * @return void
     */
    private function task_moodle(string $task): void {
        $run = &$this->state['run'];
        $work = $run['dataroot'] . '/' . WORK_DIR;
        $resultfile = $work . '/' . $task . '.json';
        if (is_file($resultfile)) {
            // The Moodle step finished in the previous request.
            $result = json_decode((string) file_get_contents($resultfile), true);
            @unlink($resultfile);
            unset($run['moodlestarted'][$task]);
            if (!is_array($result) || empty($result['ok'])) {
                $this->save();
                throw new installer_exception('Moodle step "' . $this->task_label($task) . '" failed: ' .
                    ($result['error'] ?? 'no result'));
            }
            $run['moodle'][$task] = $result;
            $next = ['moodle_paths' => $this->state['settings']['replaceurls'] ? 'moodle_urls' : 'moodle_verify',
                'moodle_urls' => 'moodle_verify', 'moodle_verify' => 'finish'];
            $run['task'] = $next[$task];
            $run['detail'] = '';
            $this->save();
            $this->progress_page($this->task_label($run['task']), $this->overall_progress(), '');
            return;
        }
        if (!empty($run['moodlestarted'][$task])) {
            unset($run['moodlestarted'][$task]);
            $this->save();
            throw new installer_exception('Moodle step "' . $this->task_label($task) . '" stopped without a result (check the web ' .
                'server error log). Use Retry to run it again.');
        }
        $run['moodlestarted'][$task] = true;
        $csrf = $this->state['csrf'];
        $settings = $this->state['settings'];
        $manifest = $this->state['info']['manifest'];
        $this->save();
        session_write_close();

        register_shutdown_function(function() use ($resultfile) {
            $error = error_get_last();
            if (!is_file($resultfile) && $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR,
                    E_COMPILE_ERROR], true)) {
                @file_put_contents($resultfile, json_encode(['ok' => false, 'error' => $error['message']]));
            }
        });
        try {
            $result = moodle_steps::run($task, $this->dir, $settings, $manifest);
            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage() . (isset($e->debuginfo) ? ' (' . $e->debuginfo . ')' : '')];
        }
        file_put_contents($resultfile, json_encode($result));
        @chmod($resultfile, 0600);
        $this->progress_page($this->task_label($task), $this->overall_progress(), 'Done.', $csrf);
    }

    /**
     * Clean up and remove the installer.
     *
     * @return void
     */
    private function task_finish(): void {
        $run = $this->state['run'];
        $s = $this->state['settings'];
        $work = $run['dataroot'] . '/' . WORK_DIR;
        $notes = [];

        // Hand the web directory back to Moodle: remove the temporary protection, restore the package's .htaccess.
        if (!empty($run['htaccess']) && is_file($this->dir . '/.htaccess') &&
                strpos((string) file_get_contents($this->dir . '/.htaccess'), HTACCESS_MARK) === 0) {
            @unlink($this->dir . '/.htaccess');
        }
        if (is_file($work . '/package.htaccess')) {
            @rename($work . '/package.htaccess', $this->dir . '/.htaccess');
        }
        paths::remove_tree($work, $run['dataroot']);

        // HTTP check of the new site (informational; the server may not be able to reach its own public URL).
        $http = $this->http_check($s['wwwroot'] . '/login/index.php');

        // Revoke every installer session and forget all attempts before anything else is removed.
        $revoked = $this->store()->destroy();

        $remaining = $revoked ? [] : [AUTH_FILE];
        $delete = [$this->dir . '/' . KEY_FILE, $this->dir . '/' . RUN_LOCK];
        if ($s['deletepackage']) {
            $delete[] = $this->package_path();
            $delete[] = $this->package_path() . '.sha256';
        }
        $delete[] = __FILE__;
        foreach ($delete as $file) {
            if (file_exists($file) && !@unlink($file)) {
                $remaining[] = basename($file);
            }
        }
        if (!$s['deletepackage']) {
            $notes[] = 'The package was kept at ' . $this->package_path() . '. It contains the whole site: move it out of the web ' .
                'directory or delete it.';
        }
        if ($remaining) {
            file_put_contents($this->dir . '/' . LOCK_FILE, "Moodle Clone installation finished on " . gmdate('c') . "\n");
            $notes[] = 'Could not delete: ' . implode(', ', $remaining) . '. The installer is locked; delete these files now.';
        }

        $moodle = $run['moodle'] ?? [];
        $verify = $moodle['moodle_verify'] ?? [];
        $body = '<p class="ok">The site has been restored.</p><p><a class="button" href="' . h($s['wwwroot']) . '/">Open ' .
            h($s['wwwroot']) . '</a></p><h2>Verification</h2>' . $this->check_list(array_merge(
                $verify['checks'] ?? [],
                [$http],
                isset($moodle['moodle_paths']['paths']) ? [['ok', 'Configuration paths migrated: ' .
                    (int) $moodle['moodle_paths']['paths'] . ' setting(s).']] : [],
                !empty($moodle['moodle_paths']['jobs']) ? [['ok', (int) $moodle['moodle_paths']['jobs'] . ' Moodle Clone backup job(s) ' .
                    'that were queued or running on the source were closed as failed on this copy.']] : [],
                isset($moodle['moodle_urls']) ? [['ok', 'Links rewritten to the new site URL: ' .
                    (int) $moodle['moodle_urls']['updated'] . ' value(s) in ' . (int) $moodle['moodle_urls']['tables'] .
                    ' table(s).']] : [],
                !empty($moodle['moodle_urls']['skipped']) ? [['warning', 'Values left unchanged because rewriting them was not ' .
                    'safe (too long for their column, or unreadable serialized data): ' .
                    implode(', ', array_slice($moodle['moodle_urls']['skipped'], 0, 10))]] : []
            )) . ($notes ? '<h2>Notes</h2><p>' . implode('</p><p>', array_map(__NAMESPACE__ . '\h', $notes)) . '</p>' : '') .
            '<p>Log in with the administrator accounts of the source site. All sessions of the source site were left behind.</p>';
        $_SESSION = [];
        session_destroy();
        $this->finished = true;
        $this->page('Installation complete', $body);
    }

    /**
     * Fetch a URL and check it looks like a Moodle page.
     *
     * @param string $url
     * @return array [status, message]
     */
    private function http_check(string $url): array {
        if (!function_exists('curl_init')) {
            return ['warning', 'Could not check the site over HTTP (no curl).'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MoodleCloneInstaller/' . VERSION]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($code === 200 && stripos($body, 'login') !== false) {
            return ['ok', 'The login page of the new site answers over HTTP (' . $url . ').'];
        }
        return ['warning', 'The login page did not answer as expected over HTTP (' . $url . ': ' .
            ($code ? 'HTTP ' . $code : $error) . '). Check the web server and DNS for the new URL.'];
    }

    /**
     * Undo everything the installer created (after a failure).
     *
     * @return void
     */
    private function do_reset(): void {
        $run = $this->state['run'] ?? [];
        $removed = [];
        // Files in the installation directory: it was verified clean, so everything else is ours.
        foreach (scandir($this->dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $this->reserved_names(), true)) {
                continue;
            }
            if ($name === '.htaccess') {
                // The installer's own protection (the directory was verified clean, so nothing else can be here).
                continue;
            }
            paths::remove_tree($this->dir . '/' . $name, $this->dir);
            $removed[] = $name;
        }
        // Moodledata: only the directory recorded when the run started.
        if (!empty($run['dataroot']) && paths::normalise_absolute($run['dataroot']) === $run['dataroot'] &&
                substr_count($run['dataroot'], '/') >= 2) {
            foreach (scandir($run['dataroot']) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    paths::remove_tree($run['dataroot'] . '/' . $name, $run['dataroot']);
                }
            }
            if (!empty($run['createddataroot'])) {
                @rmdir($run['dataroot']);
            }
        }
        // Database: the tables with the prefix did not exist when the run started.
        if (!empty($this->state['settings'])) {
            $db = db::connect($this->state['settings']);
            db::run($db, 'SET foreign_key_checks = 0');
            foreach (db::prefixed_tables($db, $this->state['info']['manifest']['table_prefix']) as $table) {
                db::run($db, 'DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
            $db->close();
        }
        unset($this->state['run'], $this->state['error']);
        $this->state['step'] = 'settings';
        $this->save();
        $this->page('Destination reset', '<p>Everything the installer created was removed: the extracted files, the moodledata ' .
            'contents and the restored tables. The package and the installer are still here.</p><form method="post">' .
            $this->csrf() . '<button>Back to the settings</button></form>');
    }

    /**
     * Record a failure and show it.
     *
     * @param string $message
     * @return void
     */
    private function fail(string $message): void {
        if (($this->state['step'] ?? '') === 'run') {
            $this->state['step'] = 'failed';
            $this->state['error'] = $message;
            $this->state['failedtask'] = $this->state['run']['task'] ?? '';
            $this->save();
            $this->show_failed();
            return;
        }
        if (isset($_SESSION)) {
            $this->save();
        }
        $this->page('Cannot continue', '<p class="err">' . h($message) . '</p><p><a href="' . h($this->self_url()) .
            '">Reload</a></p>');
    }

    /**
     * Failure page with retry and reset.
     *
     * @return void
     */
    private function show_failed(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry' &&
                hash_equals($this->state['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            $this->state['step'] = 'run';
            unset($this->state['error']);
            $this->save();
            $this->redirect();
            return;
        }
        $this->page('The installation stopped', '<p class="err">' . h($this->state['error'] ?? 'Unknown error') . '</p>' .
            '<p>Step: ' . h($this->task_label($this->state['failedtask'] ?? '')) . '. Nothing has been deleted.</p>' .
            '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="action" value="retry">' .
            '<button class="secondary">Retry this step</button></form> ' .
            '<form method="post" class="inline" onsubmit="return confirm(\'Delete everything the installer created?\')">' .
            $this->csrf() . '<input type="hidden" name="action" value="reset"><button>Reset the destination</button></form>' .
            '<p><small>Reset removes the extracted code, the moodledata contents and every table with the package\'s prefix ' .
            'from the destination database, then returns to the settings.</small></p>');
    }

    /**
     * Label of a task.
     *
     * @param string $task
     * @return string
     */
    private function task_label(string $task): string {
        $labels = ['prepare' => 'Preparing', 'extract' => 'Restoring and verifying files', 'database' => 'Restoring the database',
            'config' => 'Writing config.php', 'moodle_paths' => 'Checking Moodle and migrating paths',
            'moodle_urls' => 'Rewriting links to the new URL', 'moodle_verify' => 'Purging caches and verifying',
            'finish' => 'Finishing'];
        return $labels[$task] ?? $task;
    }

    /**
     * Rough overall progress.
     *
     * @return float
     */
    private function overall_progress(): float {
        $run = $this->state['run'];
        $order = ['prepare' => 0.0, 'extract' => 0.02, 'database' => 0.6, 'config' => 0.9, 'moodle_paths' => 0.91,
            'moodle_urls' => 0.93, 'moodle_verify' => 0.96, 'finish' => 0.99];
        $base = $order[$run['task']] ?? 0;
        if ($run['task'] === 'extract') {
            $total = max(1, array_sum(array_column($this->state['info']['counts'], 'bytes')) + $this->state['info']['databasesize']);
            $base += 0.58 * min(1, ($run['bytes'] ?? 0) / $total);
        }
        return $base;
    }

    /**
     * Package names present next to the installer.
     *
     * @return string[]
     */
    private function packages(): array {
        $found = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if (preg_match(PACKAGE_PATTERN, $name) && is_file($this->dir . '/' . $name) && !is_link($this->dir . '/' . $name)) {
                $found[] = $name;
            }
        }
        sort($found);
        return $found;
    }

    /**
     * The chosen package's path.
     *
     * @return string
     */
    private function package_path(): string {
        $name = (string) ($this->state['package'] ?? '');
        if (!preg_match(PACKAGE_PATTERN, $name) || !in_array($name, $this->authorized, true)) {
            throw new installer_exception('No package selected.');
        }
        return $this->dir . '/' . $name;
    }

    /**
     * Files of the installer itself that must never be overwritten or removed by a reset.
     *
     * @return string[]
     */
    private function reserved_names(): array {
        $names = [basename(__FILE__), KEY_FILE, AUTH_FILE, LOCK_FILE, RUN_LOCK];
        foreach ($this->packages() as $package) {
            $names[] = $package;
            $names[] = $package . '.sha256';
        }
        return $names;
    }

    /**
     * Anything in the installation directory besides the installer's own files.
     *
     * @return string[]
     */
    private function unexpected_files(): array {
        $unexpected = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $this->reserved_names(), true)) {
                continue;
            }
            if ($name === '.htaccess' && strpos((string) @file_get_contents($this->dir . '/.htaccess'), HTACCESS_MARK) === 0) {
                continue;
            }
            $unexpected[] = $name;
        }
        return $unexpected;
    }

    /**
     * Whether the time slice is used up.
     *
     * @return bool
     */
    private function out_of_time(): bool {
        return microtime(true) - $this->started > SLICE_SECONDS;
    }

    /**
     * Parse a php.ini size.
     *
     * @param string $value
     * @return int -1 for unlimited.
     */
    private function bytes(string $value): int {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                $number *= 1024;
                // Fall through.
            case 'm':
                $number *= 1024;
                // Fall through.
            case 'k':
                $number *= 1024;
        }
        return $number;
    }

    /**
     * Whether this request came over HTTPS.
     *
     * @return bool
     */
    private function is_https(): bool {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
            (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    /**
     * The URL of this directory, as a default for wwwroot.
     *
     * @return string
     */
    private function current_url_base(): string {
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return ($this->is_https() ? 'https' : 'http') . '://' . $host . $path;
    }

    /**
     * URL of this script.
     *
     * @return string
     */
    private function self_url(): string {
        return basename(__FILE__);
    }

    /**
     * Persist state to the session.
     *
     * @return void
     */
    private function save(): void {
        $_SESSION['mci'] = $this->state;
    }

    /**
     * Redirect to this script (GET).
     *
     * @return void
     */
    private function redirect(): void {
        header('Location: ' . $this->self_url(), true, 303);
    }

    /**
     * Hidden CSRF field.
     *
     * @param string|null $token
     * @return string
     */
    private function csrf(?string $token = null): string {
        return '<input type="hidden" name="csrf" value="' . h($token ?? $this->state['csrf']) . '">';
    }

    /**
     * Render a list of checks.
     *
     * @param array[] $checks
     * @return string
     */
    private function check_list(array $checks): string {
        $html = '<ul class="checks">';
        foreach ($checks as $check) {
            $html .= '<li class="' . h($check[0]) . '"><b>' . h(strtoupper($check[0])) . '</b> ' . h($check[1]) . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * Progress page that continues automatically.
     *
     * @param string $title
     * @param float|null $fraction
     * @param string $detail
     * @param string|null $csrf
     * @return void
     */
    private function progress_page(string $title, ?float $fraction, string $detail, ?string $csrf = null): void {
        $percent = $fraction === null ? null : (int) floor(min(1, max(0, $fraction)) * 100);
        $action = ($this->state['step'] ?? '') === 'run' ? 'continue' : '';
        $this->page($title, ($percent !== null ? '<div class="bar"><div style="width:' . $percent . '%">' . $percent .
            '%</div></div>' : '') . '<p>' . h($detail) . '</p>' .
            '<form method="post" id="next">' . $this->csrf($csrf) . '<input type="hidden" name="action" value="' . $action . '">' .
            '<noscript><button>Continue</button></noscript></form>' .
            '<script>setTimeout(function(){document.getElementById("next").submit();}, 300);</script>' .
            '<p><small>Do not close this page. It continues automatically.</small></p>');
    }

    /**
     * Output a page.
     *
     * @param string $title
     * @param string $body Trusted HTML.
     * @return void
     */
    private function page(string $title, string $body): void {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">' .
            '<title>' . h($title) . ' - Moodle Clone installer</title><style>' .
            'body{font-family:system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;color:#222}' .
            'h1{font-size:1.5rem}h2{font-size:1.15rem;margin-top:1.5rem}table{border-collapse:collapse;width:100%}' .
            'th,td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid #ddd;vertical-align:top}th{width:12rem}' .
            'pre,code{background:#f3f3f3;padding:.1rem .3rem}pre{padding:.6rem;overflow:auto}' .
            '.err{color:#a00}.warn{color:#8a5a00}.ok{color:#0a6b2d}ul.checks{list-style:none;padding:0}' .
            'ul.checks li{padding:.25rem 0}li.error b{color:#a00}li.warning b{color:#8a5a00}li.ok b{color:#0a6b2d}' .
            'button,.button{background:#0f6cbf;color:#fff;border:0;padding:.5rem 1rem;border-radius:4px;cursor:pointer;' .
            'text-decoration:none;display:inline-block}button.secondary{background:#6c757d}form.inline{display:inline}' .
            '.bar{background:#e9ecef;border-radius:4px;height:1.6rem;overflow:hidden}.bar div{background:#0f6cbf;color:#fff;' .
            'height:100%;text-align:center;line-height:1.6rem;min-width:2.5rem}input{padding:.3rem}' .
            '</style></head><body><h1>' . h($title) . '</h1>' . $body .
            '<hr><p><small>Moodle Clone installer ' . h(VERSION) . '</small></p></body></html>';
    }
}

/**
 * Steps that run inside the restored Moodle, after config.php exists.
 *
 * Each runs in its own request: the installer's session is closed, the
 * request is presented to Moodle as a request for the new wwwroot, and
 * Moodle's own setup is loaded with NO_MOODLE_COOKIES. From here on only
 * Moodle APIs are used (set_config, the DML layer, purge_all_caches).
 */
class moodle_steps {

    /** @var string[] Tables never rewritten (same list as Moodle's db_should_replace(), plus the config tables handled via set_config). */
    private const SKIP_TABLES = ['config', 'config_plugins', 'filter_config', 'sessions', 'events_queue',
        'repository_instance_config', 'block_instances', 'files'];

    /**
     * Boot Moodle and run a step.
     *
     * @param string $task
     * @param string $dir
     * @param array $settings
     * @param array $manifest
     * @return array Result.
     */
    public static function run(string $task, string $dir, array $settings, array $manifest): array {
        global $CFG, $DB;
        $url = parse_url($settings['wwwroot']);
        $https = strtolower($url['scheme']) === 'https';
        $_SERVER['HTTP_HOST'] = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $_SERVER['SERVER_PORT'] = (string) ($url['port'] ?? ($https ? 443 : 80));
        $_SERVER['HTTPS'] = $https ? 'on' : 'off';
        $script = rtrim($url['path'] ?? '', '/') . '/' . basename(__FILE__);
        $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $_SERVER['REQUEST_URI'] = $script;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        define('NO_MOODLE_COOKIES', true);
        define('NO_OUTPUT_BUFFERING', true);
        require($dir . '/config.php');
        require_once($CFG->libdir . '/adminlib.php');
        \core_php_time_limit::raise();

        switch ($task) {
            case 'moodle_paths':
                return self::paths($settings, $manifest);
            case 'moodle_urls':
                return self::urls($settings, $manifest);
            case 'moodle_verify':
                return self::verify($settings, $manifest);
        }
        throw new installer_exception('Unknown Moodle step.');
    }

    /**
     * Check the restored version and migrate filesystem paths stored in configuration.
     *
     * @param array $settings
     * @param array $manifest
     * @return array
     */
    private static function paths(array $settings, array $manifest): array {
        global $CFG, $DB;
        if ((string) (float) $CFG->version !== (string) (float) $manifest['moodle_version']) {
            throw new installer_exception('The restored database is at Moodle version ' . $CFG->version . ' but the package says ' .
                $manifest['moodle_version'] . '.');
        }
        $needsupgrade = moodle_needs_upgrading();
        $changed = 0;
        $pairs = [];
        foreach (['dataroot' => $settings['dataroot'], 'dirroot' => $CFG->dirroot] as $key => $new) {
            $old = rtrim($manifest[$key], '/');
            if ($old !== '' && $old !== rtrim($new, '/')) {
                $pairs[$old] = rtrim($new, '/');
            }
        }
        foreach ($pairs as $old => $new) {
            $like = $DB->sql_like('value', ':old', true, true);
            $params = ['old' => '%' . $DB->sql_like_escape($old) . '%'];
            foreach ($DB->get_records_select('config', $like, $params) as $record) {
                $value = self::replace_value($record->value, $old, $new);
                if ($value !== null && $value !== $record->value) {
                    set_config($record->name, $value);
                    $changed++;
                }
            }
            foreach ($DB->get_records_select('config_plugins', $like, $params) as $record) {
                $value = self::replace_value($record->value, $old, $new);
                if ($value !== null && $value !== $record->value) {
                    set_config($record->name, $value, $record->plugin);
                    $changed++;
                }
            }
        }
        // A clone inherits the source's Moodle Clone job records. A backup that was queued or running on the source when the
        // database was dumped has no worker here and would block new backups (and show as "running" forever): close it.
        $jobs = 0;
        if ($DB->get_manager()->table_exists('tool_moodleclone_jobs')) {
            $now = time();
            $jobs = $DB->count_records_select('tool_moodleclone_jobs', "status IN ('pending', 'running')");
            if ($jobs) {
                $DB->execute("UPDATE {tool_moodleclone_jobs} SET status = :failed, timefinished = :finished, timemodified = :modified, " .
                    "errormessage = :message WHERE status IN ('pending', 'running')", ['failed' => 'failed', 'finished' => $now,
                    'modified' => $now, 'message' => 'This backup was queued or running on the source site when it was cloned; it did ' .
                    'not run here.']);
            }
        }
        return ['paths' => $changed, 'needsupgrade' => $needsupgrade, 'jobs' => $jobs];
    }

    /**
     * Rewrite links to the old wwwroot, value by value.
     *
     * Unlike a column-wide SQL REPLACE(), this only touches rows that contain
     * the old address, rewrites PHP-serialized values by unserialising and
     * re-serialising them (so string lengths stay correct), also handles the
     * JSON-escaped form ("http:\/\/..."), never truncates a value that would
     * no longer fit its column, and reports what it had to leave alone.
     *
     * @param array $settings
     * @param array $manifest
     * @return array
     */
    private static function urls(array $settings, array $manifest): array {
        global $DB, $CFG;
        $old = rtrim($manifest['wwwroot'], '/');
        $new = rtrim($settings['wwwroot'], '/');
        if ($old === $new) {
            return ['updated' => 0, 'tables' => 0, 'skipped' => []];
        }
        $variants = [$old => $new, str_replace('/', '\\/', $old) => str_replace('/', '\\/', $new)];
        $updated = 0;
        $tables = [];
        $skipped = [];

        foreach ($DB->get_tables(false) as $table) {
            if (in_array($table, self::SKIP_TABLES, true) || preg_match('/(^|_)logs?($|_)/', $table)) {
                continue;
            }
            $columns = $DB->get_columns($table, false);
            if (!isset($columns['id'])) {
                continue;
            }
            foreach ($columns as $name => $column) {
                if (!in_array($column->meta_type, ['C', 'X'], true) || preg_match('/hash$/', $name)) {
                    continue;
                }
                foreach ($variants as $search => $replace) {
                    $select = $DB->sql_like($name, ':search', true, true);
                    $rs = $DB->get_recordset_select($table, $select, ['search' => '%' . $DB->sql_like_escape($search) . '%'], 'id',
                        'id, ' . $name);
                    foreach ($rs as $record) {
                        $value = self::replace_value((string) $record->$name, $search, $replace);
                        if ($value === null || ($column->meta_type === 'C' && $column->max_length > 0 &&
                                \core_text::strlen($value) > $column->max_length)) {
                            $skipped[] = "{$table}.{$name}#{$record->id}";
                            continue;
                        }
                        if ($value !== $record->$name) {
                            $DB->set_field($table, $name, $value, ['id' => $record->id]);
                            $updated++;
                            $tables[$table] = true;
                        }
                    }
                    $rs->close();
                }
            }
        }

        // Plain-text configuration values (e.g. custom menu links) via set_config.
        $like = $DB->sql_like('value', ':old', true, true);
        $params = ['old' => '%' . $DB->sql_like_escape($old) . '%'];
        foreach ($DB->get_records_select('config', $like, $params) as $record) {
            $value = self::replace_value($record->value, $old, $new);
            if ($value !== null && $value !== $record->value) {
                set_config($record->name, $value);
                $updated++;
                $tables['config'] = true;
            }
        }
        foreach ($DB->get_records_select('config_plugins', $like, $params) as $record) {
            $value = self::replace_value($record->value, $old, $new);
            if ($value !== null && $value !== $record->value) {
                set_config($record->name, $value, $record->plugin);
                $updated++;
                $tables['config_plugins'] = true;
            }
        }

        // Blocks store serialized configuration; let each block rewrite its own, as Moodle's db_replace() does.
        foreach (\core_component::get_plugin_list('block') as $block => $blockdir) {
            if (is_readable($blockdir . '/lib.php')) {
                include_once($blockdir . '/lib.php');
                $function = 'block_' . $block . '_global_db_replace';
                if (function_exists($function)) {
                    $function($old, $new);
                }
            }
        }
        return ['updated' => $updated, 'tables' => count($tables), 'skipped' => $skipped];
    }

    /**
     * Replace inside a value; PHP-serialized values are rewritten structurally.
     *
     * @param string $value
     * @param string $search
     * @param string $replace
     * @return string|null Null when the value is serialized data that cannot be rewritten safely.
     */
    public static function replace_value(string $value, string $search, string $replace): ?string {
        if (strpos($value, $search) === false) {
            return $value;
        }
        if (preg_match('/^(a|O|C|s|i|d|b|N):/', $value) && ($value === 'b:0;' ||
                ($data = @unserialize($value, ['allowed_classes' => false])) !== false)) {
            if ($value === 'b:0;') {
                return $value;
            }
            if (self::contains_object($data)) {
                return null;
            }
            $rewritten = self::replace_deep($data, $search, $replace);
            $result = serialize($rewritten);
            return @unserialize($result, ['allowed_classes' => false]) === $rewritten ? $result : null;
        }
        return str_replace($search, $replace, $value);
    }

    /**
     * Whether unserialized data contains objects (which were not restored, so cannot be re-serialized faithfully).
     *
     * @param mixed $data
     * @return bool
     */
    private static function contains_object($data): bool {
        if (is_object($data)) {
            return true;
        }
        if (is_array($data)) {
            foreach ($data as $item) {
                if (self::contains_object($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Replace in every string of a structure.
     *
     * @param mixed $data
     * @param string $search
     * @param string $replace
     * @return mixed
     */
    private static function replace_deep($data, string $search, string $replace) {
        if (is_string($data)) {
            return str_replace($search, $replace, $data);
        }
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $item) {
                $result[is_string($key) ? str_replace($search, $replace, $key) : $key] = self::replace_deep($item, $search, $replace);
            }
            return $result;
        }
        return $data;
    }

    /**
     * Purge caches and verify the restored site.
     *
     * @param array $settings
     * @param array $manifest
     * @return array
     */
    private static function verify(array $settings, array $manifest): array {
        global $CFG, $DB;
        purge_all_caches();
        $checks = [];
        $prefix = $manifest['table_prefix'];
        $tables = count($DB->get_tables(false));
        $expected = (int) $manifest['statistics']['database']['tables'];
        $checks[] = $tables === $expected ? ['ok', "Moodle sees all {$tables} database tables."] :
            ['error', "Moodle sees {$tables} tables; the package has {$expected}."];
        $site = get_site();
        $checks[] = $site ? ['ok', 'Site: ' . format_string($site->fullname, true, ['escape' => false]) . '.'] :
            ['error', 'The site record is missing.'];
        $admins = array_filter(array_map('intval', explode(',', (string) $CFG->siteadmins)));
        $checks[] = $admins && $DB->record_exists_select('user', 'id IN (' . implode(',', $admins) . ') AND deleted = 0') ?
            ['ok', count($admins) . ' site administrator account(s) present.'] : ['error', 'No site administrator account found.'];
        $checks[] = moodle_needs_upgrading() ? ['warning', 'Moodle reports that an upgrade is needed. Open ' .
            $settings['wwwroot'] . '/admin/index.php as an administrator (or run admin/cli/upgrade.php) before using the site.'] :
            ['ok', 'The database matches the code (Moodle ' . $CFG->release . '); no upgrade needed.'];

        // File pool: the newest 500 files referenced by the database must exist on disk.
        $missing = 0;
        $sample = $DB->get_records_sql('SELECT id, contenthash FROM {files} WHERE filesize > 0 ORDER BY id DESC', [], 0, 500);
        $filedir = $CFG->filedir ?? ($CFG->dataroot . '/filedir');
        foreach ($sample as $file) {
            $h = $file->contenthash;
            if (!is_file($filedir . '/' . substr($h, 0, 2) . '/' . substr($h, 2, 2) . '/' . $h)) {
                $missing++;
            }
        }
        $checks[] = $missing === 0 ? ['ok', 'File pool: ' . count($sample) . ' sampled files referenced by the database are ' .
            'present.'] : ['error', "File pool: {$missing} of " . count($sample) . ' sampled files are missing.'];

        $probe = make_request_directory();
        $checks[] = is_dir($probe) && is_writable($probe) ? ['ok', 'Moodledata is writable by the web server.'] :
            ['error', 'Moodledata is not writable by the web server.'];
        $checks[] = !empty($CFG->noemailever) ? ['ok', 'Outgoing email is disabled on this copy.'] :
            ['warning', 'Outgoing email is enabled: this copy can email the source site\'s users.'];
        return ['checks' => $checks];
    }
}

if (!defined('MOODLECLONE_INSTALLER_NO_MAIN')) {
    installer::main();
}
