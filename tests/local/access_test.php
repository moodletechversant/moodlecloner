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

namespace tool_moodleclone\local;

/**
 * Tests for Moodle Clone access control.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\access
 */
class access_test extends \advanced_testcase {
    public function test_site_admin_can_manage(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertTrue(access::can_manage());
        access::require_manage();
    }

    public function test_regular_user_cannot_manage(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertFalse(access::can_manage());
        $this->expectException(\required_capability_exception::class);
        access::require_manage();
    }

    public function test_capability_is_not_granted_to_any_archetype(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $managerrole = get_archetype_roles('manager');
        $this->getDataGenerator()->role_assign(reset($managerrole)->id, $user->id, \context_system::instance()->id);

        $this->assertFalse(access::can_manage($user), 'The manager archetype must not get this capability by default');
    }

    public function test_capability_can_be_granted_explicitly(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability(access::CAPABILITY, CAP_ALLOW, $roleid, $context->id);
        $this->getDataGenerator()->role_assign($roleid, $user->id, $context->id);

        $this->assertTrue(access::can_manage($user));
    }

    public function test_capability_is_registered_with_risks(): void {
        $info = get_capability_info(access::CAPABILITY);
        $this->assertNotEmpty($info);
        $this->assertSame(CONTEXT_SYSTEM, (int) $info->contextlevel);
        $this->assertNotEquals(0, $info->riskbitmask & RISK_CONFIG);
        $this->assertNotEquals(0, $info->riskbitmask & RISK_PERSONAL);
    }
}
