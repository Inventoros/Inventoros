<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CanonicalHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalHostTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function canonical(): array
    {
        return [
            'plain' => ['https://github.com/a/b.zip', 'github.com'],
            'uppercase is folded' => ['https://GitHub.COM/a', 'github.com'],
            'port' => ['https://inventoros.com:8443', 'inventoros.com'],
            'punycode' => ['https://xn--bcher-kva.example/x', 'xn--bcher-kva.example'],
            'query and fragment' => ['https://github.com/a?b=c#d', 'github.com'],
        ];
    }

    #[DataProvider('canonical')]
    public function test_returns_the_lowercase_host_of_a_canonical_url(string $url, string $host): void
    {
        $this->assertSame($host, CanonicalHost::of($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noncanonical(): array
    {
        return [
            'empty' => [''],
            'no host' => ['/relative/path'],
            'trailing dot' => ['https://github.com./x'],
            'empty label' => ['https://github..com/x'],
            'userinfo' => ['https://github.com@evil.test/x'],
            'backslash' => ['https://evil.test\\@github.com/x'],
            'percent-escape in host' => ['https://github%2ecom/x'],
            'non-ASCII host' => ["https://g\u{0456}thub.com/x"],
            'whitespace' => ['https://github.com /x'],
            'control character' => ["https://github.com\t/x"],
            'label with a leading hyphen' => ['https://-github.com/x'],
            'IPv6 literal' => ['https://[::1]/x'],
        ];
    }

    #[DataProvider('noncanonical')]
    public function test_refuses_a_noncanonical_url(string $url): void
    {
        $this->assertNull(CanonicalHost::of($url));
    }
}
