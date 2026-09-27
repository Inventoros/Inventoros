{!! $brandName !!}

Purchase Order {!! $purchaseOrder->po_number !!}

Hello{!! $purchaseOrder->supplier?->contact_name ? ' '.$purchaseOrder->supplier->contact_name : '' !!},

{!! $brandName !!} has sent you purchase order {!! $purchaseOrder->po_number !!}. The full order is attached as a PDF.
@if($customMessage)

{!! $customMessage !!}
@endif

PO Number: {!! $purchaseOrder->po_number !!}
Order Date: {!! $purchaseOrder->order_date?->format('M j, Y') ?? '-' !!}
@if($purchaseOrder->expected_date)
Expected Delivery: {!! $purchaseOrder->expected_date->format('M j, Y') !!}
@endif
Items: {!! $purchaseOrder->items->count() !!}
Total: {!! number_format((float) $purchaseOrder->total, 2) !!} {!! $purchaseOrder->currency !!}

Please confirm receipt of this order and let us know of any changes to pricing or delivery.
@include('emails.text.partials.footer')
