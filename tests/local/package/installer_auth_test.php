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

namespace tool_moodleclone\local\package;

/**
 * Tests for the installer password verifier (the source-site half of the installer authorization).
 *
 * @package    tool_moodleclone
 * @category   test
 * @copyright  2026 vishnunarayanantech
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_moodleclone\local\package\installer_auth
 */
class installer_auth_test extends \basic_testcase {

    /** @var string A password used throughout. */
    private const PASSWORD = 'correct horse battery staple';

    public function test_correct_password_verifies_and_wrong_ones_do_not(): void {
        $auth = installer_auth::from_password(self::PASSWORD);
        $this->assertTrue($auth->is_password());
        $this->assertTrue($auth->verify(self::PASSWORD));
        foreach (['', 'wrong', self::PASSWORD . ' ', ' ' . self::PASSWORD, strtoupper(self::PASSWORD), substr(self::PASSWORD, 1)] as $wrong) {
            $this->assertFalse($auth->verify($wrong), "'{$wrong}' must not verify");
        }
    }

    public function test_the_description_is_a_salted_slow_verifier_and_never_holds_the_password(): void {
        $auth = installer_auth::from_password(self::PASSWORD);
        $data = $auth->to_array();

        $this->assertSame(['mode', 'kdf', 'iterations', 'salt', 'verifier'], array_keys($data));
        $this->assertSame('password', $data['mode']);
        $this->assertSame('pbkdf2-sha256', $data['kdf']);
        $this->assertTrue($data['iterations'] >= 600000, 'OWASP minimum for PBKDF2-HMAC-SHA256');
        $this->assertSame(16, strlen(base64_decode($data['salt'], true)));
        $this->assertSame(32, strlen(base64_decode($data['verifier'], true)));

        $json = json_encode($data);
        $this->assertStringNotContainsString(self::PASSWORD, $json);
        $this->assertStringNotContainsString(base64_encode(self::PASSWORD), $json);
        $this->assertStringNotContainsString(bin2hex(self::PASSWORD), $json);
        $this->assertStringNotContainsString(hash('sha256', self::PASSWORD), $json);
        // Not a fast, unsalted digest of the password either.
        foreach (['md5', 'sha1', 'sha256'] as $algo) {
            $this->assertStringNotContainsString(base64_encode(hash($algo, self::PASSWORD, true)), $json);
        }
        // An independent computation of the documented KDF reproduces the verifier.
        $this->assertSame(base64_decode($data['verifier'], true), hash_pbkdf2('sha256', self::PASSWORD,
            base64_decode($data['salt'], true), $data['iterations'], 32, true));
    }

    public function test_every_verifier_has_its_own_salt(): void {
        $a = installer_auth::from_password(self::PASSWORD)->to_array();
        $b = installer_auth::from_password(self::PASSWORD)->to_array();
        $this->assertNotSame($a['salt'], $b['salt']);
        $this->assertNotSame($a['verifier'], $b['verifier']);
    }

    public function test_the_description_round_trips_through_json(): void {
        $auth = installer_auth::from_password(self::PASSWORD);
        $again = installer_auth::from_array(json_decode(json_encode($auth->to_array()), true));
        $this->assertTrue($again->verify(self::PASSWORD));
        $this->assertFalse($again->verify('another passphrase'));
    }

    public function test_key_file_mode_never_verifies_a_password(): void {
        $auth = installer_auth::keyfile();
        $this->assertFalse($auth->is_password());
        $this->assertSame(['mode' => 'keyfile'], $auth->to_array());
        $this->assertFalse($auth->verify(''));
        $this->assertFalse($auth->verify(self::PASSWORD));
    }

    /**
     * @return array
     */
    public function password_provider(): array {
        return [
            'fine' => [self::PASSWORD, self::PASSWORD, []],
            'exactly the minimum' => ['abcdefghijkl', 'abcdefghijkl', []],
            'unicode counts characters, not bytes' => ["\u{00e9}\u{00e8}\u{00ea}\u{00eb}\u{00e0}\u{00e2}\u{00e4}\u{00ef}\u{00ee}\u{00f4}\u{00f6}\u{00fc}",
                "\u{00e9}\u{00e8}\u{00ea}\u{00eb}\u{00e0}\u{00e2}\u{00e4}\u{00ef}\u{00ee}\u{00f4}\u{00f6}\u{00fc}", []],
            'too short' => ['abcdefghijk', 'abcdefghijk', ['short']],
            'empty' => ['', '', ['short']],
            'mismatch' => [self::PASSWORD, self::PASSWORD . 'x', ['mismatch']],
            'repetitive' => ['aaaaaaaaaaaaaaaa', 'aaaaaaaaaaaaaaaa', ['weak']],
            'few distinct characters' => ['ababababababab', 'ababababababab', ['weak']],
            'too long' => [str_repeat('abcdef', 200), str_repeat('abcdef', 200), ['long']],
        ];
    }

    /**
     * @dataProvider password_provider
     * @param string $password
     * @param string $confirmation
     * @param string[] $expected
     */
    public function test_password_rules(string $password, string $confirmation, array $expected): void {
        $this->assertSame($expected, installer_auth::password_problems($password, $confirmation));
    }

    public function test_an_unacceptable_password_cannot_be_turned_into_a_verifier(): void {
        $this->expectException(\InvalidArgumentException::class);
        installer_auth::from_password('short');
    }

    /**
     * @return array
     */
    public function invalid_description_provider(): array {
        $good = installer_auth::from_password(self::PASSWORD)->to_array();
        $with = function(string $key, $value) use ($good) {
            $data = $good;
            $data[$key] = $value;
            return $data;
        };
        return [
            'no mode' => [[], 'mode must be one of'],
            'unknown mode' => [['mode' => 'none'], 'mode must be one of'],
            'unknown key' => [$with('extra', 1), "unknown key 'installer_auth.extra'"],
            'the plaintext smuggled in' => [$with('password', self::PASSWORD), "unknown key 'installer_auth.password'"],
            'other kdf' => [$with('kdf', 'md5'), 'kdf must be'],
            'iterations as string' => [$with('iterations', '600000'), 'iterations must be an integer'],
            'iterations too few' => [$with('iterations', 1000), 'iterations must be an integer'],
            'iterations absurd (a hostile package must not stall the server)' => [$with('iterations', 2000000000), 'iterations must be an integer'],
            'short salt' => [$with('salt', base64_encode('abc')), 'salt must be base64 of 16 bytes'],
            'salt not base64' => [$with('salt', '!!!!'), 'salt must be base64 of 16 bytes'],
            'salt not canonical' => [$with('salt', rtrim($good['salt'], '=') . "\n"), 'salt must be base64 of 16 bytes'],
            'short verifier' => [$with('verifier', base64_encode('abc')), 'verifier must be base64 of 32 bytes'],
            'keyfile with extras' => [['mode' => 'keyfile', 'salt' => $good['salt']], "unknown key 'installer_auth.salt'"],
        ];
    }

    /**
     * @dataProvider invalid_description_provider
     * @param array $data
     * @param string $message
     */
    public function test_invalid_descriptions_are_rejected(array $data, string $message): void {
        $errors = installer_auth::validate($data);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($message, implode(';', $errors));
        try {
            installer_auth::from_array($data);
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid installer_auth', $e->getMessage());
        }
    }
}
