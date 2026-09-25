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

use tool_moodleclone\local\environment\snapshot;

/**
 * The manifest.json of a clone package.
 *
 * Instances are always valid: every constructor path runs
 * {@see manifest_validator}. See README.md for what each field is for.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manifest {
    /** @var int Format written into new packages (1 was the unreleased Phase 1 draft; 2 lacked installer_auth). */
    public const FORMAT = 3;

    /** @var string Product identifier, lets the installer reject unrelated zip files early. */
    public const PRODUCT = 'moodle-clone';

    /** @var string Format of the "created" field (ISO 8601, UTC). */
    public const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @var string Contents flag: Moodle code tree. */
    public const CONTENT_MOODLE = 'moodle';

    /** @var string Contents flag: persistent moodledata. */
    public const CONTENT_MOODLEDATA = 'moodledata';

    /** @var string Contents flag: database dump. */
    public const CONTENT_DATABASE = 'database';

    /** @var string[] All contents flags. */
    public const CONTENTS = [self::CONTENT_MOODLE, self::CONTENT_MOODLEDATA, self::CONTENT_DATABASE];

    /** @var array Validated manifest data. */
    private $data;

    /**
     * Use the static factories.
     *
     * @param array $data
     * @throws invalid_package_exception
     */
    private function __construct(array $data) {
        $errors = manifest_validator::validate($data);
        if ($errors) {
            throw new invalid_package_exception($errors);
        }
        $this->data = $data;
    }

    /**
     * Build the manifest for a package created from the given installation.
     *
     * @param snapshot $snapshot Source installation.
     * @param bool[] $contents Contents flag => included.
     * @param int $created Unix time the package was created.
     * @param array $generator ['version' => int, 'release' => string] of tool_moodleclone.
     * @param array $statistics Component => counts (null for components not included).
     * @param array|null $databasedump Dump description, null when the database is not included.
     * @param string[] $moodledataexcluded Excluded moodledata paths.
     * @param installer_auth|null $installerauth How the installer authorizes its user; the key file when omitted.
     * @return self
     * @throws invalid_package_exception
     */
    public static function from_snapshot(
        snapshot $snapshot,
        array $contents,
        int $created,
        array $generator,
        array $statistics,
        ?array $databasedump,
        array $moodledataexcluded,
        ?installer_auth $installerauth = null
    ): self {
        return new self([
            'format' => self::FORMAT,
            'product' => self::PRODUCT,
            'created' => gmdate(self::DATE_FORMAT, $created),
            'generator' => [
                'component' => 'tool_moodleclone',
                'version' => (int) ($generator['version'] ?? 0),
                'release' => (string) ($generator['release'] ?? ''),
            ],
            'moodle_version' => $snapshot->moodleversion,
            'moodle_release' => $snapshot->moodlerelease,
            'moodle_branch' => $snapshot->moodlebranch,
            'php_version' => $snapshot->phpversion,
            'database_type' => $snapshot->dbtype,
            'database_family' => $snapshot->dbfamily,
            'database_version' => $snapshot->dbversion,
            'wwwroot' => $snapshot->wwwroot,
            'dirroot' => $snapshot->dirroot,
            'dataroot' => $snapshot->dataroot,
            'table_prefix' => $snapshot->prefix,
            'package_contents' => $contents,
            'statistics' => [
                self::CONTENT_MOODLE => $statistics[self::CONTENT_MOODLE] ?? null,
                self::CONTENT_MOODLEDATA => $statistics[self::CONTENT_MOODLEDATA] ?? null,
                self::CONTENT_DATABASE => $statistics[self::CONTENT_DATABASE] ?? null,
            ],
            'database_dump' => $databasedump,
            'moodledata_excluded' => array_values($moodledataexcluded),
            'installer_auth' => ($installerauth ?? installer_auth::keyfile())->to_array(),
        ]);
    }

    /**
     * Load a manifest from decoded data.
     *
     * @param array $data
     * @return self
     * @throws invalid_package_exception
     */
    public static function from_array(array $data): self {
        return new self($data);
    }

    /**
     * Parse manifest.json content.
     *
     * @param string $json
     * @return self
     * @throws invalid_package_exception
     */
    public static function from_json(string $json): self {
        $data = json_decode($json, true, 16);
        if (!is_array($data)) {
            throw new invalid_package_exception(['manifest is not a JSON object']);
        }
        return new self($data);
    }

    /**
     * Manifest data as an array.
     *
     * @return array
     */
    public function to_array(): array {
        return $this->data;
    }

    /**
     * Manifest as pretty-printed JSON, ready to be written to manifest.json.
     *
     * @return string
     */
    public function to_json(): string {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * A single top-level field.
     *
     * @param string $key One of manifest_validator::FIELDS.
     * @return mixed
     */
    public function get(string $key) {
        if (!array_key_exists($key, manifest_validator::FIELDS + manifest_validator::FIELDS_V3)) {
            throw new \coding_exception("Unknown manifest field '{$key}'");
        }
        return $this->data[$key] ?? null;
    }

    /**
     * How the installer authorizes its user. Format 2 packages predate the field and use the key file.
     *
     * @return installer_auth
     */
    public function get_installer_auth(): installer_auth {
        return isset($this->data['installer_auth']) ? installer_auth::from_array($this->data['installer_auth']) :
            installer_auth::keyfile();
    }

    /**
     * Whether the package includes a component.
     *
     * @param string $content One of the CONTENT_* constants.
     * @return bool
     */
    public function includes(string $content): bool {
        return !empty($this->data['package_contents'][$content]);
    }
}
