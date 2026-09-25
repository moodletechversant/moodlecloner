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
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_moodleclone\local\job\job;

/**
 * Tests for the privacy provider.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\privacy\provider
 */
class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Two users, each with a job.
     *
     * @return array [user1, user2]
     */
    private function setup_jobs(): array {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        foreach ([$user1, $user2] as $user) {
            $this->setUser($user);
            $job = new job(0, (object) ['userid' => $user->id, 'status' => job::STATUS_COMPLETED]);
            $job->create();
        }
        $this->setUser();
        return [$user1, $user2];
    }

    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('tool_moodleclone'));
        $items = $collection->get_collection();
        $this->assertCount(1, $items);
        $this->assertSame(job::TABLE, reset($items)->get_name());
        $this->assertArrayHasKey('userid', reset($items)->get_privacy_fields());
    }

    public function test_contexts_and_users(): void {
        [$user1] = $this->setup_jobs();
        $other = $this->getDataGenerator()->create_user();
        $system = \context_system::instance();

        $this->assertSame([$system->id], array_map('intval', provider::get_contexts_for_userid($user1->id)->get_contextids()));
        $this->assertSame([], provider::get_contexts_for_userid($other->id)->get_contextids());

        $userlist = new userlist($system, 'tool_moodleclone');
        provider::get_users_in_context($userlist);
        $this->assertCount(2, $userlist->get_userids());
    }

    public function test_export(): void {
        [$user1] = $this->setup_jobs();
        $system = \context_system::instance();

        provider::export_user_data(new approved_contextlist($user1, 'tool_moodleclone', [$system->id]));

        $data = writer::with_context($system)->get_data([get_string('pluginname', 'tool_moodleclone')]);
        $this->assertCount(1, $data->jobs);
        $this->assertSame(job::STATUS_COMPLETED, $data->jobs[0]->status);
    }

    public function test_delete_for_one_user_anonymises_only_theirs(): void {
        global $DB;
        [$user1, $user2] = $this->setup_jobs();
        $system = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist($user1, 'tool_moodleclone', [$system->id]));

        $this->assertFalse($DB->record_exists_select(job::TABLE, 'userid = ? OR usermodified = ?', [$user1->id, $user1->id]));
        $this->assertTrue($DB->record_exists(job::TABLE, ['userid' => $user2->id]));
        $this->assertSame(2, $DB->count_records(job::TABLE), 'Job records are kept for the audit trail');
    }

    public function test_delete_for_users_and_all(): void {
        global $DB;
        [$user1, $user2] = $this->setup_jobs();
        $system = \context_system::instance();

        provider::delete_data_for_users(new approved_userlist($system, 'tool_moodleclone', [$user2->id]));
        $this->assertFalse($DB->record_exists(job::TABLE, ['userid' => $user2->id]));
        $this->assertTrue($DB->record_exists(job::TABLE, ['userid' => $user1->id]));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertSame(0, $DB->count_records_select(job::TABLE, 'userid > 0 OR usermodified > 0'));
    }
}
