<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #333; line-height: 1.4; }
        .container { padding: 28px; }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 12px; margin-bottom: 16px; }
        .title { font-size: 20px; font-weight: bold; color: #1e40af; }
        .meta { font-size: 10px; color: #6b7280; margin-top: 4px; }
        .notes { margin-bottom: 12px; }
        .notes p { font-size: 10px; color: #374151; margin-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f3f4f6; text-align: left; font-weight: bold; padding: 5px 6px; border-bottom: 1px solid #d1d5db; font-size: 9px; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        td.num { text-align: right; }
        .truncated { margin-top: 10px; font-size: 10px; color: #92400e; }
        .empty { padding: 16px 0; color: #6b7280; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="title">{{ $title }}</div>
        <div class="meta">Generated {{ $generatedDate }}</div>
    </div>

    @if(! empty($notes))
        <div class="notes">
            @foreach($notes as $note)
                <p>{{ $note }}</p>
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        @if(is_int($cell) || is_float($cell))
                            <td class="num">{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>
                        @else
                            <td>{{ $cell }}</td>
                        @endif
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ max(1, count($headers)) }}">No rows.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($truncatedAt)
        <p class="truncated">Showing the first {{ $truncatedAt }} of {{ $totalRows }} rows. Export as CSV or Excel for the full data.</p>
    @endif
</div>
</body>
</html>
