<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Barcodes ({{ count($barcodes) }} items)</title>
    <style>
        @media print {
            @page {
                size: letter;
                margin: 0.5in;
            }
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .barcode-grid {
                padding: 0;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .header h1 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .print-button {
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .print-button:hover {
            background: #2563eb;
        }

        .close-button {
            padding: 10px 20px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .close-button:hover {
            background: #4b5563;
        }

        .barcode-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .barcode-label {
            width: 2.5in;
            height: 1in;
            background: white;
            border: 1px solid #ccc;
            padding: 0.1in;
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

        .empty-message {
            text-align: center;
            padding: 40px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header no-print">
        <h1>Print {{ count($barcodes) }} Barcode{{ count($barcodes) > 1 ? 's' : '' }}</h1>
        <div class="header-actions">
            <button class="print-button" onclick="window.print()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Print All
            </button>
            <button class="close-button" onclick="window.close()">Close</button>
        </div>
    </div>

    @if(count($barcodes) > 0)
        <div class="barcode-grid">
            @foreach($barcodes as $item)
                <div class="barcode-label">
                    <div class="product-name" title="{{ $item['product']->name }}">{{ $item['product']->name }}</div>
                    <div class="product-sku">SKU: {{ $item['product']->sku }}</div>
                    <div class="barcode-container">
                        {!! $item['barcode'] !!}
                    </div>
                    <div class="barcode-number">{{ $item['code'] }}</div>
                </div>
            @endforeach
        </div>
    @else
        <div class="empty-message">
            <p>No barcodes to print. Please select products with barcodes or SKUs.</p>
        </div>
    @endif
</body>
</html>
