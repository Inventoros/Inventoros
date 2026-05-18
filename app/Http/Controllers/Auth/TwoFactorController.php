<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/**
 * Controller for managing two-factor authentication (TOTP).
 *
 * Handles 2FA setup, enabling, disabling, and challenge verification.
 */
class TwoFactorController extends Controller
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Show the 2FA setup page with QR code.
     */
    public function setup(Request $request): Response
    {
        $user = $request->user();

        $secret = $this->google2fa->generateSecretKey();

        // Store secret in session for verification during enable step
        $request->session()->put('two_factor_secret', $secret);

        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name', 'Inventoros'),
            $user->email,
            $secret
        );

        $qrCodeSvg = $this->generateQrCodeSvg($qrCodeUrl);

        // Generate recovery codes for display
        $recoveryCodes = $this->generateRecoveryCodes();
        $request->session()->put('two_factor_recovery_codes', $recoveryCodes);

        return Inertia::render('Auth/TwoFactorSetup', [
            'qrCodeSvg' => $qrCodeSvg,
            'secret' => $secret,
            'recoveryCodes' => $recoveryCodes,
            'enabled' => $user->two_factor_enabled,
        ]);
    }

    /**
     * Enable 2FA after verifying the TOTP code.
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $secret = $request->session()->get('two_factor_secret');

        if (!$secret) {
            return redirect()->route('two-factor.setup')
                ->withErrors(['code' => 'Please start the setup process again.']);
        }

        $valid = $this->google2fa->verifyKey($secret, $request->input('code'));

        if (!$valid) {
            return redirect()->back()
                ->withErrors(['code' => 'The provided code is invalid.']);
        }

        $recoveryCodes = $request->session()->get('two_factor_recovery_codes', $this->generateRecoveryCodes());

        $request->user()->update([
            'two_factor_secret' => encrypt($secret),
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => encrypt(json_encode(static::hashRecoveryCodes($recoveryCodes))),
        ]);

        // Mark session as verified so middleware doesn't redirect
        $request->session()->put('two_factor_verified', true);

        // Clean up session
        $request->session()->forget('two_factor_secret');
        $request->session()->forget('two_factor_recovery_codes');

        return redirect()->route('settings.account.index')
            ->with('success', 'Two-factor authentication has been enabled.');
    }

    /**
     * Disable 2FA after verifying password.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        if (!Hash::check($request->input('password'), $request->user()->password)) {
            return redirect()->back()
                ->withErrors(['password' => 'The provided password is incorrect.']);
        }

        $request->user()->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_recovery_codes' => null,
        ]);

        $request->session()->forget('two_factor_verified');

        return redirect()->route('settings.account.index')
            ->with('success', 'Two-factor authentication has been disabled.');
    }

    /**
     * Show the 2FA challenge page.
     */
    public function challenge(Request $request): Response
    {
        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Verify the 2FA challenge code or recovery code.
     */
    public function verifyChallenge(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Check if using recovery code
        if ($request->filled('recovery_code')) {
            return $this->verifyRecoveryCode($request, $user);
        }

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $secret = decrypt($user->two_factor_secret);
        $valid = $this->google2fa->verifyKey($secret, $request->input('code'));

        if (!$valid) {
            return redirect()->back()
                ->withErrors(['code' => 'The provided code is invalid.']);
        }

        $request->session()->put('two_factor_verified', true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Verify a recovery code.
     */
    protected function verifyRecoveryCode(Request $request, $user): RedirectResponse
    {
        $recoveryCode = (string) $request->input('recovery_code');

        try {
            DB::transaction(function () use ($user, $recoveryCode) {
                $locked = $user->newQuery()->lockForUpdate()->findOrFail($user->getKey());
                $storedCodes = json_decode(decrypt($locked->two_factor_recovery_codes), true) ?: [];

                $key = static::findAndConsumeRecoveryCode($recoveryCode, $storedCodes);
                if ($key === null) {
                    throw new \RuntimeException('invalid');
                }

                $locked->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($storedCodes))),
                ])->save();
            });
        } catch (\RuntimeException $e) {
            return redirect()->back()
                ->withErrors(['code' => 'The provided recovery code is invalid.']);
        }

        $request->session()->put('two_factor_verified', true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Generate a QR code SVG string.
     */
    protected function generateQrCodeSvg(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $writer->writeString($url);
    }

    /**
     * Generate 8 random recovery codes.
     *
     * @return array<string>
     */
    protected function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::random(10);
        }

        return $codes;
    }

    /**
     * Hash plaintext recovery codes for at-rest storage.
     *
     * Recovery codes are stored as sha256 hashes so an APP_KEY leak does
     * not directly yield usable codes. The plaintext is shown to the user
     * once at setup; the DB never holds it again.
     *
     * @param array<string> $codes
     * @return array<string>
     */
    public static function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => hash('sha256', $code), $codes);
    }

    /**
     * Find $input within $storedCodes (handling both legacy plaintext
     * entries and post-fix sha256 entries), consume it, and return the
     * matched index. If $input doesn't match any entry, returns null.
     *
     * When the match is against a legacy plaintext entry, the remaining
     * entries in $storedCodes are migrated in-place to their hashed form
     * so the next consume cannot leak plaintext via DB read.
     *
     * @param array<string> $storedCodes Passed by reference so callers see the consumption + migration.
     */
    public static function findAndConsumeRecoveryCode(string $input, array &$storedCodes): ?int
    {
        $inputHash = hash('sha256', $input);

        foreach ($storedCodes as $i => $stored) {
            // sha256 hex hashes are 64 chars and only [0-9a-f]; legacy
            // recovery codes are 10 chars of Str::random alphanumeric. Use
            // the hash comparison first to avoid leaking timing info via
            // strcmp shortcuts.
            $looksHashed = strlen($stored) === 64 && ctype_xdigit($stored);

            $matched = $looksHashed
                ? hash_equals($stored, $inputHash)
                : hash_equals($stored, $input);

            if ($matched) {
                unset($storedCodes[$i]);
                if (!$looksHashed) {
                    // Migrate the remaining legacy plaintext entries to
                    // hashed form so the next consume has nothing to leak.
                    $storedCodes = static::hashRecoveryCodes(array_values($storedCodes));
                }
                return $i;
            }
        }

        return null;
    }
}
