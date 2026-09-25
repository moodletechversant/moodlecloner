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

use tool_moodleclone\fixture_helper;
use tool_moodleclone\local\backup\progress\reporter;
use tool_moodleclone\local\backup\step\checksum_generator;
use tool_moodleclone\local\backup\step\manifest_generator;
use tool_moodleclone\local\backup\step\not_implemented_step;
use tool_moodleclone\local\backup\step\optional_step;
use tool_moodleclone\local\backup\step\package_writer;
use tool_moodleclone\local\backup\step\step;
use tool_moodleclone\local\environment\collector;
use tool_moodleclone\local\log\memory_logger;
use tool_moodleclone\local\log\redactor;
use tool_moodleclone\local\package\checksums;
use tool_moodleclone\local\package\installer_auth;
use tool_moodleclone\local\package\layout;
use tool_moodleclone\local\package\manifest;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/fixture_helper.php');

/**
 * Tests for the backup manager and the metadata steps.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\backup\manager
 * @covers     \tool_moodleclone\local\backup\step\manifest_generator
 * @covers     \tool_moodleclone\local\backup\step\checksum_generator
 */
class manager_test extends \advanced_testcase {

    /**
     * A step that records its execution.
     *
     * @param string $stage
     * @param array $calls Receives the stage name when executed.
     * @param bool $available
     * @param \Throwable|null $throw Thrown from execute().
     * @return step
     */
    private function fake_step(string $stage, array &$calls, bool $available = true, ?\Throwable $throw = null): step {
        return new class($stage, $calls, $available, $throw) implements step {
            /** @var string */
            private $stage;
            /** @var array */
            private $calls;
            /** @var bool */
            private $available;
            /** @var \Throwable|null */
            private $throw;

            /**
             * Constructor.
             *
             * @param string $stage
             * @param array $calls
             * @param bool $available
             * @param \Throwable|null $throw
             */
            public function __construct(string $stage, array &$calls, bool $available, ?\Throwable $throw) {
                $this->stage = $stage;
                $this->calls = &$calls;
                $this->available = $available;
                $this->throw = $throw;
            }

            public function get_stage(): string {
                return $this->stage;
            }

            public function is_available(): bool {
                return $this->available;
            }

            public function execute(backup_state $state): void {
                $this->calls[] = $this->stage;
                if ($this->throw) {
                    throw $this->throw;
                }
            }
        };
    }

    /**
     * A backup state using the real environment and an in-memory logger.
     *
     * @param redactor|null $redactor
     * @return backup_state
     */
    private function make_state(?redactor $redactor = null): backup_state {
        return new backup_state((new collector())->collect(), new options(),
            new memory_logger($redactor ?? new redactor()), 1790257501);
    }

    public function test_runs_steps_in_order_and_logs_progress(): void {
        $calls = [];
        $manager = new manager([
            $this->fake_step(stage::CODE, $calls),
            $this->fake_step(stage::DATABASE, $calls),
        ]);
        $state = $this->make_state();

        $manager->run($state);

        $this->assertSame([stage::CODE, stage::DATABASE], $calls);
        $this->assertSame([
            get_string('stage:starting', 'tool_moodleclone'),
            get_string('stage:code', 'tool_moodleclone'),
            get_string('stage:database', 'tool_moodleclone'),
            get_string('backupcomplete', 'tool_moodleclone'),
        ], $state->logger->get_messages());
    }

    public function test_refuses_to_run_when_a_step_is_unavailable(): void {
        $calls = [];
        $manager = new manager([
            $this->fake_step(stage::CODE, $calls),
            $this->fake_step(stage::DATABASE, $calls, false),
        ]);

        $this->assertFalse($manager->is_available());
        $this->assertSame([stage::DATABASE], $manager->get_unavailable_stages());
        try {
            $manager->run($this->make_state());
            $this->fail('Incomplete pipeline ran');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:backupnotavailable', $e->errorcode);
        }
        $this->assertSame([], $calls, 'No step may run when the pipeline is incomplete');
    }

    public function test_refuses_to_run_with_nothing_selected(): void {
        $calls = [];
        $manager = new manager([$this->fake_step(stage::CODE, $calls)]);
        $state = $this->make_state();
        $state->options->includecode = false;
        $state->options->includedataroot = false;
        $state->options->includedatabase = false;

        $this->expectException(\moodle_exception::class);
        $manager->run($state);
    }

