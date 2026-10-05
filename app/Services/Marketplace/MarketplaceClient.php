<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Support\CanonicalHost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Talks to the inventoros.com marketplace API (/api/v1/marketplace).
 *
 * SSRF guard: every request goes to the single configured origin, which must
 * be a bare https URL, with a path built only from fixed segments and a
 * validated slug. Redirects are never followed, so a response cannot bounce
 * the app to another host.
 */
final class MarketplaceClient
{
    private const API_PATH = '/api/v1/marketplace';

    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}$/i';

    private const TOO_LARGE = 'marketplace download exceeds the size limit';

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /**
     * The configured marketplace origin, validated.
     *
     * @throws MarketplaceException When the configured URL is not a bare https origin.
     */
    public function origin(): string
    {
        $url = trim((string) config('marketplace.url', ''));
        $parts = parse_url($url);

        $host = CanonicalHost::of($url);

        $valid = is_array($parts)
            && $host !== null
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);

        if (! $valid) {
            throw new MarketplaceException(
                'The marketplace URL is not a valid https address. Set INVENTOROS_MARKETPLACE_URL to an https origin such as https://inventoros.com.'
            );
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return 'https://'.$host.$port;
    }

    /**
     * Link to a plugin's public marketplace page, for "buy it here" messages.
     */
    public function pageUrl(string $slug): string
    {
        return $this->origin().'/marketplace/'.rawurlencode($slug);
    }

    /**
     * Installable plugins, as the (optionally authenticated) account sees them.
     * Cached briefly per token.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MarketplaceException
     */
    public function catalog(?string $token): array
    {
        $origin = $this->origin();
        $seconds = (int) config('marketplace.cache_seconds', 300);

        $fetch = function () use ($token): array {
            $data = $this->json($this->get('/plugins', $token), 'load the plugin catalog');

            return array_values(array_filter(
                is_array($data['data'] ?? null) ? $data['data'] : [],
                fn ($entry) => is_array($entry) && is_string($entry['slug'] ?? null) && self::isValidSlug($entry['slug']),
            ));
        };

        if ($seconds <= 0) {
            return $fetch();
        }

        return Cache::remember('marketplace.catalog.'.hash('sha256', $origin.'|'.($token ?? '')), $seconds, $fetch);
    }

    /**
     * One plugin's catalog entry, or null when the marketplace does not list it.
     *
     * @return array<string, mixed>|null
     *
     * @throws MarketplaceException
     */
    public function plugin(string $slug, ?string $token): ?array
    {
        $this->assertSlug($slug);

        $response = $this->get('/plugins/'.rawurlencode($slug), $token);

        if ($response->status() === 404) {
            return null;
        }

        $data = $this->json($response, 'look up this plugin');
        $entry = $data['data'] ?? null;

        if (! is_array($entry) || ($entry['slug'] ?? null) !== $slug) {
            throw new MarketplaceException('The marketplace returned an unexpected answer for this plugin.');
        }

        return $entry;
    }

    /**
     * The inventoros.com account a token belongs to.
     *
     * @return array{name: string, email: string, owned: array<int, string>}
     *
     * @throws MarketplaceException When the token is rejected or the marketplace is unreachable.
     */
    public function me(string $token): array
    {
        $response = $this->get('/me', $token);

        if (in_array($response->status(), [401, 403], true)) {
            throw new MarketplaceException('The marketplace rejected this token. Create a new marketplace connection token on your inventoros.com account page and paste it here.');
        }

        $data = $this->json($response, 'check the token')['data'] ?? null;

        return [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : '',
            'email' => is_string($data['email'] ?? null) ? $data['email'] : '',
            'owned' => array_values(array_filter((array) ($data['owned'] ?? []), 'is_string')),
        ];
    }

    /**
     * The signed runtime entitlements of the token's account for this
     * installation (see EntitlementStatement). The caller verifies every
     * signature; this only checks the shape.
     *
     * @return array{install_id: string, issued_at: string|null, entitlements: array<int, array{slug: string, expires_at: string, signature: string}>}
     *
     * @throws MarketplaceException When the token is rejected, the marketplace is unreachable or the answer is malformed.
     */
    public function entitlements(string $token, string $installId): array
    {
        if (! EntitlementStatement::isValidInstallId($installId)) {
            throw new MarketplaceException('Invalid installation id.');
        }

        $response = $this->get('/entitlements?install_id='.rawurlencode($installId), $token);

        if (in_array($response->status(), [401, 403], true)) {
            throw new MarketplaceException('The marketplace rejected the connection token of this organization.', 401);
        }

        $data = $this->json($response, 'check plugin licences')['data'] ?? null;

        if (! is_array($data) || ($data['install_id'] ?? null) !== $installId || ! is_array($data['entitlements'] ?? null)) {
            throw new MarketplaceException('The marketplace returned an unexpected answer for plugin licences.');
        }

        $entries = array_values(array_filter($data['entitlements'], fn ($entry) => is_array($entry)
            && is_string($entry['slug'] ?? null) && self::isValidSlug($entry['slug'])
            && is_string($entry['expires_at'] ?? null) && EntitlementStatement::isValidExpiresAt($entry['expires_at'])
            && is_string($entry['signature'] ?? null)));

        return [
            'install_id' => $installId,
            'issued_at' => is_string($data['issued_at'] ?? null) ? $data['issued_at'] : null,
            'entitlements' => array_map(fn (array $entry) => [
                'slug' => $entry['slug'],
                'expires_at' => $entry['expires_at'],
                'signature' => $entry['signature'],
            ], $entries),
        ];
    }

    /**
     * Download a plugin's latest package to a temporary file. The caller
     * verifies it and must delete the file.
     *
     * The body is streamed straight to the file and the transfer is aborted
     * once it passes INVENTOROS_MARKETPLACE_MAX_DOWNLOAD_BYTES, whatever the
     * Content-Length says (or whether it is sent at all), so a lying or
     * endless response is never held in memory.
     *
     * @return array{path: string, signature: string|null, checksum: string|null, version: string|null}
     *
     * @throws MarketplaceException
     */
    public function download(string $slug, ?string $token): array
    {
        $this->assertSlug($slug);

        $max = (int) config('marketplace.max_download_bytes', 50 * 1024 * 1024);
        $tooLarge = fn () => new MarketplaceException('The plugin package is larger than the download size limit (INVENTOROS_MARKETPLACE_MAX_DOWNLOAD_BYTES).');

        $directory = storage_path('app/marketplace-downloads');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.bin2hex(random_bytes(8)).'.zip';

        try {
            try {
                $response = $this->request($token)
                    ->withOptions($this->downloadOptions($path, $max))
                    ->get($this->origin().self::API_PATH.'/plugins/'.rawurlencode($slug).'/download');
            } catch (MarketplaceException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if (self::isTooLarge($e)) {
                    throw $tooLarge();
                }

                throw new MarketplaceException('Could not reach the marketplace to download this plugin. Try again later.', 0, $e);
            }

            match (true) {
                $response->successful() => null,
                $response->status() === 401 => throw new MarketplaceException($token
                    ? 'The marketplace rejected your connected account. Reconnect your inventoros.com account and try again.'
                    : 'This is a paid plugin. Connect your inventoros.com account on the Marketplace tab to install it.'),
                $response->status() === 403 => throw new MarketplaceException(
                    'Your connected inventoros.com account does not own this plugin. Get it at '.$this->pageUrl($slug).'.'
                ),
                $response->status() === 404 => throw new MarketplaceException('This plugin is not available from the marketplace.'),
                $response->status() === 429 => throw new MarketplaceException('The marketplace is limiting requests right now. Try again in a minute.'),
                default => throw new MarketplaceException("The marketplace could not serve this plugin (HTTP {$response->status()})."),
            };

            if (! $this->writeBodyIfNotStreamed($response, $path, $max)) {
                throw $tooLarge();
            }

            clearstatcache(true, $path);
            if (! is_file($path) || filesize($path) > $max) {
                throw $tooLarge();
            }
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        $header = fn (string $name) => ($value = trim($response->header($name))) === '' ? null : $value;

        return [
            'path' => $path,
            'signature' => $header('X-Marketplace-Signature'),
            'checksum' => $header('X-Marketplace-Checksum'),
            'version' => $header('X-Marketplace-Version'),
        ];
    }

    /**
     * Guzzle options for a capped download: the body is written to $sink and
     * the transfer aborts past $max bytes, from the Content-Length header when
     * one is sent and from the running byte count either way.
     *
     * @return array{sink: string, on_headers: callable, progress: callable}
     */
    public function downloadOptions(string $sink, int $max): array
    {
        return [
            'sink' => $sink,
            'on_headers' => function (ResponseInterface $response) use ($max) {
                if ((int) $response->getHeaderLine('Content-Length') > $max) {
                    throw new \RuntimeException(self::TOO_LARGE);
                }
            },
            'progress' => function ($downloadTotal, $downloaded) use ($max) {
                if ((int) $downloaded > $max) {
                    throw new \RuntimeException(self::TOO_LARGE);
                }
            },
        ];
    }

    /**
     * A real transfer has already streamed the body into $path through the
     * sink. A handler that ignores `sink` (the HTTP fake) leaves it empty; copy
     * the body across in chunks under the same cap. False when it is too large.
     */
    private function writeBodyIfNotStreamed(Response $response, string $path, int $max): bool
    {
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) > 0) {
            return true;
        }

        $body = $response->toPsrResponse()->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new MarketplaceException('Could not save the downloaded plugin package.');
        }

        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $written += strlen($chunk);

                if ($written > $max) {
                    return false;
                }

                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    private static function isTooLarge(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (str_contains($current->getMessage(), self::TOO_LARGE)) {
                return true;
            }
        }

        return false;
    }

    private function assertSlug(string $slug): void
    {
        if (! self::isValidSlug($slug)) {
            throw new MarketplaceException('Invalid plugin slug.');
        }
    }

    private function request(?string $token): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('marketplace.timeout', 20))
            ->withOptions(['allow_redirects' => false]);

        return $token !== null && $token !== '' ? $request->withToken($token) : $request;
    }

    /**
     * @throws MarketplaceException
     */
    private function get(string $path, ?string $token): Response
    {
        $url = $this->origin().self::API_PATH.$path;

        try {
            return $this->request($token)->get($url);
        } catch (\Throwable $e) {
            throw new MarketplaceException('Could not reach the marketplace. Check the connection and try again.', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MarketplaceException
     */
    private function json(Response $response, string $action): array
    {
        if ($response->status() === 429) {
            throw new MarketplaceException('The marketplace is limiting requests right now. Try again in a minute.');
        }

        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data)) {
            throw new MarketplaceException("Could not {$action}: the marketplace answered with HTTP {$response->status()}.");
        }

        return $data;
    }
}
