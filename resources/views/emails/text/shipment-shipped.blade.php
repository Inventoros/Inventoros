{!! $brandName !!}

Your order is on its way

Hello{!! $order->customer_name ? ' '.$order->customer_name : '' !!},

{!! $brandName !!} has shipped items from order #{!! $order->order_number !!}.

@if($carrierLabel !== '')
Carrier: {!! $carrierLabel !!}
@endif
@if($shipment->tracking_number)
Tracking number: {!! $shipment->tracking_number !!}
@endif

In this shipment:
@foreach($shipment->items as $item)
- {!! $item->quantity !!} x {!! $item->orderItem?->product_name !!}
@endforeach
@if($shipment->tracking_url)

Track your package: {!! $shipment->tracking_url !!}
@endif

@include('emails.text.partials.footer')
