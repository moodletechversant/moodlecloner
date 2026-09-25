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

namespace tool_moodleclone\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_moodleclone\local\job\job;

/**
 * Privacy provider for Moodle Clone.
 *
 * The job table records which administrator requested a backup. Deleting a
 * user's data anonymises those records (userid/usermodified set to 0) so the
 * audit trail of backups stays intact.
 *
 * Clone packages themselves contain a full copy of the site database and
 * files, including every user's data. They are files on disk, not personal
 * data records of this plugin, and cannot be edited after creation; the site
 * administrator must delete old packages as part of the site's retention
 * policy (see README.md).
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(job::TABLE, [
            'userid' => 'privacy:metadata:jobs:userid',
            'status' => 'privacy:metadata:jobs:status',
            'timecreated' => 'privacy:metadata:jobs:timecreated',
            'usermodified' => 'privacy:metadata:jobs:usermodified',
        ], 'privacy:metadata:jobs');
        return $collection;
    }

    /**
     * Contexts with data for a user: the system context when they requested a job.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_jobs($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . job::TABLE . '} WHERE userid > 0', []);
        $userlist->add_from_sql('usermodified', 'SELECT usermodified FROM {' . job::TABLE . '} WHERE usermodified > 0', []);
    }

    /**
     * Export the jobs a user requested.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            $records = $DB->get_records_select(
                job::TABLE,
                'userid = :userid OR usermodified = :usermodified',
                ['userid' => $userid, 'usermodified' => $userid],
                'id ASC',
                'id, status, origin, timecreated, timefinished'
            );
            if (!$records) {
                continue;
            }
            $jobs = [];
            foreach ($records as $record) {
                $jobs[] = (object) [
                    'status' => $record->status,
                    'origin' => $record->origin,
                    'timecreated' => transform::datetime($record->timecreated),
                    'timefinished' => $record->timefinished ? transform::datetime($record->timefinished) : null,
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'tool_moodleclone')],
                (object) ['jobs' => $jobs]
            );
        }
    }

    /**
     * Anonymise all job records.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->set_field_select(job::TABLE, 'userid', 0, 'userid > 0');
        $DB->set_field_select(job::TABLE, 'usermodified', 0, 'usermodified > 0');
    }

    /**
     * Anonymise one user's job records.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::anonymise([(int) $contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Anonymise several users' job records.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context() instanceof \context_system) {
            self::anonymise(array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * Replace user ids with 0.
     *
     * @param int[] $userids
     * @return void
     */
    private static function anonymise(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select(job::TABLE, 'userid', 0, "userid {$insql}", $params);
        $DB->set_field_select(job::TABLE, 'usermodified', 0, "usermodified {$insql}", $params);
    }

    /**
     * Whether a user appears in any job.
     *
     * @param int $userid
     * @return bool
     */
    private static function user_has_jobs(int $userid): bool {
        global $DB;
        return $DB->record_exists_select(
            job::TABLE,
            'userid = :userid OR usermodified = :usermodified',
            ['userid' => $userid, 'usermodified' => $userid]
        );
    }
}
