<?php

namespace Tests\Unit;

use App\Support\HookManager;
use Tests\TestCase;

class HookManagerTest extends TestCase
{
    protected HookManager $hooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooks = new HookManager();
    }

    // ========================================
    // ACTION TESTS
    // ========================================

    public function test_add_action_registers_callback(): void
    {
        $this->hooks->addAction('test_action', fn () => null);

        $this->assertTrue($this->hooks->hasAction('test_action'));
    }

    public function test_has_action_returns_false_for_unregistered(): void
    {
        $this->assertFalse($this->hooks->hasAction('nonexistent'));
    }

    public function test_do_action_executes_callback(): void
    {
        $called = false;
        $this->hooks->addAction('test_action', function () use (&$called) {
            $called = true;
        });

        $this->hooks->doAction('test_action');

        $this->assertTrue($called);
    }

    public function test_do_action_passes_arguments(): void
    {
        $received = null;
        $this->hooks->addAction('test_action', function ($arg) use (&$received) {
            $received = $arg;
        });

        $this->hooks->doAction('test_action', 'hello');

        $this->assertSame('hello', $received);
    }

    public function test_do_action_passes_multiple_arguments(): void
    {
        $received = [];
        $this->hooks->addAction('test_action', function ($a, $b, $c) use (&$received) {
            $received = [$a, $b, $c];
        });

        $this->hooks->doAction('test_action', 'one', 'two', 'three');

        $this->assertSame(['one', 'two', 'three'], $received);
    }

    public function test_do_action_respects_priority(): void
    {
        $order = [];

        $this->hooks->addAction('test_action', function () use (&$order) {
            $order[] = 'second';
        }, 20);

        $this->hooks->addAction('test_action', function () use (&$order) {
            $order[] = 'first';
        }, 5);

        $this->hooks->doAction('test_action');

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_do_action_with_same_priority_runs_in_registration_order(): void
    {
        $order = [];

        $this->hooks->addAction('test_action', function () use (&$order) {
            $order[] = 'a';
        }, 10);

        $this->hooks->addAction('test_action', function () use (&$order) {
            $order[] = 'b';
        }, 10);

        $this->hooks->doAction('test_action');

        $this->assertSame(['a', 'b'], $order);
    }

    public function test_do_action_does_nothing_for_unregistered(): void
    {
        // Should not throw
        $this->hooks->doAction('nonexistent');
        $this->assertTrue(true);
    }

    public function test_remove_action_removes_all_callbacks(): void
    {
        $this->hooks->addAction('test_action', fn () => null);
        $this->hooks->addAction('test_action', fn () => null);

        $this->hooks->removeAction('test_action');

        $this->assertFalse($this->hooks->hasAction('test_action'));
    }

    public function test_remove_action_removes_specific_callback(): void
    {
        $callbackA = function () {};
        $callbackB = function () {};

        $this->hooks->addAction('test_action', $callbackA);
        $this->hooks->addAction('test_action', $callbackB);

        $this->hooks->removeAction('test_action', $callbackA);

        // Action still has callback B
        $this->assertTrue($this->hooks->hasAction('test_action'));
    }

    public function test_remove_action_for_nonexistent_does_not_throw(): void
    {
        $this->hooks->removeAction('nonexistent');
        $this->assertTrue(true);
    }

    public function test_get_actions_returns_all_registered(): void
    {
        $this->hooks->addAction('action_a', fn () => null);
        $this->hooks->addAction('action_b', fn () => null);

        $actions = $this->hooks->getActions();

        $this->assertArrayHasKey('action_a', $actions);
        $this->assertArrayHasKey('action_b', $actions);
    }

    // ========================================
    // FILTER TESTS
    // ========================================

    public function test_add_filter_registers_callback(): void
    {
        $this->hooks->addFilter('test_filter', fn ($v) => $v);

        $this->assertTrue($this->hooks->hasFilter('test_filter'));
    }

    public function test_has_filter_returns_false_for_unregistered(): void
    {
        $this->assertFalse($this->hooks->hasFilter('nonexistent'));
    }

    public function test_apply_filters_returns_original_value_when_no_filters(): void
    {
        $result = $this->hooks->applyFilters('nonexistent', 'original');

        $this->assertSame('original', $result);
    }

    public function test_apply_filters_modifies_value(): void
    {
        $this->hooks->addFilter('test_filter', fn ($value) => $value . '_modified');

        $result = $this->hooks->applyFilters('test_filter', 'base');

        $this->assertSame('base_modified', $result);
    }

    public function test_apply_filters_chains_multiple_filters(): void
    {
        $this->hooks->addFilter('test_filter', fn ($v) => $v . '_a');
        $this->hooks->addFilter('test_filter', fn ($v) => $v . '_b');

        $result = $this->hooks->applyFilters('test_filter', 'start');

        $this->assertSame('start_a_b', $result);
    }

    public function test_apply_filters_respects_priority(): void
    {
        $this->hooks->addFilter('test_filter', fn ($v) => $v . '_low', 20);
        $this->hooks->addFilter('test_filter', fn ($v) => $v . '_high', 5);

        $result = $this->hooks->applyFilters('test_filter', 'start');

        $this->assertSame('start_high_low', $result);
    }

    public function test_apply_filters_passes_extra_arguments(): void
    {
        $this->hooks->addFilter('test_filter', function ($value, $extra) {
            return $value . '_' . $extra;
        });

        $result = $this->hooks->applyFilters('test_filter', 'base', 'extra');

        $this->assertSame('base_extra', $result);
    }

    public function test_remove_filter_removes_all_callbacks(): void
    {
        $this->hooks->addFilter('test_filter', fn ($v) => $v);
        $this->hooks->addFilter('test_filter', fn ($v) => $v);

        $this->hooks->removeFilter('test_filter');

        $this->assertFalse($this->hooks->hasFilter('test_filter'));
    }

    public function test_remove_filter_removes_specific_callback(): void
    {
        $callbackA = fn ($v) => $v . '_a';
        $callbackB = fn ($v) => $v . '_b';

        $this->hooks->addFilter('test_filter', $callbackA);
        $this->hooks->addFilter('test_filter', $callbackB);

        $this->hooks->removeFilter('test_filter', $callbackA);

        $this->assertTrue($this->hooks->hasFilter('test_filter'));
        $result = $this->hooks->applyFilters('test_filter', 'start');
        $this->assertSame('start_b', $result);
    }

    public function test_remove_filter_for_nonexistent_does_not_throw(): void
    {
        $this->hooks->removeFilter('nonexistent');
        $this->assertTrue(true);
    }

    public function test_get_filters_returns_all_registered(): void
    {
        $this->hooks->addFilter('filter_a', fn ($v) => $v);
        $this->hooks->addFilter('filter_b', fn ($v) => $v);

        $filters = $this->hooks->getFilters();

        $this->assertArrayHasKey('filter_a', $filters);
        $this->assertArrayHasKey('filter_b', $filters);
    }

    public function test_apply_filters_can_change_value_type(): void
    {
        $this->hooks->addFilter('test_filter', fn ($v) => (int) $v * 2);

        $result = $this->hooks->applyFilters('test_filter', '5');

        $this->assertSame(10, $result);
    }

    public function test_apply_filters_with_null_value(): void
    {
        $this->hooks->addFilter('test_filter', fn ($v) => $v ?? 'default');

        $result = $this->hooks->applyFilters('test_filter', null);

        $this->assertSame('default', $result);
    }
}
