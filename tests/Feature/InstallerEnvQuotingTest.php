<?php

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallerController;
use Dotenv\Parser\Parser;
use Tests\TestCase;

class InstallerEnvQuotingTest extends TestCase
{
    /**
     * Compose a single env line via the installer's helper, parse it back
     * through phpdotenv, and assert the round-trip preserves the value.
     */
    protected function assertRoundTrip(string $key, string $value): void
    {
        $line = $key . '=' . InstallerController::quoteEnvValue($value);

        $entries = (new Parser())->parse($line);

        $this->assertCount(1, $entries, "Expected exactly one parsed entry from `{$line}`");
        $entry = $entries[0];

        $this->assertSame($key, $entry->getName());
        $this->assertTrue($entry->getValue()->isDefined(), 'Parsed entry has no value');
        $this->assertSame($value, $entry->getValue()->get()->getChars(), 'Round-trip mismatch');
    }

    public function test_password_with_dollar_sign_round_trips(): void
    {
        $this->assertRoundTrip('DB_PASSWORD', 'p@ss$1word');
    }

    public function test_password_with_double_quote_round_trips(): void
    {
        $this->assertRoundTrip('DB_PASSWORD', 'pa"ss"word');
    }

    public function test_password_with_backslash_round_trips(): void
    {
        $this->assertRoundTrip('DB_PASSWORD', 'pa\\ss\\word');
    }

    public function test_password_with_hash_round_trips(): void
    {
        // Unquoted, the # would truncate the line as a comment.
        $this->assertRoundTrip('DB_PASSWORD', 'has#hash');
    }

    public function test_password_with_newline_round_trips(): void
    {
        $this->assertRoundTrip('DB_PASSWORD', "line1\nline2");
    }

    public function test_mixed_special_characters_round_trip(): void
    {
        $this->assertRoundTrip('DB_PASSWORD', 'a$b"c\\d#e f');
    }

    public function test_simple_value_round_trips_unchanged(): void
    {
        $this->assertRoundTrip('APP_NAME', 'Inventoros');
    }
}
