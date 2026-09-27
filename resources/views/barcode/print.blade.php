<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Barcode - {{ $product->name }}</title>
    <style>
        @media print {
            @page {
                size: 2.5in 1in;
                margin: 0;
            }
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #f5f5f5;
        }

        .barcode-label {
            width: 2.5in;
            height: 1in;
            background: white;
            border: 1px solid #ccc;
            padding: 0.1in;
            box-sizing: border-box;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .product-name {
            font-size: 8pt;
            font-weight: bold;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }

        .product-sku {
            font-size: 7pt;
            color: #666;
            margin-bottom: 4px;
        }

        .barcode-container {
            margin: 2px 0;
        }

        .barcode-container svg {
            max-width: 100%;
            height: auto;
        }

        .barcode-number {
            font-size: 8pt;
            font-weight: bold;
            margin-top: 2px;
        }

        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .print-button:hover {
            background: #2563eb;
        }

        .single-picker {
            position: fixed;
            top: 20px;
            left: 20px;
        }

        .type-picker {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
        }

        .type-picker select {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font-size: 13px;
            background: white;
        }

        .type-picker button {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            background: white;
            cursor: pointer;
            font-size: 13px;
        }

        .barcode-type {
            font-size: 6pt;
            color: #666;
            margin-top: 1px;
        }
    </style>
</head>
<body>
    <button id="barcode-print-btn" class="print-button no-print">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
        </svg>
        Print Barcode
    </button>

    <div class="barcode-label">
        <div class="product-name" title="{{ $product->name }}">{{ $product->name }}</div>
        <div class="product-sku">SKU: {{ $product->sku }}</div>
        <div class="barcode-container">
            {!! $barcode !!}
        </div>
        <div class="barcode-number">{{ $code }}</div>
        <div class="barcode-type">{{ $type->label() }}</div>
    </div>

    <div class="single-picker">
        <form method="GET" action="{{ route('products.barcode.print', $product) }}" class="type-picker no-print">
        <label for="barcode-type">Barcode type</label>
        <select id="barcode-type" name="type">
            <option value="auto" @selected($selectedType === 'auto')>Product default</option>
            @foreach($types as $option)
                <option value="{{ $option->value }}" @selected($selectedType === $option->value)>{{ $option->label() }}</option>
            @endforeach
        </select>
        <button type="submit">Apply</button>
    </form>
    </div>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('barcode-print-btn')?.addEventListener('click', function () {
            window.print();
        });
    </script>
</body>
</html>
