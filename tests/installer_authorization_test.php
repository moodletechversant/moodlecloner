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

namespace tool_moodleclone;

use MoodleCloneInstaller\auth_store;
use MoodleCloneInstaller\auth_verifier;
use tool_moodleclone\local\package\installer_auth;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/installer_fixture.php');

/**
 * Tests for how the standalone installer authorizes its user (Phase 3.1): the package's offline verifier, the
 * server-side attempt limiter and the session registry.
 *
 * The browser-level flow (real login form, real sessions, a full restore) is exercised by tests/e2e/installer_e2e.py.
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \MoodleCloneInstaller\auth_verifier
 * @covers     \MoodleCloneInstaller\auth_store
 */
class installer_authorization_test extends \basic_testcase {

    /** @var string */
    private const PASSWORD = 'correct horse battery staple';

    /** @var string */
    private $dir;

    /** @var int Fake time. */
    private $now = 1790000000;

    protected function setUp(): void {
        installer_fixture::load();
        $this->dir = installer_fixture::make_dir();
        $this->now = 1790000000;
    }

    /**
     * A limiter with a controllable clock.
     *
     * @return auth_store
     */
    private function store(): auth_store {
        return new auth_store($this->dir . '/auth.php', function() {
            return $this->now;
        });
    }

    /**
     * Write a package protected the given way and return its path.
     *
     * @param array $options installer_fixture::build_package() options.
     * @return string
     */
    private function package(array $options = []): string {
        $path = $this->dir . '/moodle-clone-2026-09-24-113503.zip';
        installer_fixture::build_package($path, $options);
        return $path;
    }

    // 1. A correct password succeeds; 2. an incorrect one fails.

    public function test_the_correct_installer_password_succeeds_and_incorrect_ones_fail(): void {
        $auth = installer_auth::from_password(self::PASSWORD)->to_array();
        $found = auth_verifier::from_package($this->package(['installer_auth' => $auth]));

        $this->assertSame($auth, $found, 'the installer reads the verifier straight from the package');
        $this->assertTrue(auth_verifier::verify_password($found, self::PASSWORD));
        foreach (['', 'wrong', self::PASSWORD . ' ', ' ' . self::PASSWORD, strtoupper(self::PASSWORD)] as $wrong) {
            $this->assertFalse(auth_verifier::verify_password($found, $wrong), "'{$wrong}'");
        }
    }

    public function test_the_installer_and_the_plugin_agree_on_the_algorithm(): void {
        // The installer is standalone, so it carries its own copy of the verification; it must accept exactly what the plugin makes.
        $plugin = installer_auth::from_password(self::PASSWORD);
        $this->assertTrue(auth_verifier::verify_password($plugin->to_array(), self::PASSWORD));
        $this->assertSame([], auth_verifier::validate($plugin->to_array()));
        $this->assertSame([], auth_verifier::validate(['mode' => 'keyfile']));
    }

    public function test_a_key_file_package_never_verifies_a_password(): void {
        $found = auth_verifier::from_package($this->package(['installer_auth' => ['mode' => 'keyfile']]));
        $this->assertSame(['mode' => 'keyfile'], $found);
        $this->assertFalse(auth_verifier::verify_password($found, ''));
        $this->assertFalse(auth_verifier::verify_password($found, self::PASSWORD));
    }

    public function test_packages_made_before_installer_passwords_use_the_key_file(): void {
        $this->assertSame(['mode' => 'keyfile'], auth_verifier::from_package($this->package(['format' => 2])));
    }

    /**
     * @return array
     */
    public function unusable_package_provider(): array {
        $good = installer_auth::from_password(self::PASSWORD)->to_array();
        return [
            'installer_auth missing in format 3' => [['manifest' => function(array $m) {
                unset($m['installer_auth']);
                return $m;
            }]],
            'unknown mode' => [['installer_auth' => ['mode' => 'none']]],
            'password mode without a verifier' => [['installer_auth' => ['mode' => 'password']]],
            'iterations that would stall the server' => [['installer_auth' => ['iterations' => 2000000000] + $good]],
            'iterations too weak' => [['installer_auth' => ['iterations' => 1] + $good]],
            'other kdf' => [['installer_auth' => ['kdf' => 'md5'] + $good]],
            'extra key' => [['installer_auth' => $good + ['note' => 'x']]],
            'unknown format' => [['manifest' => function(array $m) {
                $m['format'] = 9;
                return $m;
            }]],
            'not a moodle clone package' => [['manifest' => function(array $m) {
                $m['product'] = 'other';
                return $m;
            }]],
            'truncated upload' => [['truncate' => 250]],
        ];
    }

