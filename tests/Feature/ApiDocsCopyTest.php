<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The API reference copy (config/scramble.php) is shown to users at
 * /docs/api and follows the UI copy rules: no em dashes.
 */
class ApiDocsCopyTest extends TestCase
{
    public function test_the_api_reference_copy_has_no_em_dash(): void
    {
        $copy = json_encode(config('scramble'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString("\u{2014}", (string) $copy);
    }
}
