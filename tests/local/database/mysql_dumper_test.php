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
 * Tests for the MySQL dumper, against the real PHPUnit database.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\database\mysql_dumper
 * @covers     \tool_moodleclone\local\database\connection_factory
 * @covers     \tool_moodleclone\local\database\gzip_sink
 */
class mysql_dumper_test extends \advanced_testcase {

    /** @var string Test table (without prefix). */
    private const TABLE = 'tool_moodleclone_dumptest';

    /**
     * Skip unless MySQL; create the test table.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('The dumper supports MySQL only');
        }
        $this->resetAfterTest();
        $table = new \xmldb_table(self::TABLE);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null);
        $table->add_field('body', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('data', XMLDB_TYPE_BINARY, null, null, null);
        $table->add_field('amount', XMLDB_TYPE_NUMBER, '15', null, null, null, null, 5);
        $table->add_field('ratio', XMLDB_TYPE_FLOAT, '20', null, null, null, null, 10);
        $table->add_field('counter', XMLDB_TYPE_INTEGER, '10', null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('name', XMLDB_INDEX_NOTUNIQUE, ['name']);
        $dbman = $DB->get_manager();
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $dbman->create_table($table);
    }

    /**
     * Drop the test table.
     *
     * @return void
     */
    protected function tearDown(): void {
        global $DB;
        $table = new \xmldb_table(self::TABLE);
        if ($DB->get_dbfamily() === 'mysql' && $DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->drop_table($table);
        }
        parent::tearDown();
    }

    /**
     * Tricky rows.
     *
     * @return array
     */
    private function tricky_rows(): array {
        return [
            ['name' => "O'Brien \"quoted\" \\ backslash", 'body' => "line1\nline2;\r\n-- not a comment\x1a",
                'data' => random_bytes(3000) . "\0\xff", 'amount' => '-12345.67891', 'ratio' => 0.125, 'counter' => 0],
            ['name' => 'emoji 😀 ünïcode', 'body' => "'); DROP TABLE mdl_user; --", 'data' => '',
                'amount' => '0.00000', 'ratio' => -1.5, 'counter' => 2147483647],
            ['name' => null, 'body' => null, 'data' => null, 'amount' => null, 'ratio' => null, 'counter' => null],
        ];
    }

    /**
     * Sink collecting output in memory.
     *
     * @return sink
     */
    private function memory_sink(): sink {
        return new class implements sink {
            /** @var string */
            public $data = '';

            public function write(string $data): void {
                $this->data .= $data;
            }

            public function close(): void {
            }
        };
    }

    /**
     * Dump the given tables on a fresh dump connection.
     *
     * @param string[] $tables
     * @param callable|null $between Called after begin(), before dump().
     * @return array [sql, info]
     */
    private function dump(array $tables, ?callable $between = null): array {
        $db = connection_factory::open();
        try {
            $dumper = new mysql_dumper($db, $tables);
            $dumper->begin();
            if ($between !== null) {
                $between();
            }
            $sink = $this->memory_sink();
            $info = $dumper->dump($sink);
            $dumper->end();
            return [$sink->data, $info];
        } finally {
            $db->dispose();
        }
    }

    /**
     * Execute the table statements of a dump (SET statements are skipped to keep the test session clean).
     *
     * @param string $sql
     * @return void
     */
    private function restore(string $sql): void {
        global $DB;
        foreach (explode(";\n", $sql) as $statement) {
            $lines = array_filter(explode("\n", $statement), function($line) {
                return strpos($line, '--') !== 0 && trim($line) !== '';
            });
            $statement = trim(implode("\n", $lines));
            if ($statement === '' || stripos($statement, 'SET ') === 0) {
                continue;
            }
            $DB->execute($statement);
        }
    }

    public function test_dump_restores_identical_data(): void {
        global $DB;
        $ids = [];
        foreach ($this->tricky_rows() as $row) {
            $ids[] = $DB->insert_record(self::TABLE, (object) $row);
        }
        $before = $DB->get_records(self::TABLE, null, 'id');

        [$sql, $info] = $this->dump([self::TABLE]);

        $this->assertSame(1, $info['tables']);
        $this->assertSame(3, $info['rows']);
        $this->assertStringContainsString('CREATE TABLE `' . $DB->get_prefix() . self::TABLE . '`', $sql);
        $this->assertStringContainsString(mysql_dumper::COMPLETION_MARKER, $sql);
        $this->assertStringNotContainsString("O'Brien", $sql, 'Values never appear as text');
        $this->assertStringNotContainsString('DROP TABLE mdl_user', $sql);

        $DB->delete_records(self::TABLE);
        $this->restore($sql);
        $DB->reset_caches();

        $after = $DB->get_records(self::TABLE, null, 'id');
        $this->assertEquals($before, $after);
        $this->assertSame($before[$ids[0]]->data, $after[$ids[0]]->data, 'Binary data is byte-identical');
        $this->assertTrue($DB->get_manager()->index_exists(new \xmldb_table(self::TABLE),
            new \xmldb_index('name', XMLDB_INDEX_NOTUNIQUE, ['name'])), 'Indexes are restored');
    }

    public function test_snapshot_excludes_rows_written_after_it_started(): void {
        global $DB;
        $DB->insert_record(self::TABLE, (object) ['name' => 'before snapshot']);

        [$sql] = $this->dump([self::TABLE], function() use ($DB) {
            // Committed by the main connection while the dump transaction is open.
            $DB->insert_record(self::TABLE, (object) ['name' => 'after snapshot']);
        });

        $this->assertStringContainsString(bin2hex('before snapshot'), $sql);
        $this->assertStringNotContainsString(bin2hex('after snapshot'), $sql);
    }

