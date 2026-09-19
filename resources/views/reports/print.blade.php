@php
    use App\Domain\Reports\Data\ReportColumn;
    use App\Domain\Reports\Data\ReportRow;

    /** @var \App\Domain\Reports\Data\ReportTable $table */
    /** @var string|null $organizationName */

    $money = static function (?string $value): string {
        if ($value === null || $value === '') {
            return '';
        }

        // Formatted for reading, at the very last step, after every figure
        // has already been decided in PHP at full decimal precision.
        return number_format((float) $value, 2, '.', ',');
    };
@endphp
    <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $table->title }} — {{ $table->period->label }}</title>

    {{--
        Self-contained on purpose. A print view that depends on the
        application's stylesheet bundle prints differently the day the bundle
        changes, and a printed financial statement should look the same in
        five years as it does today.
    --}}
    <style>
        :root {
            --ink: #14161a;
            --muted: #5d636e;
            --rule: #d7dae0;
            --rule-strong: #9aa0aa;
        }

        * { box-sizing: border-box; }

        html {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 11pt;
            color: var(--ink);
            background: #fff;
        }

        body { margin: 0; padding: 24px; }

        header { margin-bottom: 20px; }

        .org {
            font-size: 10pt;
            color: var(--muted);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        h1 { font-size: 18pt; margin: 2px 0 4px; font-weight: 600; }

        .period { color: var(--muted); font-size: 10pt; }

        .reconciliation {
            margin-top: 10px;
            padding: 8px 10px;
            border-left: 3px solid var(--rule-strong);
            font-size: 9.5pt;
            color: var(--muted);
            page-break-inside: avoid;
        }

        .reconciliation.fails {
            border-left-color: #b42318;
            color: #b42318;
            font-weight: 600;
        }

        table { width: 100%; border-collapse: collapse; }

        thead th {
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            text-align: right;
            padding: 6px 8px;
            border-bottom: 1px solid var(--rule-strong);
        }

        thead th:first-child { text-align: left; }

        td {
            padding: 4px 8px;
            border-bottom: 1px solid var(--rule);
            text-align: right;
            /* Digits line up column to column. On a statement this is the
               difference between a figure a reader can scan and one they
               have to check. */
            font-variant-numeric: tabular-nums;
        }

        td:first-child { text-align: left; }

        tr.heading td {
            font-weight: 600;
            padding-top: 14px;
            border-bottom: 1px solid var(--rule-strong);
        }

        tr.subtotal td { font-weight: 600; }

        tr.total td {
            font-weight: 700;
            border-top: 1px solid var(--rule-strong);
            border-bottom: 2px solid var(--rule-strong);
        }

        tr.spacer td { border: 0; height: 8px; }

        .code { color: var(--muted); font-size: 9pt; margin-right: 6px; }

        .negative { color: #b42318; }

        footer {
            margin-top: 18px;
            font-size: 9pt;
            color: var(--muted);
        }

        .print-hint { margin-top: 20px; }

        @media print {
            body { padding: 0; }

            /* Column headings repeat on every sheet: page four of a general
               ledger is unreadable without them. */
            thead { display: table-header-group; }

            tr { page-break-inside: avoid; }

            .print-hint { display: none; }

            @page { margin: 14mm; }
        }
    </style>
</head>
<body>
<header>
    @if ($organizationName !== null)
        <p class="org">{{ $organizationName }}</p>
    @endif

    <h1>{{ $table->title }}</h1>

    <p class="period">
        {{ $table->period->label }}
        @if ($table->period->fromDate() !== $table->period->toDate())
            · {{ $table->period->fromDate() }} to {{ $table->period->toDate() }}
        @endif
        · amounts in {{ $table->currency }}
    </p>

    @if ($table->reconciliation !== null)
        <p class="reconciliation {{ $table->reconciles ? '' : 'fails' }}">
            {{ $table->reconciliation }}
        </p>
    @endif
</header>

<table>
    <thead>
    <tr>
        @foreach ($table->columns as $column)
            <th scope="col">{{ $column->label }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach ($table->flatten() as $row)
        <tr class="{{ $row->style }}">
            @foreach ($table->columns as $index => $column)
                @if ($index === 0)
                    <td style="padding-left: {{ 8 + $row->depth * 14 }}px">
                        @if ($row->code !== null && $row->style === 'row')
                            <span class="code">{{ $row->code }}</span>
                        @endif
                        {{ $row->label }}
                    </td>
                @else
                    @php $value = $row->values[$column->key] ?? null; @endphp
                    <td class="{{ $value !== null && str_starts_with($value, '-') ? 'negative' : '' }}">
                        {{ $column->isNumeric() ? $money($value) : ($value ?? '') }}
                    </td>
                @endif
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>

<footer>
    @foreach ($table->notes as $note)
        <p>{{ $note }}</p>
    @endforeach

    <p>Generated {{ now()->format('j M Y, H:i') }}.</p>

    <p class="print-hint">
        Use your browser's print dialogue to print this, or to save it as a PDF.
    </p>
</footer>
</body>
</html>
