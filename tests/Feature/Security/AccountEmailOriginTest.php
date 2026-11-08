<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Mail\PortalPasswordResetEmail;
use App\Notifications\AccountInvitation;
use App\Services\Portal\PortalInvitationService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

class AccountEmailOriginTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public static function applicationUrls(): array
    {
        return [
            'root install' => ['https://inventory.example.test'],
            'subdirectory install' => ['https://inventory.example.test/wms'],
        ];
    }

    #[DataProvider('applicationUrls')]
    public function test_staff_reset_email_uses_the_configured_application_origin(string $applicationUrl): void
    {
        config(['app.url' => $applicationUrl]);
        Notification::fake();
        $staff = $this->makeStaff($this->makeOrganization('Origin Staff'), 'staff@example.test');

        $this->post('https://attacker.example/forgot-password', ['email' => $staff->email])
            ->assertRedirect();

        Notification::assertSentTo($staff, ResetPassword::class, function (ResetPassword $notification) use ($staff, $applicationUrl) {
            $this->assertSame(
                $applicationUrl.'/reset-password/'.$notification->token.'?email=staff%40example.test',
                $notification->toMail($staff)->actionUrl,
            );

            return true;
        });
    }

    #[DataProvider('applicationUrls')]
    public function test_portal_reset_email_uses_the_configured_application_origin(string $applicationUrl): void
    {
        config(['app.url' => $applicationUrl]);
        Mail::fake();
        $organization = $this->makeOrganization('Origin Portal');
        $contact = $this->makeContact($this->makeCustomer($organization, 'Buyer'), 'buyer@example.test');

        $this->post('https://attacker.example/portal/'.$organization->slug.'/forgot-password', ['email' => $contact->email])
            ->assertRedirect();

        Mail::assertQueued(PortalPasswordResetEmail::class, function (PortalPasswordResetEmail $mail) use ($organization, $applicationUrl) {
            $this->assertSame(
                $applicationUrl.'/portal/'.$organization->slug.'/reset-password/'.$mail->token.'?email=buyer%40example.test',
                $mail->resetUrl,
            );

            return true;
        });
    }

    #[DataProvider('applicationUrls')]
    public function test_account_invitations_use_the_configured_origin(string $applicationUrl): void
    {
        config(['app.url' => $applicationUrl]);
        $organization = $this->makeOrganization('Origin Invitations');
        $staff = $this->makeStaff($organization, 'staff@example.test');
        $this->get('https://attacker.example/login')->assertOk();

        $mail = (new AccountInvitation('invitation-token', $organization->name))->toMail($staff);

        $this->assertSame($applicationUrl.'/reset-password/invitation-token?email=staff%40example.test', $mail->actionUrl);
    }

    #[DataProvider('applicationUrls')]
    public function test_portal_invitation_signature_covers_the_configured_origin(string $applicationUrl): void
    {
        config(['app.url' => $applicationUrl]);
        $organization = $this->makeOrganization('Signed Origin');
        $contact = $this->makeContact($this->makeCustomer($organization, 'Buyer'), 'buyer@example.test', password: '');
        $this->get('https://attacker.example/login')->assertOk();

        $url = app(PortalInvitationService::class)->acceptUrl($contact);

        $this->assertStringStartsWith($applicationUrl.'/portal/'.$organization->slug.'/invitation/'.$contact->id.'?', $url);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        $this->assertFalse(URL::hasValidSignature(Request::create(str_replace('inventory.example.test', 'attacker.example', $url))));
    }
}
