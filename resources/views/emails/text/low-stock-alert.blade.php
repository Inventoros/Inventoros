{!! $brandName !!}

Low Stock Alert

The following product is running low on stock and needs your attention:

Product: {!! $product->name ?? 'Unknown Product' !!}
SKU: {!! $product->sku ?? 'N/A' !!}
Current stock: {!! $product->stock ?? 0 !!}
Minimum stock: {!! $product->min_stock ?? 0 !!}

View product details: {!! $notification_url ?? '' !!}

Tip: Consider creating a purchase order to restock this product.
@include('emails.text.partials.footer')
