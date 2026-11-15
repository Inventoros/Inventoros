<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The one-time secret that unlocks the web installer.
 *
 * Until Inventoros is installed, the installer configures the database and
 * creates the first organization and administrator, so only someone who can
 * read the server's configuration or files may use it. The token is
 * INVENTOROS_SETUP_TOKEN when set (the Linux installer generates one into
 * .env); otherwise a random token is written to storage/app/private/setup-token
 * the first time the installer is opened, and the log names that file (never
 * the token). The file is deleted when the installation completes.
 */
class InstallerSetupToken
{
    /**
     * Session key holding a fingerprint of the token the session entered.
     */
    public const SESSION_KEY = 'installer_unlocked';

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('app/private/setup-token');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The token that unlocks the installer: the configured one, else the
     * token file's, generating the file when there is none yet. Null when
     * neither is available (the file cannot be written).
     */
    public function expected(): ?string
    {
        $configured = trim((string) config('app.setup_token', ''));
        if ($configured !== '') {
            return $configured;
        }

        return $this->readFile() ?? $this->generateFile();
    }

    public function matches(string $given): bool
    {
        $expected = $this->expected();

        return $expected !== null && hash_equals($expected, $given);
    }

    /**
     * Remember on the session that it entered the current token.
     */
    public function unlock(Request $request): void
    {
        $expected = $this->expected();
        if ($expected === null) {
            return;
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $this->fingerprint($expected));
    }

    /**
     * Whether this session entered the current token. A changed or rotated
     * token locks it again.
     */
    public function isUnlocked(Request $request): bool
    {
        $stored = $request->session()->get(self::SESSION_KEY);
        $expected = $this->expected();

        return is_string($stored) && $expected !== null && hash_equals($this->fingerprint($expected), $stored);
    }

    /**
     * After the installation: forget the session's unlock and delete the
     * token file, which can never be used again.
     */
    public function retire(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);

        if (is_file($this->path) && ! @unlink($this->path)) {
            Log::warning('Could not delete the installer setup token file '.$this->path.'; delete it by hand.', ['path' => $this->path]);
        }
    }

    private function fingerprint(string $token): string
    {
        return hash('sha256', 'inventoros-installer-setup-token|'.$token);
    }

    private function readFile(): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $token = trim((string) @file_get_contents($this->path));

        return $token === '' ? null : $token;
    }

    private function generateFile(): ?string
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        // 256 bits, URL-safe.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // Exclusive create: when two first visits race, both use the token
        // that was written first. The umask keeps the file private from the
        // start (0600); chmod covers systems that ignore it.
        $umask = umask(0077);
        try {
            $handle = @fopen($this->path, 'x');
        } finally {
            umask($umask);
        }

        if ($handle === false) {
            $existing = $this->readFile();
            if ($existing === null) {
                Log::error('Could not write the installer setup token to '.$this->path.'. Make storage/app/private writable, or set INVENTOROS_SETUP_TOKEN, to unlock the web installer.', ['path' => $this->path]);
            }

            return $existing;
        }

        fwrite($handle, $token."\n");
        fclose($handle);
        @chmod($this->path, 0600);

        Log::warning('Inventoros is not installed yet. The web installer is locked with a setup token stored in '.$this->path.'; open that file on the server to unlock it.', ['path' => $this->path]);

        return $token;
    }
}
