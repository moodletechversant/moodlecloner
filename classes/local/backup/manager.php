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

namespace tool_moodleclone\local\backup;

use tool_moodleclone\local\backup\step\checksum_generator;
use tool_moodleclone\local\backup\step\code_collector;
use tool_moodleclone\local\backup\step\database_dumper;
use tool_moodleclone\local\backup\step\dataroot_collector;
use tool_moodleclone\local\backup\step\finaliser;
use tool_moodleclone\local\backup\step\manifest_generator;
use tool_moodleclone\local\backup\step\optional_step;
use tool_moodleclone\local\backup\step\package_writer;
use tool_moodleclone\local\backup\step\step;
use tool_moodleclone\local\log\logger;

/**
 * Runs the backup steps in order.
 *
 * The manager knows nothing about how each part is produced; it enforces
 * ordering, availability, preconditions and progress reporting, and stops at
 * the first failing step. It is driven by local\job\runner from the CLI or
 * the scheduled task, never inside a web request, because a full-site
 * backup can take hours.
 *
 * @package    tool_moodleclone
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /** @var step[] */
    private $steps;

    /**
     * Constructor.
     *
     * @param step[] $steps In execution order.
     */
    public function __construct(array $steps) {
        foreach ($steps as $step) {
            if (!$step instanceof step) {
                throw new \coding_exception('Backup steps must implement ' . step::class);
            }
        }
        $this->steps = array_values($steps);
    }

    /**
     * The manager with the standard pipeline.
     *
     * @return self
     */
    public static function create_default(): self {
        return new self([
            new code_collector(),
            new database_dumper(),
            new dataroot_collector(),
            new manifest_generator(),
            new checksum_generator(),
            new package_writer(),
            new finaliser(),
        ]);
    }

    /**
     * The configured steps.
     *
     * @return step[]
     */
    public function get_steps(): array {
        return $this->steps;
    }

    /**
     * Stages whose step is not implemented yet.
     *
     * @return string[]
     */
    public function get_unavailable_stages(): array {
        $stages = [];
        foreach ($this->steps as $step) {
            if (!$step->is_available()) {
                $stages[] = $step->get_stage();
            }
        }
        return $stages;
    }

    /**
     * Whether a complete backup can be run.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->get_unavailable_stages() === [];
    }

    /**
     * Run every step.
     *
     * @param backup_state $state
     * @return void
     * @throws \moodle_exception When the pipeline is incomplete or options select nothing.
     */
    public function run(backup_state $state): void {
        if (!$this->is_available()) {
            throw new \moodle_exception('error:backupnotavailable', 'tool_moodleclone', '',
                implode(', ', $this->get_unavailable_stages()));
        }
        if ($state->options->is_empty()) {
            throw new \moodle_exception('error:nothingselected', 'tool_moodleclone');
        }

        $state->logger->log(logger::INFO, stage::get_label(stage::STARTING));
        $total = count($this->steps);
        foreach ($this->steps as $index => $step) {
            $stage = $step->get_stage();
            if ($step instanceof optional_step && !$step->is_included($state->options)) {
                $state->reporter->step_skipped($stage, $index + 1, $total);
                continue;
            }
            $state->logger->log(logger::INFO, stage::get_label($stage));
            $state->currentstage = $stage;
            $state->reporter->step_started($stage, $index + 1, $total);
            try {
                // Checked before (not after) each step, so a published package is never withdrawn.
                $state->check_cancelled(true);
                $step->execute($state);
            } catch (\Throwable $e) {
                // Reporters and the logger redact, so the message is safe to record.
                $message = backup_exception::describe($e);
                $state->reporter->step_failed($stage, $message);
                $state->logger->log(logger::ERROR, get_string('stagefailed', 'tool_moodleclone',
                    ['stage' => $stage, 'message' => $message]));
                throw $e;
            }
            $state->reporter->step_completed($stage);
        }
        $state->currentstage = null;
        $state->logger->log(logger::INFO, get_string('backupcomplete', 'tool_moodleclone'));
    }
}
