<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Markdown notifications (the account invitation) interpolate names that
 * admins and users choose. Those values must reach the email as text: a name
 * written as a Markdown link or raw HTML must not turn into a clickable link
 * or markup in a message sent from this installation.
 */
final class MarkdownMailInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_controlled_names_are_not_rendered_as_markdown_or_html(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'email' => 'acme@example.com']);
        $user = User::create([
            'name' => '[Verify your account](https://evil.test/login) <a href="https://evil.test/html">x</a>',
            'email' => 'pat@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);

        $notification = new AccountInvitation('token', '[Acme](javascript:alert(1)) <img src=x onerror=alert(1)>');

        $html = (string) $notification->toMail($user)->render();

        // No link or image element was produced from the names...
        $this->assertDoesNotMatchRegularExpression('/<a\b[^>]*href="https:\/\/evil\.test/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<a\b[^>]*>\s*Acme\s*<\/a>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<img\b/i', $html);
        // ...and the names are still shown, as text.
        $this->assertStringContainsString('[Verify your account](https://evil.test/login)', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }
}
