<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        // Match the password binding established by a real staff Login event.
        if (($guard ?? config('auth.defaults.guard')) === 'web') {
            $this->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword())]);
        }

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);
    }
}