    /**
     * Anything unexpected must refuse to authorize (fail closed), never fall back to a weaker way in.
     *
     * @dataProvider unusable_package_provider
     * @param array $options
     */
    public function test_an_unusable_package_authorizes_nobody(array $options): void {
        $this->assertNull(auth_verifier::from_package($this->package($options)));
    }

    public function test_a_missing_or_garbage_file_authorizes_nobody(): void {
        $this->assertNull(auth_verifier::from_package($this->dir . '/nothing.zip'));
        file_put_contents($this->dir . '/garbage.zip', 'not a zip');
        $this->assertNull(auth_verifier::from_package($this->dir . '/garbage.zip'));
    }

    // 3. Repeated incorrect attempts are rate-limited.

    public function test_failed_attempts_are_limited_with_growing_back_off(): void {
        $store = $this->store();
        for ($i = 1; $i <= auth_store::FREE_FAILURES; $i++) {
            $attempt = $store->begin_attempt('client-a');
            $this->assertTrue($attempt['allowed'], "attempt {$i} is free");
        }
        // The next attempt is still allowed (it is the one that reaches the limit) and starts the first lock-out.
        $this->assertTrue($store->begin_attempt('client-a')['allowed']);
        $refused = $store->begin_attempt('client-a');
        $this->assertFalse($refused['allowed']);
        $this->assertSame(auth_store::BASE_LOCK, $refused['retry']);

        $this->now += auth_store::BASE_LOCK - 1;
        $this->assertFalse($store->begin_attempt('client-a')['allowed'], 'still locked one second early');
        $this->now += 1;
        $this->assertTrue($store->begin_attempt('client-a')['allowed'], 'allowed again when the lock-out ends');
        $this->assertSame(2 * auth_store::BASE_LOCK, $store->begin_attempt('client-a')['retry'], 'the next lock-out doubles');

        // It is capped.
        for ($i = 0; $i < 12; $i++) {
            $this->now += auth_store::MAX_LOCK;
            $store->begin_attempt('client-a');
        }
        $this->assertSame(auth_store::MAX_LOCK, $store->begin_attempt('client-a')['retry']);
    }

    public function test_a_locked_client_is_refused_even_when_it_would_be_right(): void {
        // The refusal is decided before any password is looked at, so the lock cannot be used as an oracle.
        $store = $this->store();
        for ($i = 0; $i <= auth_store::FREE_FAILURES; $i++) {
            $store->begin_attempt('client-a');
        }
        $attempt = $store->begin_attempt('client-a');
        $this->assertFalse($attempt['allowed']);
        $this->assertSame('', $attempt['ticket'], 'nothing to check and nothing to give back');
    }

    public function test_one_clients_failures_do_not_lock_out_another(): void {
        $store = $this->store();
        for ($i = 0; $i < 10; $i++) {
            $store->begin_attempt('attacker');
        }
        $this->assertFalse($store->begin_attempt('attacker')['allowed']);
        $this->assertTrue($store->begin_attempt('administrator')['allowed']);
    }

    public function test_attempts_are_counted_before_they_are_checked(): void {
        // Parallel guesses cannot all pass the check while the counter still reads zero: each reservation counts at once.
        $store = $this->store();
        $reserved = 0;
        for ($i = 0; $i < 20; $i++) {
            if ($store->begin_attempt('client-a')['allowed']) {
                $reserved++;
            }
        }
        $this->assertSame(auth_store::FREE_FAILURES + 1, $reserved);
    }

    public function test_a_correct_password_clears_the_clients_failures(): void {
        $store = $this->store();
        for ($i = 0; $i < auth_store::FREE_FAILURES; $i++) {
            $store->begin_attempt('client-a');
        }
        $attempt = $store->begin_attempt('client-a');
        $this->assertTrue($attempt['allowed']);
        $store->succeeded('client-a', $attempt['ticket']);
        for ($i = 0; $i < auth_store::FREE_FAILURES; $i++) {
            $this->assertTrue($store->begin_attempt('client-a')['allowed'], 'the count started again');
        }
    }

