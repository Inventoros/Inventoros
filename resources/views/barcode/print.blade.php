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
        }

        .print-button:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">=¨ Print Barcode</button>

    <div class="barcode-label">
        <div class="product-name" title="{{ $product->name }}">{{ $product->name }}</div>
        <div class="product-sku">SKU: {{ $product->sku }}</div>
        <div class="barcode-container">
            {!! $barcode !!}
        </div>
        <div class="barcode-number">{{ $code }}</div>
    </div>

    <script>
        // Auto-print on load (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
