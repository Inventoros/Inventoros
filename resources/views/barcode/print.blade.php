<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Barcode - {{ $product->name }}</title>
    <style>
        @media print {
            @page {
                size: letter;
                margin: 0.5in 0.15in;
            }
            html,
            body {
                width: auto !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                display: block !important;
                min-height: 0 !important;
                overflow: visible !important;
                background: white !important;
            }
            .no-print {
                display: none !important;
            }
            .barcode-label {
                width: 100% !important;
                height: 1in !important;
                border: 1px solid #d0d0d0 !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
                padding: 0.08in !important;
                background: white !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                display: flex !important;
                text-align: center !important;
                box-sizing: border-box !important;
                margin: 0 !important;
            }
            .barcode-grid {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                grid-auto-rows: 1in !important;
                gap: 0 !important;
                width: 100% !important;
                max-width: 8.2in !important;
                margin: 0 !important;
                padding: 0 !important;
                orphans: 1 !important;
                widows: 1 !important;
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
            border: 1px solid #e8e8e8;
            padding: 0.1in;
            box-sizing: border-box;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .barcode-grid {
            display: grid;
            grid-template-columns: repeat(3, 2.5in);
            grid-auto-rows: 1in;
            gap: 0;
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
    <button id="barcode-print-btn" class="print-button no-print">Print Barcode</button>

    <div class="barcode-grid">
        @for ($i = 0; $i < 30; $i++)
            <div class="barcode-label">
                <div class="product-name" title="{{ $product->name }}">{{ $product->name }}</div>
                <div class="product-sku">SKU: {{ $product->sku }}</div>
                <div class="barcode-container">
                    {!! $barcode !!}
                </div>
                <div class="barcode-number">{{ $code }}</div>
            </div>
        @endfor
    </div>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('barcode-print-btn')?.addEventListener('click', function () {
            window.print();
        });
    </script>
</body>
</html>
