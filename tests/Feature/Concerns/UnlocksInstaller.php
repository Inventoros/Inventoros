<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

/**
 * The installer steps only answer a session that entered the setup token.
 */
trait UnlocksInstaller
{
    protected function unlockInstaller(string $token = 'testing-installer-setup-token'): void
    {
        config(['app.setup_token' => $token]);

        $this->postJson('/install/unlock', ['token' => $token])
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
