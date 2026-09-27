{!! $brandName !!}

Order Status Updated

Order #{!! $order->order_number ?? 'N/A' !!} status has been updated.

Previous status: {!! ucfirst($oldStatusValue ?? 'unknown') !!}
New status: {!! ucfirst($statusValue ?? 'unknown') !!}

Customer: {!! $order->customer_name ?? 'Unknown' !!}
Order total: {!! number_format((float) ($order->total ?? 0), 2) !!} {!! $order->currency ?? '' !!}

View order details: {!! $notification_url ?? '' !!}
@include('emails.text.partials.footer')
