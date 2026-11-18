<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ExchangeRates\ProviderErrorMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderErrorMessageTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function messages(): array
    {
        return [
            'query string' => ['GET https://rates.internal/v1/latest?apikey=abc&base=EUR failed', 'GET https://rates.internal/v1/latest?[redacted] failed'],
            'user info' => ['Could not reach http://admin:pa55@10.0.0.5:8080/rates', 'Could not reach http://[redacted]@10.0.0.5:8080/rates'],
            'fragment' => ['See https://rates.example/x#token=abc', 'See https://rates.example/x'],
            'bearer' => ['Sent Bearer abc.def.ghi', 'Sent Bearer [redacted]'],
            'name=value' => ['Rejected api_key=12345', 'Rejected api_key=[redacted]'],
            'header' => ['X-Api-Key: 12345 rejected', 'X-Api-Key: [redacted] rejected'],
            'json' => ['{"token":"abc","ok":false}', '{"token":"[redacted]","ok":false}'],
            'opaque key' => ['Key sk_live_0123456789abcdefABCDEF is invalid', 'Key [redacted] is invalid'],
            'plain text kept' => ['Frankfurter answered with HTTP 503.', 'Frankfurter answered with HTTP 503.'],
            'long word kept' => ['internationalization_is_unsupported_here', 'internationalization_is_unsupported_here'],
        ];
    }

    #[DataProvider('messages')]
    public function test_it_redacts_credentials_and_keeps_the_rest(string $message, string $expected): void
    {
        $this->assertSame($expected, ProviderErrorMessage::redact($message));
    }
}
