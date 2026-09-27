<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        @media print {
            @page {
                size: letter;
                margin: 0.5in;
            }
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .label-grid {
                padding: 0;
                box-shadow: none;
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
            flex-wrap: wrap;
            gap: 12px;
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
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .mode-picker {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
        }

        .mode-picker select,
        .mode-picker button {
            padding: 6px 10px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            font-size: 13px;
            background: white;
        }

        .mode-picker button {
            cursor: pointer;
        }

        .print-button {
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
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

        .label-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, 2.5in);
            gap: 10px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .qr-label {
            width: 2.5in;
            height: 1.25in;
            border: 1px solid #ccc;
            padding: 0.08in;
            display: flex;
            align-items: center;
            gap: 0.08in;
            break-inside: avoid;
        }

        .qr-image svg {
            width: 1.05in;
            height: 1.05in;
            display: block;
        }

        .qr-text {
            min-width: 0;
            flex: 1;
        }

        .qr-title {
            font-size: 9pt;
            font-weight: bold;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .qr-subtitle {
            font-size: 7pt;
            color: #555;
            margin-top: 3px;
        }

        .qr-payload {
            font-family: monospace;
            font-size: 6.5pt;
            color: #333;
            margin-top: 4px;
            word-break: break-all;
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
        <h1>{{ $title }}</h1>
        <div class="header-actions">
            @if($modeAction)
                <form method="GET" action="{{ $modeAction }}" class="mode-picker">
                    @if($ids)
                        <input type="hidden" name="ids" value="{{ $ids }}">
                    @endif
                    <label for="qr-mode">QR contains</label>
                    <select id="qr-mode" name="mode">
                        <option value="sku" @selected($mode === 'sku')>SKU</option>
                        <option value="url" @selected($mode === 'url')>Link to product page</option>
                    </select>
                    <button type="submit">Apply</button>
                </form>
            @endif
            <button id="qr-print-btn" class="print-button">Print</button>
            <button id="qr-close-btn" class="close-button">Close</button>
        </div>
    </div>

    @if(count($labels) > 0)
        <div class="label-grid">
            @foreach($labels as $label)
                <div class="qr-label">
                    <div class="qr-image">{!! $label['svg'] !!}</div>
                    <div class="qr-text">
                        <div class="qr-title" title="{{ $label['title'] }}">{{ $label['title'] }}</div>
                        @if($label['subtitle'] !== '')
                            <div class="qr-subtitle">{{ $label['subtitle'] }}</div>
                        @endif
                        <div class="qr-payload">{{ $label['payload'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="empty-message">
            <p>Nothing to print.</p>
        </div>
    @endif

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('qr-print-btn')?.addEventListener('click', function () {
            window.print();
        });
        document.getElementById('qr-close-btn')?.addEventListener('click', function () {
            window.close();
        });
    </script>
</body>
</html>
