{!! $brandName !!}

Invoice {!! $order->invoice_number !!}

Hello{!! $order->customer_name ? ' '.$order->customer_name : '' !!},

Thank you for your order. Your invoice from {!! $brandName !!} for order #{!! $order->order_number !!} is attached as a PDF.
@if($customMessage)

{!! $customMessage !!}
@endif

Invoice Number: {!! $order->invoice_number !!}
Order Number: #{!! $order->order_number !!}
Invoice Date: {!! ($order->invoice_issued_at ?? now())->format('M j, Y') !!}
Total: {!! number_format((float) $order->total, 2) !!} {!! $order->currency !!}
@include('emails.text.partials.footer')
