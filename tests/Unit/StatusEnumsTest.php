<?php

namespace Tests\Unit;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\TrackingType;
use PHPUnit\Framework\TestCase;

class StatusEnumsTest extends TestCase
{
    public function test_order_status_values_match_existing_column_shape(): void
    {
        $this->assertSame(
            ['pending', 'processing', 'shipped', 'delivered', 'cancelled'],
            OrderStatus::values()
        );
    }

    public function test_order_approval_status_values_match_existing_column_shape(): void
    {
        $this->assertSame(
            ['pending', 'approved', 'rejected'],
            OrderApprovalStatus::values()
        );
    }

    public function test_tracking_type_values_match_existing_column_shape(): void
    {
        $this->assertSame(['none', 'batch', 'serial'], TrackingType::values());
    }

    public function test_options_returns_label_value_pairs(): void
    {
        $options = OrderStatus::options();

        $this->assertCount(5, $options);
        $this->assertSame(['value' => 'pending', 'label' => 'Pending'], $options[0]);
        $this->assertSame(['value' => 'cancelled', 'label' => 'Cancelled'], $options[4]);
    }

    public function test_enum_cases_are_comparable_to_string_values(): void
    {
        // Legacy callsites that do $order->status === 'pending' continue
        // to work once a column is cast to the enum, because backed-enum
        // cases serialise back to their string value via ->value.
        $this->assertSame('pending', OrderStatus::PENDING->value);
        $this->assertSame('approved', OrderApprovalStatus::APPROVED->value);
        $this->assertSame('serial', TrackingType::SERIAL->value);
    }
}
