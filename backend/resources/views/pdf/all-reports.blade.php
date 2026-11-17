<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }} statistics</title>
    <style>
        /* same page layout as report.blade.php */
        @page { margin: 32px 36px 48px; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10px; color: #1f2937; margin: 0; }
        header { border-bottom: 2px solid #16a34a; padding-bottom: 8px; margin-bottom: 6px; }
        h1 { font-size: 20px; color: #14532d; margin: 0 0 4px; }
        h2 { font-size: 14px; color: #14532d; margin: 18px 0 4px; border-bottom: 1px solid #bbf7d0; padding-bottom: 3px; }
        .meta { color: #6b7280; font-size: 9px; }
        .desc { color: #4b5563; margin: 0 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #f0fdf4; text-align: left; padding: 4px 5px; color: #14532d; }
        td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; }
        td.num { text-align: right; }
        tr { page-break-inside: avoid; }
        /* keeps a report's title together with the start of its table */
        h2, .desc { page-break-after: avoid; }
        .empty { color: #9ca3af; font-style: italic; }
        footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8.5px; color: #9ca3af; text-align: center; }
        .page-number:after { content: "Page " counter(page) " of " counter(pages); }
    </style>
</head>
<body>
<header>
    <h1>Full statistics report</h1>
    <div class="meta">
        {{ $scopeLabel }} · Generated on {{ $generatedAt->format('Y-m-d H:i') }} · {{ config('app.name') }} — Open Food Facts data (ODbL) + own data
    </div>
</header>

@foreach($reports as $report)
    <section>
        <h2>{{ $report['title'] }}</h2>
        <p class="desc">{{ $report['description'] }}</p>
        @if(count($report['rows']) === 0)
            <p class="empty">No data available.</p>
        @else
            <table>
                <thead><tr>@foreach($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
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
    </section>
@endforeach

<footer>{{ config('app.name') }} · <span class="page-number"></span></footer>
</body>
</html>