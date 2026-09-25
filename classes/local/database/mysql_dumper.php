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

namespace tool_moodleclone\local\database;

use tool_moodleclone\local\backup\backup_exception;

/**
 * Dumps the Moodle tables of a MySQL database as SQL, from one consistent snapshot.
 *
 * Consistency: begin() starts "START TRANSACTION WITH CONSISTENT SNAPSHOT,
 * READ ONLY" at REPEATABLE READ on a dedicated connection. InnoDB then
 * serves every read in the dump from the same MVCC snapshot, so all tables
 * reflect one point in time without locking the site (the same technique
 * as mysqldump --single-transaction). This only holds when:
 *  - every table is InnoDB (checked; other engines make begin() fail), and
 *  - no DDL changes the tables (ALTER/DROP/RENAME/TRUNCATE are not
 *    transactional in MySQL). begin() reads every table definition (SHOW CREATE
 *    TABLE) right after the snapshot starts; this also takes MySQL metadata
 *    locks, so from then on DDL and TRUNCATE on those tables wait until the
 *    dump ends instead of breaking it. After dumping, the table list and every
 *    definition are compared with the ones captured at the start (catching a
 *    change in the short window before the locks, or a new table); any
 *    difference fails the dump.
 *
 * Operational consequence: a plugin upgrade, or a scheduled task that
 * truncates a table (e.g. context_temp, tag_correlation), waits for the dump
 * to finish; queries queued behind such a waiting statement wait too.
 *
 * Data is read with moodle_database::export_table_recordset(), which on
 * MySQL streams rows unbuffered, so memory does not depend on table size.
 *
 * SQL safety: no value from the database is ever placed in SQL as text.
 * Strings, dates and JSON are written as hex literals with a character set
 * introducer (_utf8mb4 X'...'), binary data as X'...', and numbers only
 * after matching a strict numeric pattern. Identifiers come from
 * information_schema and are backtick-quoted with backticks doubled. The
 * output is therefore immune to quoting, escaping, sql_mode and charset
 * tricks in the data.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mysql_dumper {
    /** @var string Dump format name recorded in the manifest. */
    public const FORMAT = 'mysql';

    /** @var int Dump format version recorded in the manifest. */
    public const FORMAT_VERSION = 1;

    /** @var string Last line prefix; its absence means the dump is truncated. */
    public const COMPLETION_MARKER = '-- Moodle Clone dump completed';

    /**
     * @var string[] Tables (without prefix) dumped as structure only. Session rows are
     * security sensitive (session ids) and meaningless on another site, matching the
     * exclusion of moodledata/sessions.
     */
    public const STRUCTURE_ONLY = ['sessions'];

    /** @var int Target size of one INSERT statement. */
    public const MAX_STATEMENT_BYTES = 1048576;

    /** @var int Maximum rows per INSERT statement. */
    public const MAX_STATEMENT_ROWS = 1000;

    /** @var string[] information_schema DATA_TYPE => value encoding kind. */
    private const KINDS = [
        'tinyint' => 'int', 'smallint' => 'int', 'mediumint' => 'int', 'int' => 'int', 'integer' => 'int', 'bigint' => 'int',
        'decimal' => 'decimal', 'numeric' => 'decimal',
        'float' => 'float', 'double' => 'float', 'real' => 'float',
        'char' => 'string', 'varchar' => 'string', 'tinytext' => 'string', 'text' => 'string',
        'mediumtext' => 'string', 'longtext' => 'string', 'enum' => 'string', 'set' => 'string',
        'binary' => 'binary', 'varbinary' => 'binary', 'tinyblob' => 'binary', 'blob' => 'binary',
        'mediumblob' => 'binary', 'longblob' => 'binary', 'bit' => 'binary',
        'geometry' => 'binary', 'point' => 'binary', 'linestring' => 'binary', 'polygon' => 'binary',
        'multipoint' => 'binary', 'multilinestring' => 'binary', 'multipolygon' => 'binary',
        'geometrycollection' => 'binary', 'geomcollection' => 'binary',
        'date' => 'temporal', 'time' => 'temporal', 'datetime' => 'temporal', 'timestamp' => 'temporal', 'year' => 'temporal',
        'json' => 'json',
    ];

    /** @var \moodle_database Dedicated connection. */
    private $db;

    /** @var string */
    private $prefix;

    /** @var string[]|null Restrict to these tables (without prefix); tests only. */
    private $onlytables;

    /** @var bool */
    private $intransaction = false;

    /** @var int|null */
    private $snapshottime = null;

    /** @var string Connection result character set, used for introducers. */
    private $charset = '';

    /** @var string Schema default character set. */
    private $schemacharset = '';

    /** @var string Schema default collation. */
    private $schemacollation = '';

    /** @var array|null Table name => ['rows' => int, 'columns' => array[], 'create' => string]. */
    private $tables = null;

    /** @var string[] Prefixed views that were skipped. */
    private $views = [];

    /**
     * Constructor.
     *
     * @param \moodle_database $db A connection used for nothing else while dumping.
     * @param string[]|null $onlytables Restrict to these tables (names without prefix).
     */
    public function __construct(\moodle_database $db, ?array $onlytables = null) {
        if ($db->get_dbfamily() !== 'mysql') {
            throw new backup_exception('dbunsupported', $db->get_dbfamily());
        }
        $this->db = $db;
        $this->prefix = $db->get_prefix();
        $this->onlytables = $onlytables;
    }

    /**
     * Start the snapshot and read the table definitions.
     *
     * @return void
     * @throws backup_exception
     */
    public function begin(): void {
        try {
            $this->db->execute("SET SESSION time_zone = '+00:00'");
            $this->db->execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->snapshottime = time();
            $this->db->execute('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $this->intransaction = true;
        } catch (\dml_exception $e) {
            throw new backup_exception('dbsnapshot', null, $e->getMessage());
        }
        $this->tables = $this->inspect();
    }

    /**
     * Unix time just before the snapshot started.
     *
     * @return int|null
     */
    public function get_snapshot_time(): ?int {
        return $this->snapshottime;
    }

    /**
     * Tables that will be dumped.
     *
     * @return array Name => ['rows' => estimated rows, 'columns' => array[], 'create' => string]
     */
    public function get_tables(): array {
        if ($this->tables === null) {
            throw new \coding_exception('Call begin() first');
        }
        return $this->tables;
    }

    /**
     * Write the dump.
     *
     * @param sink $sink
     * @param callable|null $progress fn(float $fraction)
     * @return array ['tables', 'rows', 'max_statement_bytes', 'charset', 'collation', 'views']
     */
    public function dump(sink $sink, ?callable $progress = null): array {
        $tables = $this->get_tables();
        $estimated = max(1, array_sum(array_column($tables, 'rows')));
        $rows = 0;
        $maxstatement = 0;
        $definitions = [];

        $sink->write("-- Moodle Clone database dump\n" .
            '-- Format: ' . self::FORMAT . ' ' . self::FORMAT_VERSION . "\n" .
            '-- Table prefix: ' . backup_exception::printable($this->prefix) . "\n" .
            "-- Consistency: one InnoDB snapshot (START TRANSACTION WITH CONSISTENT SNAPSHOT, REPEATABLE READ)\n" .
            '-- Created: ' . gmdate('Y-m-d\TH:i:s\Z', $this->snapshottime) . "\n" .
            "SET NAMES {$this->charset};\n" .
            "SET time_zone = '+00:00';\n" .
            "SET foreign_key_checks = 0;\n" .
            "SET unique_checks = 0;\n" .
            "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n");

        foreach ($tables as $name => $info) {
            $create = $info['create'];
            $definitions[$name] = self::normalise_definition($create);
            $quoted = self::quote_identifier($name);
            $sink->write("\n-- Table: {$quoted}\nDROP TABLE IF EXISTS {$quoted};\n{$create};\n");

            if (in_array(substr($name, strlen($this->prefix)), self::STRUCTURE_ONLY, true)) {
                continue;
            }
            $this->dump_rows(
                $name,
                $info['columns'],
                $sink,
                $rows,
                $maxstatement,
                function () use ($progress, &$rows, $estimated) {
                    if ($progress !== null) {
                        $progress(min(0.99, $rows / $estimated));
                    }
                }
            );
        }

        $this->check_schema_unchanged($definitions);

        $sink->write("\nSET foreign_key_checks = 1;\nSET unique_checks = 1;\n" .
            self::COMPLETION_MARKER . ': ' . count($tables) . " tables, {$rows} rows\n");
        if ($progress !== null) {
            $progress(1.0);
        }

        return [
            'tables' => count($tables),
            'rows' => $rows,
            'max_statement_bytes' => $maxstatement,
            'charset' => $this->schemacharset,
            'collation' => $this->schemacollation,
            'views' => $this->views,
        ];
    }

    /**
     * End the snapshot transaction.
     *
     * @return void
     */
    public function end(): void {
        if ($this->intransaction) {
            $this->intransaction = false;
            $this->db->execute('ROLLBACK');
        }
    }

    /**
     * End the transaction, ignoring errors (used on failure paths).
     *
     * @return void
     */
    public function end_quietly(): void {
        try {
            $this->end();
        } catch (\Throwable $e) {
            // The connection is disposed next, which also ends the transaction.
            $this->intransaction = false;
        }
    }

    /**
     * Stream one table's rows as multi-row INSERT statements.
     *
     * @param string $name Prefixed table name.
     * @param array[] $columns
     * @param sink $sink
     * @param int $rows Running total, updated.
     * @param int $maxstatement Largest statement so far, updated.
     * @param callable $tick Called every 500 rows.
     * @return void
     */
    private function dump_rows(
        string $name,
        array $columns,
        sink $sink,
        int &$rows,
        int &$maxstatement,
        callable $tick
    ): void {
        $unprefixed = substr($name, strlen($this->prefix));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $unprefixed)) {
            // The export_table_recordset() call only handles Moodle-style names.
            throw new backup_exception('dbtablename', $name);
        }
        $insert = 'INSERT INTO ' . self::quote_identifier($name) . ' (' .
            implode(',', array_map([self::class, 'quote_identifier'], array_column($columns, 'name'))) . ') VALUES ';

        $values = '';
        $count = 0;
        $flush = function () use ($sink, $insert, &$values, &$count, &$maxstatement) {
            if ($count === 0) {
                return;
            }
            $statement = $insert . $values . ";\n";
            $maxstatement = max($maxstatement, strlen($statement));
            $sink->write($statement);
            $values = '';
            $count = 0;
        };

        try {
            $recordset = $this->db->export_table_recordset($unprefixed);
        } catch (\dml_exception $e) {
            throw new backup_exception('dbsnapshot', null, $name . ': ' . $e->getMessage());
        }
        try {
            foreach ($recordset as $record) {
                $tuple = '';
                foreach ($columns as $i => $column) {
                    $key = $column['key'];
                    if (!property_exists($record, $key)) {
                        throw new backup_exception('dbcolumnmissing', $name . '.' . $column['name']);
                    }
                    $tuple .= ($i === 0 ? '(' : ',') . self::encode_value($record->$key, $column['kind'], $this->charset);
                }
                $tuple .= ')';
                if (
                    $count > 0 && ($count >= self::MAX_STATEMENT_ROWS ||
                        strlen($insert) + strlen($values) + strlen($tuple) + 3 > self::MAX_STATEMENT_BYTES)
                ) {
                    $flush();
                }
                $values .= ($count === 0 ? '' : ',') . $tuple;
                $count++;
                $rows++;
                if ($rows % 500 === 0) {
                    $tick();
                }
            }
            $flush();
        } catch (\dml_exception $e) {
            // E.g. "Table definition has changed" when DDL hits a table during the dump.
            throw new backup_exception('dbsnapshot', null, $name . ': ' . $e->getMessage());
        } finally {
            $recordset->close();
        }
    }

    /**
     * Read character sets and the definition of every prefixed table.
     *
     * @return array
     */
    private function inspect(): array {
        $schema = $this->db->get_record_sql('SELECT DEFAULT_CHARACTER_SET_NAME AS charsetname,
                DEFAULT_COLLATION_NAME AS collationname
            FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
        $this->schemacharset = (string) ($schema->charsetname ?? '');
        $this->schemacollation = (string) ($schema->collationname ?? '');
        $results = $this->db->get_record_sql('SELECT @@character_set_results AS charsetname');
        $this->charset = (string) ($results->charsetname ?? '');
        foreach ([$this->charset, $this->schemacharset, $this->schemacollation] as $name) {
            if (!preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
                throw new backup_exception('dbcharset', $name);
            }
        }

        $tables = [];
        $nontransactional = [];
        $recordset = $this->db->get_recordset_sql('SELECT TABLE_NAME AS name, TABLE_TYPE AS tabletype, ENGINE AS engine,
                TABLE_ROWS AS tablerows
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($recordset as $record) {
            if (!$this->is_our_table($record->name)) {
                continue;
            }
            if ($record->tabletype !== 'BASE TABLE') {
                $this->views[] = $record->name;
                continue;
            }
            if (strcasecmp((string) $record->engine, 'InnoDB') !== 0) {
                $nontransactional[] = $record->name . ' (' . $record->engine . ')';
            }
            $tables[$record->name] = ['rows' => (int) $record->tablerows, 'columns' => [], 'create' => ''];
        }
        $recordset->close();
        if ($nontransactional) {
            throw new backup_exception('dbnontransactional', implode(', ', $nontransactional));
        }

        $recordset = $this->db->get_recordset_sql('SELECT TABLE_NAME AS tablename, COLUMN_NAME AS name,
                DATA_TYPE AS datatype, EXTRA AS extra
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, ORDINAL_POSITION');
        foreach ($recordset as $record) {
            if (!isset($tables[$record->tablename])) {
                continue;
            }
            $extra = strtoupper((string) $record->extra);
            if (strpos($extra, 'INVISIBLE') !== false) {
                // SELECT * does not return invisible columns, so their data would be lost.
                $recordset->close();
                throw new backup_exception('dbinvisiblecolumn', $record->tablename . '.' . $record->name);
            }
            if (strpos($extra, 'VIRTUAL GENERATED') !== false || strpos($extra, 'STORED GENERATED') !== false) {
                // Generated columns are recomputed on restore and cannot be inserted.
                continue;
            }
            $tables[$record->tablename]['columns'][] = [
                'name' => $record->name,
                'key' => strtolower($record->name),
                'kind' => self::column_kind($record->datatype, $record->tablename . '.' . $record->name),
            ];
        }
        $recordset->close();

        ksort($tables, SORT_STRING);
        // Capture definitions now: this is the schema of the snapshot, and it locks the tables against DDL.
        foreach (array_keys($tables) as $name) {
            $tables[$name]['create'] = $this->show_create($name);
        }
        return $tables;
    }

    /**
     * Whether a table belongs to this Moodle (prefix, and the optional test filter).
     *
     * @param string $name
     * @return bool
     */
    private function is_our_table(string $name): bool {
        if ($this->prefix === '' || strpos($name, $this->prefix) !== 0) {
            return $this->prefix === '' && $this->onlytables === null;
        }
        return $this->onlytables === null || in_array(substr($name, strlen($this->prefix)), $this->onlytables, true);
    }

    /**
     * SHOW CREATE TABLE output.
     *
     * @param string $name
     * @return string
     */
    private function show_create(string $name): string {
        try {
            $recordset = $this->db->get_recordset_sql('SHOW CREATE TABLE ' . self::quote_identifier($name));
            $create = null;
            foreach ($recordset as $record) {
                $values = array_values((array) $record);
                $create = $values[1] ?? null;
                break;
            }
            $recordset->close();
        } catch (\dml_exception $e) {
            throw new backup_exception('dbschemachanged', $name);
        }
        if (!is_string($create) || stripos($create, 'CREATE TABLE') !== 0) {
            throw new backup_exception('dbschemachanged', $name);
        }
        return $create;
    }

    /**
     * Fail if any table was created, dropped or altered while dumping.
     *
     * @param string[] $definitions Name => normalised definition captured by begin().
     * @return void
     */
    private function check_schema_unchanged(array $definitions): void {
        $current = [];
        $recordset = $this->db->get_recordset_sql('SELECT TABLE_NAME AS name, TABLE_TYPE AS tabletype
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($recordset as $record) {
            if ($record->tabletype === 'BASE TABLE' && $this->is_our_table($record->name)) {
                $current[] = $record->name;
            }
        }
        $recordset->close();
        sort($current, SORT_STRING);
        $dumped = array_keys($definitions);
        if ($current !== $dumped) {
            $changed = array_merge(array_diff($current, $dumped), array_diff($dumped, $current));
            throw new backup_exception('dbschemachanged', implode(', ', array_slice($changed, 0, 10)));
        }
        foreach ($definitions as $name => $definition) {
            if (self::normalise_definition($this->show_create($name)) !== $definition) {
                throw new backup_exception('dbschemachanged', $name);
            }
        }
    }

    /**
     * Definition without the AUTO_INCREMENT counter, which changes with every insert.
     *
     * @param string $create
     * @return string
     */
    private static function normalise_definition(string $create): string {
        return preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
    }

    /**
     * Encoding kind for an information_schema DATA_TYPE.
     *
     * @param string $datatype
     * @param string $column For the error message.
     * @return string
     */
    public static function column_kind(string $datatype, string $column = ''): string {
        $datatype = strtolower($datatype);
        if (!isset(self::KINDS[$datatype])) {
            throw new backup_exception('dbunsupportedtype', $column . ' (' . $datatype . ')');
        }
        return self::KINDS[$datatype];
    }

    /**
     * SQL literal for a value, without ever embedding the value as text.
     *
     * @param string|int|float|null $value As returned by the DML layer.
     * @param string $kind From column_kind().
     * @param string $charset Character set the server used for returned text.
     * @return string
     */
    public static function encode_value($value, string $kind, string $charset): string {
        if ($value === null) {
            return 'NULL';
        }
        $value = (string) $value;
        switch ($kind) {
            case 'int':
                if (preg_match('/^-?\d+$/', $value)) {
                    return $value;
                }
                break;
            case 'decimal':
                if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
                    return $value;
                }
                break;
            case 'float':
                if (preg_match('/^-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $value)) {
                    return $value;
                }
                break;
            case 'binary':
                return "X'" . bin2hex($value) . "'";
            case 'string':
            case 'temporal':
            case 'json':
                if (!preg_match('/^[a-z0-9_]{1,64}$/', $charset)) {
                    throw new backup_exception('dbcharset', $charset);
                }
                return '_' . $charset . " X'" . bin2hex($value) . "'";
        }
        throw new backup_exception('dbunexpectedvalue', $kind);
    }

    /**
     * Backtick-quote an identifier.
     *
     * @param string $name
     * @return string
     */
    public static function quote_identifier(string $name): string {
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- MySQL quotes identifiers with backticks.
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
