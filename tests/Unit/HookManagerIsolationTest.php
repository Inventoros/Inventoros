<?php

namespace Tests\Unit;

use App\Support\HookManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class HookManagerIsolationTest extends TestCase
{
    public function test_one_throwing_action_does_not_prevent_others_from_running(): void
    {
        Log::spy();

        $hooks = new HookManager();
        $ran = [];

        $hooks->addAction('event', function () { throw new RuntimeException('boom'); }, 10);
        $hooks->addAction('event', function () use (&$ran) { $ran[] = 'second'; }, 20);
        $hooks->addAction('event', function () use (&$ran) { $ran[] = 'third'; }, 30);

        $hooks->doAction('event');

        $this->assertSame(['second', 'third'], $ran);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_throwing_filter_is_skipped_and_subsequent_filters_see_unchanged_value(): void
    {
        Log::spy();

        $hooks = new HookManager();

        $hooks->addFilter('value', fn ($v) => $v . '-A', 10);
        $hooks->addFilter('value', function ($v) { throw new RuntimeException('boom'); }, 20);
        $hooks->addFilter('value', fn ($v) => $v . '-C', 30);

        $result = $hooks->applyFilters('value', 'start');

        // A applied (start → start-A), B threw and was skipped (still start-A),
        // C applied (start-A → start-A-C).
        $this->assertSame('start-A-C', $result);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_throwing_callback_is_logged_with_tag_and_exception_class(): void
    {
        Log::spy();

        $hooks = new HookManager();
        $hooks->addAction('crash.tag', function () { throw new \LogicException('details'); });

        $hooks->doAction('crash.tag');

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return is_array($context)
                && ($context['tag'] ?? null) === 'crash.tag'
                && ($context['exception'] ?? null) === \LogicException::class
                && ($context['error'] ?? null) === 'details';
        });
    }
}
