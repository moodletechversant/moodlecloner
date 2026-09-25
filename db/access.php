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

/**
 * Capability definitions for Moodle Clone.
 *
 * A clone package contains the whole database (including password hashes and
 * personal data) and every stored file, so holding this capability is
 * equivalent to full control of the site. No archetype receives it by default:
 * only site administrators (who implicitly hold every capability) can use the
 * tool unless it is explicitly granted.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'tool/moodleclone:manage' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
];
