<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    {{-- The utility stylesheet is included for the on-screen preview only; the
         print rules below override it so the printed page is a clean table
         rather than a screenshot of the app. --}}
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4 portrait; margin: 12mm; }

        body {
            background: #f1f5f9;
            color: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 12px;
        }

        .sheet {
            max-width: 210mm;
            margin: 8mm auto;
            padding: 10mm;
            background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .12);
        }

        .no-print { margin-bottom: 6mm; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2mm 1.5mm; text-align: left; }
        thead th { border-bottom: 1px solid #cbd5e1; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        tbody tr { border-bottom: 1px solid #e2e8f0; }
        tfoot td { border-top: 2px solid #0f172a; font-weight: 700; }
        .num { text-align: right; white-space: nowrap; }

        h1 { font-size: 18px; margin: 0 0 1mm; }
        .meta { color: #475569; font-size: 11px; }
        .totals { margin-top: 6mm; margin-left: auto; width: 70mm; }
        .totals td { padding: 1.5mm; }
        .totals .grand td { border-top: 2px solid #0f172a; font-weight: 700; font-size: 14px; }

        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            .no-print { display: none !important; }
            thead { display: table-header-group; }  /* repeat headers on each page */
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="no-print">
            <button type="button" onclick="window.print()"
                    style="border:1px solid #0f172a;border-radius:6px;padding:8px 16px;font:inherit;font-weight:600;background:#fff;cursor:pointer">
                Print / save as PDF
            </button>
            <a href="{{ $backUrl }}" style="margin-left:12px;font-size:13px">Back to {{ $backLabel }}</a>
        </div>

        <header>
            <h1>{{ $businessName ?? config('app.name') }}</h1>
            <p class="meta">{{ $title }}</p>
            <p class="meta">{{ $subtitle }}</p>
            <p class="meta">Printed {{ now()->format('d M Y H:i') }} &middot; {{ auth()->user()?->name }}</p>
        </header>

        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th class="{{ $column['numeric'] ?? false ? 'num' : '' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $index => $column)
                            <td class="{{ $column['numeric'] ?? false ? 'num' : '' }}">
                                {!! $column['render']($row, $index) !!}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}" style="text-align:center;color:#64748b;padding:8mm 0">
                            Nothing to report for this period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @isset($totals)
            <table class="totals">
                @foreach ($totals as $label => $value)
                    <tr class="{{ $label === 'Net revenue' ? 'grand' : '' }}">
                        <td>{{ $label }}</td>
                        <td class="num">{{ $value }}</td>
                    </tr>
                @endforeach
            </table>
        @endisset
    </div>
</body>
</html>