    public function test_non_innodb_table_is_refused(): void {
        global $DB;
        $DB->execute('ALTER TABLE {' . self::TABLE . '} ENGINE=MyISAM');
        try {
            $this->dump([self::TABLE]);
            $this->fail('Non-transactional table accepted');
        } catch (backup_exception $e) {
            $this->assertSame('dbnontransactional', $e->reason);
            $this->assertStringContainsString('MyISAM', $e->a);
        }
    }

    public function test_schema_change_cannot_slip_into_a_dump(): void {
        global $DB;
        $DB->insert_record(self::TABLE, (object) ['name' => 'x']);
        $altered = false;
        $DB->execute('SET SESSION lock_wait_timeout = 2');
        try {
            $this->dump([self::TABLE], function() use ($DB, &$altered) {
                try {
                    $DB->execute('ALTER TABLE {' . self::TABLE . '} ADD COLUMN extra INT NULL');
                    $altered = true;
                } catch (\dml_exception $e) {
                    // Expected: the dump's metadata locks make the DDL wait (and time out here).
                    $altered = false;
                }
            });
            $this->assertFalse($altered, 'DDL ran during the dump and the dump still succeeded');
        } catch (backup_exception $e) {
            // Also safe: the change was detected and the dump refused.
            $this->assertContains($e->reason, ['dbsnapshot', 'dbschemachanged']);
        } finally {
            $DB->execute('SET SESSION lock_wait_timeout = DEFAULT');
            $DB->reset_caches();
        }
    }

    public function test_table_created_during_dump_fails_it(): void {
        global $DB;
        $extra = new \xmldb_table(self::TABLE . '2');
        $extra->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $extra->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        try {
            $this->dump([self::TABLE, self::TABLE . '2'], function() use ($DB, $extra) {
                $DB->get_manager()->create_table($extra);
            });
            $this->fail('New table not detected');
        } catch (backup_exception $e) {
            $this->assertSame('dbschemachanged', $e->reason);
        } finally {
            if ($DB->get_manager()->table_exists($extra)) {
                $DB->get_manager()->drop_table($extra);
            }
        }
    }

    public function test_session_rows_are_not_dumped(): void {
        global $DB;
        $DB->insert_record('sessions', (object) ['state' => 0, 'sid' => 'secret-session-id', 'userid' => 2,
            'timecreated' => time(), 'timemodified' => time(), 'firstip' => '127.0.0.1', 'lastip' => '127.0.0.1']);

        [$sql, $info] = $this->dump(['sessions']);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('INSERT INTO', $sql);
        $this->assertStringNotContainsString(bin2hex('secret-session-id'), $sql);
        $this->assertSame(0, $info['rows']);
    }

    public function test_gzip_sink_output_decompresses(): void {
        $compressed = '';
        $sink = new gzip_sink(function(string $data) use (&$compressed) {
            $compressed .= $data;
        });
        $sink->write(str_repeat('INSERT INTO x VALUES (1);' . "\n", 100000));
        $sink->close();
        $this->assertSame(str_repeat('INSERT INTO x VALUES (1);' . "\n", 100000), gzdecode($compressed));
    }

    /**
     * Values and their literals.
     *
     * @return array
     */
    public function encode_provider(): array {
        return [
            'null' => [null, 'string', 'NULL'],
            'int' => ['-42', 'int', '-42'],
            'decimal' => ['3.14000', 'decimal', '3.14000'],
            'float exponent' => ['1.5e-7', 'float', '1.5e-7'],
            'string' => ["a'b", 'string', "_utf8mb4 X'612762'"],
            'empty string' => ['', 'string', "_utf8mb4 X''"],
            'binary' => ["\0\xff", 'binary', "X'00ff'"],
            'date' => ['2026-09-24', 'temporal', "_utf8mb4 X'323032362d30392d3234'"],
        ];
    }

    /**
     * Literal encoding.
     *
     * @dataProvider encode_provider
     * @param string|null $value
     * @param string $kind
     * @param string $expected
     */
    public function test_encode_value($value, string $kind, string $expected): void {
        $this->assertSame($expected, mysql_dumper::encode_value($value, $kind, 'utf8mb4'));
    }

    /**
     * Numeric values that are not numbers.
     *
     * @return array
     */
    public function bad_number_provider(): array {
        return [['1; DROP TABLE x', 'int'], ['1 OR 1=1', 'int'], ['0x41', 'int'], ['1e5', 'decimal'], ['NaN', 'float']];
    }

    /**
     * Injection through numeric columns is impossible.
     *
     * @dataProvider bad_number_provider
     * @param string $value
     * @param string $kind
     */
    public function test_encode_rejects_non_numeric_numbers(string $value, string $kind): void {
        $this->expectException(backup_exception::class);
        mysql_dumper::encode_value($value, $kind, 'utf8mb4');
    }

    public function test_column_kind_and_identifiers(): void {
        $this->assertSame('binary', mysql_dumper::column_kind('LONGBLOB'));
        $this->assertSame('`we``ird`', mysql_dumper::quote_identifier('we`ird'));
        $this->expectException(backup_exception::class);
        mysql_dumper::column_kind('vector');
    }
}
