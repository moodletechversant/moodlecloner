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
 * Base for Moodle Clone job events.
 *
 * Event data holds the job id and, where useful, sizes, the package hash or
 * the failing stage. Never error text, paths or anything from config.php.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class job_event extends \core\event\base {
    /**
     * Create the event for a job.
     *
     * @param job $job
     * @param array $other
     * @param int|null $userid Acting user; defaults to the job's requester.
     * @return static
     */
    public static function create_for_job(job $job, array $other = [], ?int $userid = null) {
        $data = [
            'context' => \context_system::instance(),
            'objectid' => $job->get('id'),
            'other' => $other,
        ];
        $userid = $userid ?? (int) $job->get('userid');
        if ($userid > 0) {
            $data['userid'] = $userid;
        }
        return static::create($data);
    }

    /**
     * Common initialisation.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = job::TABLE;
    }

    /**
     * Link to the admin page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/moodleclone/index.php');
    }

    /**
     * Jobs are not restored from course backups.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => job::TABLE, 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * No ids in "other".
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
