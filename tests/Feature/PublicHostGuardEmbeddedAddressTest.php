<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PublicHostGuard;
use App\Support\PublicHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * IPv6 forms that carry an IPv4 address (IPv4-mapped, NAT64, 6to4) reach
 * that IPv4 address through a translator, so they must be judged by the
 * embedded address. A trailing-dot hostname must not slip past the pinned
 * DNS answer.
 */
final class PublicHostGuardEmbeddedAddressTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function embeddedPrivateAddresses(): array
    {
        return [
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'NAT64 loopback' => ['64:ff9b::7f00:1'],
            'NAT64 metadata' => ['64:ff9b::a9fe:a9fe'],
            'NAT64 RFC1918' => ['64:ff9b::a00:1'],
            'local-use NAT64 prefix' => ['64:ff9b:1::a9fe:a9fe'],
            '6to4 loopback' => ['2002:7f00:1::'],
            '6to4 metadata' => ['2002:a9fe:a9fe::'],
        ];
    }

    #[DataProvider('embeddedPrivateAddresses')]
    public function test_an_ip_literal_embedding_a_private_ipv4_is_rejected(string $ip): void
    {
        $this->mock(PublicHostResolver::class)->shouldNotReceive('resolve');

        $this->expectException(RuntimeException::class);
        PublicHostGuard::assertPublic("https://[{$ip}]/hook");
    }

    #[DataProvider('embeddedPrivateAddresses')]
    public function test_a_hostname_resolving_to_an_embedded_private_ipv4_is_rejected(string $ip): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->andReturn([$ip]);

        $this->expectException(RuntimeException::class);
        PublicHostGuard::connectionOptions('https://receiver.example.com/hook');
    }

    public function test_a_nat64_address_embedding_a_public_ipv4_is_still_public(): void
    {
        $this->assertTrue(PublicHostGuard::isPublicIp('64:ff9b::808:808'));
    }

    public function test_a_trailing_dot_hostname_resolving_to_a_private_ip_is_rejected(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->andReturn(['10.0.0.8']);

        $this->expectException(RuntimeException::class);
        PublicHostGuard::assertPublic('https://internal.example.com./hook');
    }

    /**
     * cURL's pinned answer is keyed by the host name; a trailing-dot spelling
     * must not be pinned under one name and connected under another.
     */
    public function test_a_trailing_dot_hostname_is_refused_for_delivery(): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->andReturn(['8.8.8.8']);

        $this->expectException(RuntimeException::class);
        PublicHostGuard::connectionOptions('https://receiver.example.com./hook');
    }
}
