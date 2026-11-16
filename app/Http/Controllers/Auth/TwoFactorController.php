<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Services\SecurityEventLogger;
use App\Services\TwoFactorVerifier;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $this->google2fa = new Google2FA;
    }

    /**
     * Show the 2FA setup page with QR code.
     */
    public function setup(Request $request): Response
    {
        $user = $request->user();

        if ($user->two_factor_enabled) {
            $request->session()->forget(['two_factor_secret', 'two_factor_recovery_codes']);

            return Inertia::render('Auth/TwoFactorSetup', [
                'qrCodeSvg' => null,
                'secret' => null,
                'recoveryCodes' => [],
                'enabled' => true,
            ]);
        }

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
        // Enrolling a second factor needs the current password, so a borrowed
        // session cannot bind the account to someone else's authenticator.
        $request->validate([
            'code' => 'required|string|size:6',
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $secret = $request->session()->get('two_factor_secret');

        if (! $secret) {
            return redirect()->route('two-factor.setup')
                ->withErrors(['code' => __('Please start the setup process again.')]);
        }

        // The timestep is stored so this code cannot be replayed at sign-in.
        $timestep = app(TwoFactorVerifier::class)->timestepOf($secret, (string) $request->input('code'));

        if ($timestep === null) {
            return redirect()->back()
                ->withErrors(['code' => __('The provided code is invalid.')]);
        }

        $recoveryCodes = $request->session()->get('two_factor_recovery_codes', $this->generateRecoveryCodes());

        // Two setup requests may have started before either enabled MFA.
        // Serialize activation and re-read the authoritative factor state.
        $enabledUser = DB::transaction(function () use ($request, $secret, $recoveryCodes, $timestep) {
            $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->getKey());

            if ($user->two_factor_enabled) {
                throw ValidationException::withMessages([
                    'code' => __('Two-factor authentication is already enabled. Disable it with your current password before setting it up again.'),
                ]);
            }

            $user->forceFill([
                'two_factor_secret' => encrypt($secret),
                'two_factor_enabled' => true,
                'two_factor_recovery_codes' => encrypt(json_encode(static::hashRecoveryCodes($recoveryCodes))),
                'two_factor_last_used_timestep' => $timestep,
            ])->save();

            return $user;
        });

        app(SecurityEventLogger::class)->record(SecurityEvent::TWO_FACTOR_ENABLED, $request->user(), $enabledUser);

        // Mark session as verified for this user, under a fresh session id,
        // so middleware doesn't redirect
        $this->markSessionVerified($request, $enabledUser);

        // Clean up session
        $request->session()->forget('two_factor_secret');
        $request->session()->forget('two_factor_recovery_codes');

        return redirect()->route('settings.account.index')
            ->with('success', __('Two-factor authentication has been enabled.'));
    }

    /**
     * Disable 2FA after verifying password.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            return redirect()->back()
                ->withErrors(['password' => __('auth.password')]);
        }

        $request->user()->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_recovery_codes' => null,
        ]);

        $request->session()->forget(EnsureTwoFactorVerified::SESSION_KEY);

        app(SecurityEventLogger::class)->record(SecurityEvent::TWO_FACTOR_DISABLED, $request->user(), $request->user());

        return redirect()->route('settings.account.index')
            ->with('success', __('Two-factor authentication has been disabled.'));
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
        $verifier = app(TwoFactorVerifier::class);

        // Check if using recovery code. A valid one works even while the
        // account is locked out, so someone holding only the password cannot
        // keep the owner out.
        if ($request->filled('recovery_code')) {
            return $this->verifyRecoveryCode($request, $user);
        }

        // One failure budget per account, shared with the API login.
        if ($verifier->tooManyFailures($user)) {
            return redirect()->back()
                ->withErrors(['code' => $verifier->lockoutMessage($user)]);
        }

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $result = $verifier->checkTotp($user, (string) $request->input('code'));

        if ($result === TwoFactorVerifier::TOTP_REPLAYED) {
            return redirect()->back()
                ->withErrors(['code' => __(TwoFactorVerifier::REPLAY_MESSAGE)]);
        }

        if ($result !== TwoFactorVerifier::TOTP_ACCEPTED) {
            app(SecurityEventLogger::class)->record(SecurityEvent::TWO_FACTOR_FAILED, $user, $user, ['method' => 'totp']);
            $verifier->recordFailure($user);

            return redirect()->back()
                ->withErrors(['code' => __('The provided code is invalid.')]);
        }

        $verifier->clearFailures($user);
        $this->markSessionVerified($request, $user);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Verify a recovery code.
     */
    protected function verifyRecoveryCode(Request $request, $user): RedirectResponse
    {
        $recoveryCode = (string) $request->input('recovery_code');
        $verifier = app(TwoFactorVerifier::class);
        $lockedOut = $verifier->tooManyFailures($user);

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
            app(SecurityEventLogger::class)->record(SecurityEvent::TWO_FACTOR_FAILED, $user, $user, ['method' => 'recovery_code']);
            $verifier->recordFailure($user);

            return redirect()->back()
                ->withErrors(['code' => $lockedOut ? $verifier->lockoutMessage($user) : __('The provided recovery code is invalid.')]);
        }

        $verifier->clearFailures($user);
        $this->markSessionVerified($request, $user);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Record that this session passed the second factor as $user, under a
     * fresh session id.
     */
    protected function markSessionVerified(Request $request, $user): void
    {
        $request->session()->regenerate();
        $request->session()->put(EnsureTwoFactorVerified::SESSION_KEY, $user->getAuthIdentifier());
    }

    /**
     * Generate a QR code SVG string.
     */
    protected function generateQrCodeSvg(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd
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
     * @param  array<string>  $codes
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
     * @param  array<string>  $storedCodes  Passed by reference so callers see the consumption + migration.
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
                if (! $looksHashed) {
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
