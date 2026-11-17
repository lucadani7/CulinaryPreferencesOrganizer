<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    <style>
        /* page margins leave room at the bottom for the fixed footer */
        @page { margin: 32px 36px 48px; }
        /* DejaVu Sans ships with dompdf and covers extended Latin characters */
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10.5px; color: #1f2937; margin: 0; }
        header { border-bottom: 2px solid #16a34a; padding-bottom: 8px; margin-bottom: 14px; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #14532d; }
        .meta { color: #6b7280; font-size: 9.5px; }
        .desc { margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f0fdf4; color: #14532d; text-align: left; padding: 6px; border-bottom: 1px solid #bbf7d0; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #fafafa; }
        td.num { text-align: right; }
        .summary { margin-top: 14px; }
        .summary td { border: none; padding: 2px 6px; }
        footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8.5px; color: #9ca3af; text-align: center; }
        .page-number:after { content: "Page " counter(page) " of " counter(pages); }
        tr { page-break-inside: avoid; }
        .empty { padding: 20px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
<header>
    <h1>{{ $report['title'] }}</h1>
    <div class="meta">
        {{ $scopeLabel }} · Generated on {{ $generatedAt->format('Y-m-d H:i') }} · {{ config('app.name') }} — Open Food Facts data (ODbL)
    </div>
</header>

@if(!empty($report['description']))
    <p class="desc">{{ $report['description'] }}</p>
@endif

@if(count($report['rows']) === 0)
    <div class="empty">No data available for this report.</div>
@else
    <table>
        <thead>
        <tr>
            @foreach($report['columns'] as $label)
                <th>{{ $label }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach($report['rows'] as $row)
            <tr>
                @foreach(array_keys($report['columns']) as $key)
                    @php $v = $row[$key] ?? null; @endphp
                    <td class="{{ is_numeric($v) ? 'num' : '' }}">
                        @if(is_bool($v)) {{ $v ? 'yes' : 'no' }}
                        @elseif(is_null($v)) —
                        @elseif(is_float($v)) {{ number_format($v, 2) }}
                        @else {{ $v }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if(!empty($extra))
    <table class="summary">
        @foreach($extra as $label => $value)
            <tr><td><strong>{{ $label }}</strong></td><td>{{ $value }}</td></tr>
        @endforeach
    </table>
@endif

<footer>{{ config('app.name') }} · <span class="page-number"></span></footer>
</body>
</html>