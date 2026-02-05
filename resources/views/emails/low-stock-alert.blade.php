@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        ⚠️ Low Stock Alert
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        The following product is running low on stock and needs your attention:
    </p>

    <!-- Product Info Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <table width="100%">
                    <tr>
                        <td>
                            <strong style="color: #92400e; font-size: 18px; display: block; margin-bottom: 8px;">
                                {{ $product->name }}
                            </strong>
                            <span style="color: #78350f; font-size: 14px;">
                                SKU: {{ $product->sku }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top: 15px;">
                            <table width="100%">
                                <tr>
                                    <td width="50%">
                                        <span style="color: #78350f; font-size: 14px; display: block; margin-bottom: 5px;">
                                            Current Stock
                                        </span>
                                        <strong style="color: #dc2626; font-size: 24px;">
                                            {{ $product->stock }}
                                        </strong>
                                    </td>
                                    <td width="50%">
                                        <span style="color: #78350f; font-size: 14px; display: block; margin-bottom: 5px;">
                                            Minimum Stock
                                        </span>
                                        <strong style="color: #92400e; font-size: 24px;">
                                            {{ $product->min_stock }}
                                        </strong>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- HOOK: Additional actions --}}
    {!! apply_filters('email_additional_actions', '', 'low_stock', $product) !!}

    <!-- Action Button -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
        <tr>
            <td align="center">
                <a href="{{ $notification_url }}" style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                    View Product Details
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        💡 <strong>Tip:</strong> Consider creating a purchase order to restock this product.
    </p>
@endsection
