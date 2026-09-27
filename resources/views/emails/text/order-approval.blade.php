{!! $brandName !!}

Order {!! ucfirst($approvalStatusValue ?? 'pending') !!}

Your order #{!! $order->order_number ?? 'N/A' !!} has been {!! $approvalStatusValue ?? 'pending' !!} by {!! optional($order->approver)->name ?? 'System' !!}.
@if($order->approval_notes ?? '')

Notes: {!! $order->approval_notes !!}
@endif

Order number: #{!! $order->order_number ?? 'N/A' !!}
Customer: {!! $order->customer_name ?? 'Unknown' !!}
Total: {!! number_format((float) ($order->total ?? 0), 2) !!} {!! $order->currency ?? '' !!}

View order details: {!! $notification_url ?? '' !!}
@include('emails.text.partials.footer')
