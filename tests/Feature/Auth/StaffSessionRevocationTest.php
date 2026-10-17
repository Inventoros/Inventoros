<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

// Separate persisted sessions exercise the complete authentication pipeline.
class StaffSessionRevocationTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    private string $auditSessionDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->auditSessionDirectory = sys_get_temp_dir().'/inventoros-session-audit-'.bin2hex(random_bytes(8));
        mkdir($this->auditSessionDirectory);
        config(['session.driver' => 'file', 'session.files' => $this->auditSessionDirectory]);
        $this->newBrowser();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->auditSessionDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->auditSessionDirectory);
        parent::tearDown();
    }

    private function newBrowser(?string $sessionId = null): void
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->defaultCookies = [];
        if ($sessionId !== null) {
            $this->withCookie(config('session.cookie'), $sessionId);
        }
    }

    public function test_password_recovery_revokes_a_separate_persisted_session(): void
    {
        $org = $this->makeOrganization('Independent session audit');
        $user = $this->makeStaff($org, 'session-recovery@audit.test');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $attackerSession = app('session.store')->getId();
        $this->assertFileExists($this->auditSessionDirectory.'/'.$attackerSession);

        // Reload the cookie into a newly constructed guard and session store.
        $this->newBrowser($attackerSession);
        $this->assertNull(Auth::guard('web')->getUser());
        $this->get('/profile')->assertOk();
        $this->assertSame($user->id, Auth::id());

        // A distinct guest browser completes the actual recovery endpoint.
        $token = Password::createToken($user);
        $this->newBrowser();
        $this->assertNull(Auth::guard('web')->getUser());
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Recovered-Audit-Password-2026!',
            'password_confirmation' => 'Recovered-Audit-Password-2026!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('Recovered-Audit-Password-2026!', $user->fresh()->password));
        $this->assertNotSame($attackerSession, app('session.store')->getId());

        // Negative control: without a signed-in cookie the protected page refuses access.
        $this->newBrowser();
        $this->get('/profile')->assertRedirect(route('login'));

        // Reopen only the original persisted cookie, without actingAs or cached user.
        $this->newBrowser($attackerSession);
        $this->assertNull(Auth::guard('web')->getUser());
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertNull(Auth::id());
    }

    public function test_password_update_keeps_current_browser_and_revokes_other_browser(): void
    {
        $org = $this->makeOrganization('Password change');
        $user = $this->makeStaff($org, 'password-change@audit.test');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $otherSession = app('session.store')->getId();
        $this->newBrowser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $currentSession = app('session.store')->getId();
        $this->assertNotSame($otherSession, $currentSession);
        $this->newBrowser($currentSession);
        $this->put('/password', [
            'current_password' => 'password',
            'password' => 'Changed-Password-2026!',
            'password_confirmation' => 'Changed-Password-2026!',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->newBrowser($currentSession);
        $this->get('/profile')->assertOk();
        $this->newBrowser($otherSession);
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertNull(Auth::id());
    }

    public function test_legacy_session_without_password_binding_requires_fresh_login(): void
    {
        $org = $this->makeOrganization('Legacy session');
        $user = $this->makeStaff($org, 'legacy-session@audit.test');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $session = app('session.store');
        $sessionId = $session->getId();
        $session->forget('password_hash_web');
        $session->save();
        $this->newBrowser($sessionId);
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertNull(Auth::id());
        $this->newBrowser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $newSession = app('session.store')->getId();
        $this->newBrowser($newSession);
        $this->get('/profile')->assertOk();
    }

    public function test_remembered_login_is_accepted_then_revoked_after_password_recovery(): void
    {
        $org = $this->makeOrganization('Remembered login');
        $user = $this->makeStaff($org, 'remembered@audit.test');
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        $response->assertRedirect();
        $recallerName = Auth::guard('web')->getRecallerName();
        $recaller = $response->getCookie($recallerName)->getValue();
        $this->newBrowser();
        $this->withCookie($recallerName, $recaller)->get('/profile')->assertOk();
        $this->assertSame($user->id, Auth::id());

        $token = Password::createToken($user);
        $this->newBrowser();
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Recovered-Remembered-Password-2026!',
            'password_confirmation' => 'Recovered-Remembered-Password-2026!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->newBrowser();
        $this->withCookie($recallerName, $recaller)->get('/profile')->assertRedirect(route('login'));
        $this->assertNull(Auth::id());
    }
}
