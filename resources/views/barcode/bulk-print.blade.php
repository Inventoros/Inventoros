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
                margin: 0.5in 0.15in;
            }
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .barcode-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 0;
                width: 100%;
                max-width: 8.2in;
                padding: 0;
                margin: 0;
                background: transparent;
                border-radius: 0;
                box-shadow: none;
                orphans: 1;
                widows: 1;
            }
            .barcode-label {
                width: 100%;
                height: 1in;
                break-inside: avoid;
                page-break-inside: avoid;
                border: 1px solid #d0d0d0 !important;
                background: white !important;
                padding: 0.08in !important;
                box-sizing: border-box !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                text-align: center !important;
                margin: 0 !important;
            }
            .product-name {
                font-size: 7pt !important;
                line-height: 1.1 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .product-sku {
                font-size: 6pt !important;
                line-height: 1.1 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .barcode-number {
                font-size: 6pt !important;
                line-height: 1.1 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .barcode-container {
                margin: 0 !important;
                padding: 0 !important;
            }
            .barcode-container svg {
                max-height: 0.35in !important;
                width: 100% !important;
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
            border: 1px solid #e8e8e8;
            padding: 0.1in;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            box-sizing: border-box;
        }

        .product-name {
            font-size: 8pt;
            font-weight: bold;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            line-height: 1;
        }

        .product-sku {
            font-size: 7pt;
            color: #666;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            line-height: 1;
        }

        .barcode-container {
            margin: 1px 0;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .barcode-container svg {
            max-width: 100%;
            max-height: 0.4in;
            height: auto;
        }

        .barcode-number {
            font-size: 7pt;
            font-weight: bold;
            margin-top: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            line-height: 1;
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
            <button id="bulk-print-btn" class="print-button">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Print All
            </button>
            <button id="bulk-close-btn" class="close-button">Close</button>
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

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('bulk-print-btn')?.addEventListener('click', function () {
            window.print();
        });
        document.getElementById('bulk-close-btn')?.addEventListener('click', function () {
            window.close();
        });
    </script>
</body>
</html>