    public function test_step_failure_is_logged_redacted_and_rethrown(): void {
        $calls = [];
        $failure = new \RuntimeException('Access denied using password dbpw-value');
        $manager = new manager([
            $this->fake_step(stage::DATABASE, $calls, true, $failure),
            $this->fake_step(stage::ARCHIVE, $calls),
        ]);
        $state = $this->make_state(new redactor(['dbpw-value']));

        try {
            $manager->run($state);
            $this->fail('Failure swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame([stage::DATABASE], $calls, 'Later steps do not run after a failure');
        $entries = $state->logger->get_entries();
        $last = end($entries);
        $this->assertSame('error', $last['level']);
        $this->assertStringContainsString('[redacted]', $last['message']);
        $this->assertStringNotContainsString('dbpw-value', $last['message']);
    }

    public function test_constructor_rejects_non_steps(): void {
        $this->expectException(\coding_exception::class);
        new manager([new \stdClass()]);
    }

    public function test_default_pipeline_order_and_availability(): void {
        $manager = manager::create_default();
        $stages = array_map(function(step $step) {
            return $step->get_stage();
        }, $manager->get_steps());

        // The database is dumped before moodledata so trashed content can be recovered.
        $this->assertSame([stage::CODE, stage::DATABASE, stage::DATAROOT, stage::MANIFEST, stage::CHECKSUMS,
            stage::ARCHIVE, stage::FINALISING], $stages);
        $this->assertSame([], $manager->get_unavailable_stages());
        $this->assertTrue($manager->is_available());
    }

    public function test_optional_steps_are_skipped_and_reported(): void {
        $calls = [];
        $optional = new class($calls) implements optional_step {
            /** @var array */
            private $calls;

            /**
             * Constructor.
             *
             * @param array $calls
             */
            public function __construct(array &$calls) {
                $this->calls = &$calls;
            }

            public function get_stage(): string {
                return stage::DATABASE;
            }

            public function is_available(): bool {
                return true;
            }

            public function is_included(options $options): bool {
                return $options->includedatabase;
            }

            public function execute(backup_state $state): void {
                $this->calls[] = 'database';
            }
        };
        $events = [];
        $state = $this->make_state();
        $state->options->includedatabase = false;
        $state->reporter = $this->recording_reporter($events);

        (new manager([$this->fake_step(stage::CODE, $calls), $optional]))->run($state);

        $this->assertSame([stage::CODE], $calls);
        $this->assertSame(['started:code:1/2', 'completed:code', 'skipped:database:2/2'], $events);
    }

    public function test_failure_is_reported_to_reporter(): void {
        $calls = [];
        $events = [];
        $state = $this->make_state();
        $state->reporter = $this->recording_reporter($events);
        $manager = new manager([$this->fake_step(stage::CODE, $calls, true, new backup_exception('unreadable', 'x/y'))]);

        try {
            $manager->run($state);
            $this->fail('Failure swallowed');
        } catch (backup_exception $e) {
            $this->assertSame(['started:code:1/1', 'failed:code'], $events);
        }
    }

    public function test_cancellation_stops_before_next_step(): void {
        $calls = [];
        $state = $this->make_state();
        $cancel = false;
        $state->cancelcheck = function() use (&$cancel) {
            return $cancel;
        };
        $first = $this->fake_step(stage::CODE, $calls);
        $manager = new manager([$first, $this->fake_step(stage::DATABASE, $calls)]);
        $state->logger = new \tool_moodleclone\local\log\memory_logger(new redactor());
        $cancel = true;

        $this->expectException(backup_cancelled_exception::class);
        try {
            $manager->run($state);
        } finally {
            $this->assertSame([], $calls, 'Cancellation is checked before each step starts');
        }
    }

    public function test_manifest_checksum_and_archive_steps(): void {
        $this->resetAfterTest();
        $dataroot = make_request_directory();
        $state = fixture_helper::make_state(make_request_directory(), $dataroot);
        $state->options->includedatabase = false;
        $state->options->includecode = false;
        $state->statistics['moodledata'] = ['files' => 0, 'directories' => 0, 'symlinks' => 0, 'bytes' => 0,
            'recovered_from_trash' => 0];

        (new manager([new manifest_generator(), new checksum_generator(), new package_writer()]))->run($state);

        $this->assertInstanceOf(manifest::class, $state->manifest);
        $this->assertSame(['moodle' => false, 'moodledata' => true, 'database' => false],
            $state->manifest->get('package_contents'));
        $this->assertContains('cache', $state->manifest->get('moodledata_excluded'));
        $this->assertTrue($state->zip->is_finished());

        $zip = new \ZipArchive();
        $zip->open($state->zip->get_path());
        $parsed = checksums::from_string($zip->getFromName(layout::CHECKSUMS));
        $this->assertSame([layout::MANIFEST], $parsed->get_paths());
        $this->assertSame(hash('sha256', $zip->getFromName(layout::MANIFEST)), $parsed->get(layout::MANIFEST));
        $zip->close();
    }

    /**
     * Run the manifest, checksum and archive steps over an (empty) moodledata with the given installer authorization.
     *
     * @param installer_auth|null $auth
     * @return \ZipArchive The finished package, opened for reading.
     */
    private function build_package_with(?installer_auth $auth): \ZipArchive {
        $state = fixture_helper::make_state(make_request_directory(), make_request_directory());
        $state->options->includedatabase = false;
        $state->options->includecode = false;
        $state->options->installerauth = $auth;
        $state->statistics['moodledata'] = ['files' => 0, 'directories' => 0, 'symlinks' => 0, 'bytes' => 0,
            'recovered_from_trash' => 0];
        (new manager([new manifest_generator(), new checksum_generator(), new package_writer()]))->run($state);
        $zip = new \ZipArchive();
        $zip->open($state->zip->get_path());
        return $zip;
    }

    public function test_the_package_carries_the_password_verifier_and_never_the_password(): void {
        $this->resetAfterTest();
        $password = 'correct horse battery staple';
        $zip = $this->build_package_with(installer_auth::from_password($password));

        $manifest = json_decode($zip->getFromName(layout::MANIFEST), true);
        $this->assertSame(3, $manifest['format']);
        $this->assertSame('password', $manifest['installer_auth']['mode']);
        $this->assertTrue(installer_auth::from_array($manifest['installer_auth'])->verify($password));

        // Not a single byte of the archive (every entry, decompressed) contains the password, in any common encoding.
        $needles = [$password, base64_encode($password), bin2hex($password), rawurlencode($password), json_encode($password)];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = (string) $zip->getFromIndex($i);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $content, $zip->getNameIndex($i));
            }
        }
        $zip->close();
    }

    public function test_the_key_file_is_the_default_installer_authorization(): void {
        $this->resetAfterTest();
        $zip = $this->build_package_with(null);
        $manifest = json_decode($zip->getFromName(layout::MANIFEST), true);
        $this->assertSame(['mode' => 'keyfile'], $manifest['installer_auth']);
        $zip->close();
    }

    public function test_checksum_step_requires_manifest(): void {
        $this->expectException(\coding_exception::class);
        (new checksum_generator())->execute($this->make_state());
    }

    public function test_not_implemented_step_refuses_to_execute(): void {
        $step = new class extends not_implemented_step {
            public function get_stage(): string {
                return stage::ARCHIVE;
            }
        };
        $this->assertFalse($step->is_available());
        $this->expectException(\coding_exception::class);
        $step->execute($this->make_state());
    }

    /**
     * Reporter recording events as strings.
     *
     * @param array $events
     * @return reporter
     */
    private function recording_reporter(array &$events): reporter {
        return new class($events) implements reporter {
            /** @var array */
            private $events;

            /**
             * Constructor.
             *
             * @param array $events
             */
            public function __construct(array &$events) {
                $this->events = &$events;
            }

            public function step_started(string $stage, int $index, int $total): void {
                $this->events[] = "started:{$stage}:{$index}/{$total}";
            }

            public function step_progress(string $stage, float $fraction): void {
            }

            public function step_completed(string $stage): void {
                $this->events[] = "completed:{$stage}";
            }

            public function step_skipped(string $stage, int $index, int $total): void {
                $this->events[] = "skipped:{$stage}:{$index}/{$total}";
            }

            public function step_failed(string $stage, string $error): void {
                $this->events[] = "failed:{$stage}";
            }
        };
    }
}
