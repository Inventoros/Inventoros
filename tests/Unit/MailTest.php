<?php

namespace Tests\Unit;

use App\Mail\LowStockEmail;
use App\Mail\OrderApprovalEmail;
use App\Mail\OrderStatusEmail;
use App\Mail\TestEmail;
use Tests\TestCase;

class MailTest extends TestCase
{
    // ========================================
    // LowStockEmail
    // ========================================

    public function test_low_stock_email_sets_subject_with_product_name(): void
    {
        $product = new \stdClass();
        $product->name = 'Widget Pro';

        $mail = new LowStockEmail(['product' => $product]);
        $mail->build();

        $this->assertSame('Low Stock Alert - Widget Pro', $mail->subject);
    }

    public function test_low_stock_email_handles_null_product(): void
    {
        $mail = new LowStockEmail(['product' => null]);
        $mail->build();

        $this->assertSame('Low Stock Alert - Unknown Product', $mail->subject);
    }

    public function test_low_stock_email_uses_correct_view(): void
    {
        $product = new \stdClass();
        $product->name = 'Test';

        $mail = new LowStockEmail(['product' => $product]);
        $built = $mail->build();

        $this->assertSame('emails.low-stock-alert', $built->view);
    }

    public function test_low_stock_email_stores_data(): void
    {
        $data = ['product' => null, 'notification_url' => '/products/1'];
        $mail = new LowStockEmail($data);

        $this->assertSame($data, $mail->data);
    }

    // ========================================
    // OrderStatusEmail
    // ========================================

    public function test_order_status_email_sets_subject_with_order_number(): void
    {
        $order = new \stdClass();
        $order->order_number = 'ORD-123';

        $mail = new OrderStatusEmail(['order' => $order]);
        $mail->build();

        $this->assertSame('Order Status Updated - #ORD-123', $mail->subject);
    }

    public function test_order_status_email_handles_null_order(): void
    {
        $mail = new OrderStatusEmail(['order' => null]);
        $mail->build();

        $this->assertSame('Order Status Updated - #N/A', $mail->subject);
    }

    public function test_order_status_email_uses_correct_view(): void
    {
        $order = new \stdClass();
        $order->order_number = 'ORD-1';

        $mail = new OrderStatusEmail(['order' => $order]);
        $built = $mail->build();

        $this->assertSame('emails.order-status', $built->view);
    }

    // ========================================
    // OrderApprovalEmail
    // ========================================

    public function test_order_approval_email_sets_subject_for_approved(): void
    {
        $order = new \stdClass();
        $order->approval_status = 'approved';
        $order->order_number = 'ORD-456';

        $mail = new OrderApprovalEmail(['order' => $order]);
        $mail->build();

        $this->assertSame('Order Approved - #ORD-456', $mail->subject);
    }

    public function test_order_approval_email_sets_subject_for_rejected(): void
    {
        $order = new \stdClass();
        $order->approval_status = 'rejected';
        $order->order_number = 'ORD-789';

        $mail = new OrderApprovalEmail(['order' => $order]);
        $mail->build();

        $this->assertSame('Order Rejected - #ORD-789', $mail->subject);
    }

    public function test_order_approval_email_handles_null_order(): void
    {
        $mail = new OrderApprovalEmail(['order' => null]);
        $mail->build();

        $this->assertSame('Order Pending - #N/A', $mail->subject);
    }

    public function test_order_approval_email_uses_correct_view(): void
    {
        $order = new \stdClass();
        $order->approval_status = 'approved';
        $order->order_number = 'ORD-1';

        $mail = new OrderApprovalEmail(['order' => $order]);
        $built = $mail->build();

        $this->assertSame('emails.order-approval', $built->view);
    }

    // ========================================
    // TestEmail
    // ========================================

    public function test_test_email_sets_correct_subject(): void
    {
        $mail = new TestEmail(['message' => 'Hello']);
        $mail->build();

        $this->assertSame('Test Email - Inventoros', $mail->subject);
    }

    public function test_test_email_uses_correct_view(): void
    {
        $mail = new TestEmail(['message' => 'Hello']);
        $built = $mail->build();

        $this->assertSame('emails.test-email', $built->view);
    }

    public function test_test_email_stores_data(): void
    {
        $data = ['message' => 'Test message', 'sent_by' => 'Admin'];
        $mail = new TestEmail($data);

        $this->assertSame($data, $mail->data);
    }
}
