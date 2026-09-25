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

namespace tool_moodleclone\event;

use tool_moodleclone\local\job\job;

/**
 * Tests for the audit events.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\event\job_event
 * @covers     \tool_moodleclone\event\backup_started
 * @covers     \tool_moodleclone\event\backup_completed
 * @covers     \tool_moodleclone\event\backup_failed
 * @covers     \tool_moodleclone\event\package_downloaded
 * @covers     \tool_moodleclone\event\package_deleted
 */
class events_test extends \advanced_testcase {
    /**
     * Event classes with their "other" data.
     *
     * @return array
     */
    public static function event_provider(): array {
        return [
            'started' => [backup_started::class, [], 'c'],
            'completed' => [backup_completed::class, ['size' => 123, 'sha256' => str_repeat('a', 64)], 'c'],
            'failed' => [backup_failed::class, ['status' => 'failed', 'stage' => 'database'], 'u'],
            'downloaded' => [package_downloaded::class, ['size' => 123], 'r'],
            'deleted' => [package_deleted::class, [], 'd'],
        ];
    }

    /**
     * Events trigger with the job id, the right user and no secrets.
     *
     * @dataProvider event_provider
     * @param string $class
     * @param array $other
     * @param string $crud
     */
    public function test_event(string $class, array $other, string $crud): void {
        global $CFG;
        $this->resetAfterTest();
        $requester = $this->getDataGenerator()->create_user();
        $job = new job(0, (object) ['userid' => $requester->id]);
        $job->create();
        $sink = $this->redirectEvents();

        $class::create_for_job($job, $other)->trigger();

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf($class, $event);
        $this->assertSame((int) $job->get('id'), (int) $event->objectid);
        $this->assertSame((int) $requester->id, (int) $event->userid);
        $this->assertSame($crud, $event->crud);
        $this->assertEquals(\context_system::instance(), $event->get_context());
        $this->assertSame($other, $event->other);
        $this->assertNotEmpty($event->get_description());
        $this->assertInstanceOf(\moodle_url::class, $event->get_url());
        $serialised = json_encode($event->get_data());
        if (!empty($CFG->dbpass)) {
            $this->assertStringNotContainsString($CFG->dbpass, $serialised);
        }
        $this->assertStringNotContainsString($CFG->dataroot, $serialised, 'No filesystem paths in events');
    }

    public function test_acting_user_can_differ_from_requester(): void {
        $this->resetAfterTest();
        $job = new job(0, (object) ['userid' => 2]);
        $job->create();
        $downloader = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();

        package_downloaded::create_for_job($job, ['size' => 1], (int) $downloader->id)->trigger();

        $this->assertSame((int) $downloader->id, (int) $sink->get_events()[0]->userid);
    }
}
