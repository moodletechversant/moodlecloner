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
 * Validates decoded manifest data against the supported formats.
 *
 * The format is closed: unknown keys are rejected, so adding a field means a
 * new format number. This keeps the installer from silently trusting
 * data it does not understand and stops secrets being slipped in under new
 * keys. Format 1 (Phase 1) was a draft that no released version ever wrote
 * into a package; format 2 is the first format of real packages (Phases 2 and
 * 3). Format 3 (Phase 3.1) adds "installer_auth", how the standalone installer
 * authorizes its user; format 2 packages remain valid and use the key file.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manifest_validator {
    /** @var string[] Top-level key => expected type ("int", "string", "?string", "array", "?array"). */
    public const FIELDS = [
        'format' => 'int',
        'product' => 'string',
        'created' => 'string',
        'generator' => 'array',
        'moodle_version' => 'string',
        'moodle_release' => 'string',
        'moodle_branch' => 'string',
        'php_version' => 'string',
        'database_type' => 'string',
        'database_family' => 'string',
        'database_version' => '?string',
        'wwwroot' => 'string',
        'dirroot' => 'string',
        'dataroot' => 'string',
        'table_prefix' => 'string',
        'package_contents' => 'array',
        'statistics' => 'array',
        'database_dump' => '?array',
        'moodledata_excluded' => 'array',
    ];

    /** @var int[] Formats this version can read. Packages are written in manifest::FORMAT. */
    public const SUPPORTED_FORMATS = [2, 3];

    /** @var string[] Fields added by format 3. */
    public const FIELDS_V3 = ['installer_auth' => 'array'];

    /** @var string[] generator object. */
    public const GENERATOR_FIELDS = ['component' => 'string', 'version' => 'int', 'release' => 'string'];

    /** @var array statistics.<component> objects. */
    public const STATISTICS_FIELDS = [
        manifest::CONTENT_MOODLE => ['files' => 'int', 'directories' => 'int', 'symlinks' => 'int', 'bytes' => 'int'],
        manifest::CONTENT_MOODLEDATA => ['files' => 'int', 'directories' => 'int', 'symlinks' => 'int', 'bytes' => 'int',
            'recovered_from_trash' => 'int'],
        manifest::CONTENT_DATABASE => ['tables' => 'int', 'rows' => 'int'],
    ];

    /** @var string[] database_dump object. */
    public const DATABASE_DUMP_FIELDS = ['format' => 'string', 'format_version' => 'int', 'compression' => 'string',
        'charset' => 'string', 'collation' => 'string', 'consistency' => 'string', 'max_statement_bytes' => 'int'];

    /** @var string[] DML families Moodle supports. */
    public const DATABASE_FAMILIES = ['mysql', 'postgres', 'mssql', 'oracle'];

    /** @var string Key names that suggest a secret. Matched case-insensitively anywhere in the tree. */
    public const FORBIDDEN_KEY_PATTERN = '/pass(word|wd)?|secret|token|api_?key|private_?key|salt|cookie|session|credential/i';

    /**
     * Validate decoded manifest data.
     *
     * @param array $data
     * @return string[] Problems found; empty when valid.
     */
    public static function validate(array $data): array {
        $errors = [];

        foreach (self::find_forbidden_keys($data) as $key) {
            $errors[] = "forbidden key '{$key}'";
        }
        $format = $data['format'] ?? null;
        if (is_int($format) && !in_array($format, self::SUPPORTED_FORMATS, true)) {
            // Other formats have other fields, so nothing else can be checked meaningfully.
            $errors[] = "unsupported format {$format}";
            return $errors;
        }
        $fields = $format === 3 ? self::FIELDS + self::FIELDS_V3 : self::FIELDS;
        $errors = array_merge($errors, self::check_object($data, $fields, ''));
        if ($errors) {
            // Content checks below assume the structure is right.
            return $errors;
        }

        if (isset($data['installer_auth'])) {
            $errors = array_merge($errors, installer_auth::validate($data['installer_auth']));
        }
        if ($data['product'] !== manifest::PRODUCT) {
            $errors[] = "'product' must be '" . manifest::PRODUCT . "'";
        }
        $created = \DateTime::createFromFormat(manifest::DATE_FORMAT, $data['created'], new \DateTimeZone('UTC'));
        if ($created === false || $created->format(manifest::DATE_FORMAT) !== $data['created']) {
            $errors[] = "'created' must be an ISO 8601 UTC timestamp";
        }
        $generatorerrors = self::check_object($data['generator'], self::GENERATOR_FIELDS, 'generator');
        if ($generatorerrors || $data['generator']['component'] !== 'tool_moodleclone') {
            $errors[] = "'generator' must contain component 'tool_moodleclone', an integer version and a release";
        }
        if (!preg_match('/^\d{10}(\.\d{1,2})?$/', $data['moodle_version'])) {
            $errors[] = "'moodle_version' is not a Moodle version number";
        }
        if (!in_array($data['database_family'], self::DATABASE_FAMILIES, true)) {
            $errors[] = "'database_family' is not a supported family";
        }
        if (!self::is_http_url($data['wwwroot'])) {
            $errors[] = "'wwwroot' must be an http or https URL without a trailing slash";
        }
        foreach (['dirroot', 'dataroot'] as $key) {
            if (!self::is_absolute_path($data[$key])) {
                $errors[] = "'{$key}' must be an absolute path";
            }
        }
        if (!preg_match('/^[a-z0-9_]{0,30}$/', $data['table_prefix'])) {
            $errors[] = "'table_prefix' may contain only lowercase letters, digits and underscores";
        }

        $contentserrors = self::validate_contents($data['package_contents']);
        $errors = array_merge($errors, $contentserrors);
        if (!$contentserrors) {
            $errors = array_merge($errors, self::validate_statistics($data['statistics'], $data['package_contents']));
            $errors = array_merge($errors, self::validate_database_dump(
                $data['database_dump'],
                $data['package_contents'][manifest::CONTENT_DATABASE]
            ));
        }

        if (array_values($data['moodledata_excluded']) !== $data['moodledata_excluded']) {
            $errors[] = "'moodledata_excluded' must be a list";
        } else {
            foreach ($data['moodledata_excluded'] as $path) {
                if (!is_string($path) || !\tool_moodleclone\local\filesystem\path_validator::is_safe_relative_path($path)) {
                    $errors[] = "'moodledata_excluded' may contain only relative paths";
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Validate the package_contents object.
     *
     * @param array $contents
     * @return string[]
     */
    private static function validate_contents(array $contents): array {
        $errors = [];
        $expected = manifest::CONTENTS;
        foreach (array_diff(array_keys($contents), $expected) as $key) {
            $errors[] = "unknown package_contents key '{$key}'";
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $contents) || !is_bool($contents[$key])) {
                $errors[] = "package_contents.{$key} must be a boolean";
            }
        }
        if (!$errors && !in_array(true, $contents, true)) {
            $errors[] = 'package_contents must include at least one component';
        }
        return $errors;
    }

    /**
     * Statistics must exist exactly for the included components.
     *
     * @param array $statistics
     * @param bool[] $contents
     * @return string[]
     */
    private static function validate_statistics(array $statistics, array $contents): array {
        $errors = [];
        foreach (array_diff(array_keys($statistics), manifest::CONTENTS) as $key) {
            $errors[] = "unknown statistics key '{$key}'";
        }
        foreach (self::STATISTICS_FIELDS as $component => $fields) {
            $value = $statistics[$component] ?? null;
            if (!$contents[$component]) {
                if ($value !== null) {
                    $errors[] = "statistics.{$component} must be null when the component is not included";
                }
                continue;
            }
            if (!is_array($value)) {
                $errors[] = "statistics.{$component} is required";
                continue;
            }
            $errors = array_merge($errors, self::check_object($value, $fields, "statistics.{$component}"));
            foreach ($value as $key => $number) {
                if (is_int($number) && $number < 0) {
                    $errors[] = "statistics.{$component}.{$key} must not be negative";
                }
            }
        }
        return $errors;
    }

    /**
     * database_dump must describe the dump exactly when one is included.
     *
     * @param array|null $dump
     * @param bool $included
     * @return string[]
     */
    private static function validate_database_dump(?array $dump, bool $included): array {
        if (!$included) {
            return $dump === null ? [] : ["'database_dump' must be null when the database is not included"];
        }
        if ($dump === null) {
            return ["'database_dump' is required when the database is included"];
        }
        $errors = self::check_object($dump, self::DATABASE_DUMP_FIELDS, 'database_dump');
        if ($errors) {
            return $errors;
        }
        if ($dump['format'] !== 'mysql' || $dump['format_version'] !== 1) {
            $errors[] = 'unsupported database_dump format';
        }
        if ($dump['compression'] !== 'gzip') {
            $errors[] = "database_dump.compression must be 'gzip'";
        }
        if ($dump['consistency'] !== 'snapshot') {
            $errors[] = "database_dump.consistency must be 'snapshot'";
        }
        foreach (['charset', 'collation'] as $key) {
            if (!preg_match('/^[a-z0-9_]{1,64}$/', $dump[$key])) {
                $errors[] = "database_dump.{$key} is not a valid name";
            }
        }
        if ($dump['max_statement_bytes'] < 0) {
            $errors[] = 'database_dump.max_statement_bytes must not be negative';
        }
        return $errors;
    }

    /**
     * Check keys and value types of an object against a schema.
     *
     * @param array $value
     * @param string[] $schema key => type
     * @param string $path Dotted path for messages ('' for the top level).
     * @return string[]
     */
    private static function check_object(array $value, array $schema, string $path): array {
        $errors = [];
        $prefix = $path === '' ? '' : $path . '.';
        foreach (array_keys($value) as $key) {
            if (!array_key_exists($key, $schema)) {
                $errors[] = "unknown key '{$prefix}{$key}'";
            }
        }
        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $value)) {
                $errors[] = "missing key '{$prefix}{$key}'";
            } else if (!self::has_type($value[$key], $type)) {
                $errors[] = "'{$prefix}{$key}' must be of type {$type}";
            }
        }
        return $errors;
    }

    /**
     * Recursively collect keys that look like they hold secrets.
     *
     * The one exemption is the top-level "installer_auth" object: it holds a
     * public salt and a derived verifier (never a password), and is checked
     * against its own strict schema by {@see installer_auth::validate()}.
     *
     * @param array $data
     * @param string $prefix Dotted path of the parent, for error messages.
     * @return string[]
     */
    public static function find_forbidden_keys(array $data, string $prefix = ''): array {
        $found = [];
        foreach ($data as $key => $value) {
            if ($prefix === '' && $key === 'installer_auth') {
                continue;
            }
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY_PATTERN, $key)) {
                $found[] = $path;
            }
            if (is_array($value)) {
                $found = array_merge($found, self::find_forbidden_keys($value, $path));
            }
        }
        return $found;
    }

    /**
     * Type check matching the notation in the schemas.
     *
     * @param mixed $value
     * @param string $type
     * @return bool
     */
    private static function has_type($value, string $type): bool {
        if ($type[0] === '?') {
            if ($value === null) {
                return true;
            }
            $type = substr($type, 1);
        }
        switch ($type) {
            case 'int':
                return is_int($value);
            case 'string':
                return is_string($value);
            case 'array':
                return is_array($value);
        }
        return false;
    }

    /**
     * Whether a value is an http(s) URL without a trailing slash, as $CFG->wwwroot must be.
     *
     * @param string $url
     * @return bool
     */
    private static function is_http_url(string $url): bool {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || substr($url, -1) === '/') {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }

    /**
     * Whether a path is absolute on Unix or Windows.
     *
     * @param string $path
     * @return bool
     */
    private static function is_absolute_path(string $path): bool {
        return $path !== '' && ($path[0] === '/' || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path));
    }
}