    public function test_failures_are_forgotten_after_a_while(): void {
        $store = $this->store();
        for ($i = 0; $i < 3; $i++) {
            $store->begin_attempt('client-a');
        }
        $this->now += auth_store::FORGET_AFTER + 1;
        for ($i = 0; $i < auth_store::FREE_FAILURES + 1; $i++) {
            $this->assertTrue($store->begin_attempt('client-a')['allowed']);
        }
    }

    public function test_distributed_guessing_hits_a_global_limit(): void {
        $store = $this->store();
        $allowed = 0;
        for ($i = 0; $i < auth_store::GLOBAL_MAX + 10; $i++) {
            if ($store->begin_attempt('client-' . $i)['allowed']) {
                $allowed++;
            }
        }
        $this->assertSame(auth_store::GLOBAL_MAX + 1, $allowed, 'a fresh client is refused too once the ceiling is hit');
        $refused = $store->begin_attempt('someone-new');
        $this->assertFalse($refused['allowed']);
        $this->assertSame(auth_store::GLOBAL_LOCK, $refused['retry']);
        $this->now += auth_store::GLOBAL_LOCK + auth_store::GLOBAL_WINDOW;
        $this->assertTrue($store->begin_attempt('someone-new')['allowed'], 'and it passes');
    }

    // 4. The plaintext password is never persisted.

    public function test_the_plaintext_is_never_written_by_the_installers_state(): void {
        $store = $this->store();
        $attempt = $store->begin_attempt('client-a');
        $store->succeeded('client-a', $attempt['ticket']);
        $token = $store->create_session(['moodle-clone-2026-09-24-113503.zip'], 'Mozilla/5.0');
        $store->session_packages($token, 'Mozilla/5.0');

        // The store is never given the password at all; and nothing it writes contains the password or the token.
        $all = '';
        foreach (glob($this->dir . '/*') as $file) {
            $all .= (string) file_get_contents($file);
        }
        $this->assertStringContainsString('sessions', $all);
        $this->assertStringNotContainsString(self::PASSWORD, $all);
        $this->assertStringNotContainsString($token, $all, 'only a one-way hash of the session token is stored');
        $this->assertStringContainsString(hash('sha256', $token), $all);
    }

    public function test_the_installer_source_never_stores_a_submitted_password(): void {
        // A guard against regressions: the only use of the submitted password is verify_password().
        $source = (string) file_get_contents(__DIR__ . '/../installer/installer.php');
        preg_match_all('/\$_POST\[\'password\'\]/', $source, $uses);
        $this->assertSame(1, count($uses[0]), 'the password is read from the request exactly once');
        $this->assertSame(0, preg_match('/(file_put_contents|fwrite|error_log|\$_SESSION|\$this->state|\$_COOKIE)[^;\n]*\$password/', $source));
    }

    // 6. An installer session cannot be forged.

    public function test_sessions_cannot_be_forged(): void {
        $store = $this->store();
        $ua = 'Mozilla/5.0 (X11)';
        $real = $store->create_session(['moodle-clone-2026-09-24-113503.zip'], $ua);
        $this->assertSame(['moodle-clone-2026-09-24-113503.zip'], $store->session_packages($real, $ua));

        $tampered = $real;
        $tampered[10] = $tampered[10] === 'a' ? 'b' : 'a';
        $forgeries = [
            'empty' => '',
            'well-formed but never issued' => bin2hex(random_bytes(32)),
            'one character changed' => $tampered,
            'the hash the file stores' => hash('sha256', $real),
            'too short' => substr($real, 0, 63),
            'too long' => $real . '0',
            'upper case' => strtoupper($real),
            'array-ish' => '[]',
            'sql-ish' => "' OR '1'='1",
            'path-ish' => '../../auth',
            'php-ish' => '<?php exit; ?>',
            'null byte' => $real . "\0",
        ];
        foreach ($forgeries as $label => $token) {
            $this->assertNull($store->session_packages($token, $ua), $label);
        }
        $this->assertNull($store->session_packages($real, 'Some other browser'), 'a stolen token from another browser');
        $this->assertSame(['moodle-clone-2026-09-24-113503.zip'], $store->session_packages($real, $ua), 'the real one still works');
    }

