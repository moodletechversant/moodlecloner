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

namespace tool_moodleclone\local\backup\step;

use tool_moodleclone\local\backup\backup_state;
use tool_moodleclone\local\backup\options;
use tool_moodleclone\local\backup\stage;
use tool_moodleclone\local\database\connection_factory;
use tool_moodleclone\local\database\gzip_sink;
use tool_moodleclone\local\database\mysql_dumper;
use tool_moodleclone\local\package\layout;
use tool_moodleclone\local\package\manifest;

/**
 * Streams database.sql.gz straight into the archive.
 *
 * Opens a dedicated connection (connection_factory), takes one consistent
 * InnoDB snapshot (mysql_dumper) and gzips the SQL on the fly, so no
 * uncompressed or temporary copy of the database touches the disk. MySQL
 * only in this release.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class database_dumper implements optional_step {
    /**
     * The stage this step performs.
     *
     * @return string
     */
    public function get_stage(): string {
        return stage::DATABASE;
    }

    /**
     * Implemented.
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Included when the database is selected.
     *
     * @param options $options
     * @return bool
     */
    public function is_included(options $options): bool {
        return (bool) $options->includedatabase;
    }

    /**
     * Dump.
     *
     * @param backup_state $state
     * @return void
     */
    public function execute(backup_state $state): void {
        $connector = $state->dbconnector ?? [connection_factory::class, 'open'];
        $db = $connector();
        $dumper = null;
        try {
            $dumper = new mysql_dumper($db, $state->dumptables);
            $dumper->begin();
            $state->snapshottime = $dumper->get_snapshot_time();

            $stream = $state->zip->open_stream(layout::DATABASE, false, 0600, $state->snapshottime);
            $sink = new gzip_sink([$stream, 'write']);
            $info = $dumper->dump($sink, function (float $fraction) use ($state) {
                $state->progress($fraction);
            });
            $sink->close();
            $result = $stream->close();
            $dumper->end();

            foreach ($info['views'] as $view) {
                // Views are not table data; they are reported rather than silently dropped.
                $state->warn('view', $view);
            }
            $state->checksums->add(layout::DATABASE, $result['sha256']);
            $state->statistics[manifest::CONTENT_DATABASE] = ['tables' => $info['tables'], 'rows' => $info['rows']];
            $state->databasedump = [
                'format' => mysql_dumper::FORMAT,
                'format_version' => mysql_dumper::FORMAT_VERSION,
                'compression' => 'gzip',
                'charset' => $info['charset'],
                'collation' => $info['collation'],
                'consistency' => 'snapshot',
                'max_statement_bytes' => $info['max_statement_bytes'],
            ];
        } finally {
            if ($dumper !== null) {
                $dumper->end_quietly();
            }
            $db->dispose();
        }
    }
}
