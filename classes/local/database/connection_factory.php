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
 * Opens the dedicated database connection used for the dump.
 *
 * A second connection is needed because the dump runs inside one long
 * read-only snapshot transaction, while the job's progress must keep being
 * written (and committed) through $DB. It connects with the same driver and
 * settings as $DB, except that the read-only replica option is removed:
 * reads routed to a lagging replica would break snapshot consistency.
 *
 * This is the only place the plugin reads $CFG->dbpass, and only to hand it
 * to the DML driver. It is never stored, logged or written anywhere.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_factory {
    /**
     * Connect.
     *
     * @return \moodle_database
     * @throws backup_exception
     */
    public static function open(): \moodle_database {
        global $CFG;
        $db = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        if (!$db) {
            throw new backup_exception('dbdriver', (string) $CFG->dbtype);
        }
        $options = isset($CFG->dboptions) ? (array) $CFG->dboptions : [];
        unset($options['readonly']);
        try {
            $db->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $options);
        } catch (\dml_connection_exception $e) {
            // The driver's detail (e.g. "Access denied for user ...") helps diagnosis;
            // callers pass every message through the redactor before storing it.
            throw new backup_exception('dbconnect', null, $e->debuginfo ?? null);
        }
        return $db;
    }
}