    public function test_the_session_state_file_cannot_be_used_to_add_packages(): void {
        // Which packages a visitor may install is decided by the server-side record, not by anything in their PHP session.
        $store = $this->store();
        $token = $store->create_session(['moodle-clone-2026-09-24-113503.zip'], 'ua');
        $this->assertSame(['moodle-clone-2026-09-24-113503.zip'], $store->session_packages($token, 'ua'));
        $this->assertNotContains('moodle-clone-2026-09-25-000000.zip', $store->session_packages($token, 'ua'));
    }

    public function test_sessions_expire_when_idle_and_after_a_fixed_time(): void {
        $store = $this->store();
        $token = $store->create_session(['p.zip'], 'ua');
        $this->now += auth_store::IDLE_TTL - 1;
        $this->assertNotNull($store->session_packages($token, 'ua'), 'a request just before the idle limit is fine (and renews it)');
        $this->now += auth_store::IDLE_TTL - 1;
        $this->assertNotNull($store->session_packages($token, 'ua'));
        $this->now += auth_store::IDLE_TTL + 1;
        $this->assertNull($store->session_packages($token, 'ua'), 'idle for too long');

        $token = $store->create_session(['p.zip'], 'ua');
        $issued = $this->now;
        while ($this->now + auth_store::IDLE_TTL - 60 < $issued + auth_store::ABSOLUTE_TTL) {
            $this->now += auth_store::IDLE_TTL - 60;
            $this->assertNotNull($store->session_packages($token, 'ua'), 'active use keeps a session alive');
        }
        $this->now = $issued + auth_store::ABSOLUTE_TTL + 1;
        $this->assertNull($store->session_packages($token, 'ua'), 'never valid beyond the absolute limit, however active');
    }

    // 7. A successful installation invalidates installer authorization.

    public function test_finishing_revokes_every_session_and_forgets_all_failures(): void {
        $store = $this->store();
        $a = $store->create_session(['p.zip'], 'ua');
        $b = $store->create_session(['p.zip'], 'ua');
        for ($i = 0; $i < 10; $i++) {
            $store->begin_attempt('attacker');
        }
        $this->assertNotNull($store->session_packages($a, 'ua'));

        $this->assertTrue($store->destroy());
        $this->assertFileDoesNotExist($this->dir . '/auth.php');
        $this->assertNull($store->session_packages($a, 'ua'));
        $this->assertNull($store->session_packages($b, 'ua'));
        $this->assertFileDoesNotExist($this->dir . '/auth.php', 'checking a revoked session does not bring the file back');
        // Nothing lingers: a later installer in this folder starts with no sessions and no lock-outs.
        $this->assertTrue($this->store()->begin_attempt('attacker')['allowed']);
    }

    public function test_revocation_works_even_when_the_file_cannot_be_deleted(): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        $store = $this->store();
        $token = $store->create_session(['p.zip'], 'ua');
        chmod($this->dir, 0500);
        try {
            $this->assertTrue($store->destroy(), 'emptied instead of deleted');
        } finally {
            chmod($this->dir, 0700);
        }
        $this->assertNull($store->session_packages($token, 'ua'));
    }

    public function test_a_missing_or_damaged_state_file_authorizes_nobody(): void {
        $store = $this->store();
        $token = $store->create_session(['p.zip'], 'ua');
        $this->assertNotNull($store->session_packages($token, 'ua'));
        $damaged = ['', 'garbage', "<?php exit; ?>\nnot json", "<?php exit; ?>\n[]", "<?php exit; ?>\n{\"sessions\":\"x\"}",
            "<?php exit; ?>\n{\"sessions\":{\"a\":\"x\"},\"clients\":{\"b\":\"y\"},\"global\":{\"times\":\"z\"}}",
            "<?php exit; ?>\n{\"sessions\":{\"" . hash('sha256', $token) . "\":{\"idle\":\"never\",\"abs\":1}}}"];
        foreach ($damaged as $content) {
            file_put_contents($this->dir . '/auth.php', $content);
            $this->assertNull($store->session_packages($token, 'ua'), 'fails closed: ' . json_encode($content));
            // ...and the limiter keeps working on whatever is left.
            $this->assertTrue($store->begin_attempt('client-a')['allowed']);
        }
    }

    public function test_the_state_file_is_private_and_inert(): void {
        $store = $this->store();
        $store->create_session(['p.zip'], 'ua');
        $file = $this->dir . '/auth.php';
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertStringStartsWith("<?php exit; ?>\n", (string) file_get_contents($file), 'served as PHP it prints nothing');
    }
}
