@php
    // Idioma do usuário (lang financial_reports), como a tela e o CSV/XLSX:
    // moeda igual ao Intl.NumberFormat do front, datas no formato do locale.
    $locale = app()->getLocale();
    $money  = fn ($value): string => \Illuminate\Support\Number::currency((float) $value, 'BRL', $locale);
    $date   = fn ($value): string => \Carbon\Carbon::parse($value)->locale($locale)->isoFormat('L');
    $t      = fn (string $key, array $replace = []): string => __("financial_reports.{$key}", $replace);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $t('cashflow.title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1f2937;
        }
        .header {
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 16px;
            padding-bottom: 10px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
            margin-bottom: 4px;
        }
        .meta {
            font-size: 10px;
            color: #6b7280;
        }
        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .summary th,
        .summary td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
        }
        .summary th {
            background: #f3f4f6;
        }
        table.table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th,
        .table td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            vertical-align: top;
        }
        .table th {
            background: #f3f4f6;
            font-weight: 700;
        }
        .text-end { text-align: right; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ $t('cashflow.title') }}</div>
        <div class="meta">
            {{ $t('cashflow.pdf.clinic', ['name' => $entity->name]) }}<br>
            {{ $t('cashflow.pdf.period', ['from' => $date($from), 'to' => $date($to)]) }}<br>
            {{ $t('cashflow.pdf.generated_at', ['datetime' => $generatedAt->locale($locale)->isoFormat('L LT')]) }}
        </div>
    </div>

    <table class="summary">
        <thead>
            <tr>
                <th>{{ $t('cashflow.pdf.total_income') }}</th>
                <th>{{ $t('cashflow.pdf.total_expense') }}</th>
                <th>{{ $t('cashflow.pdf.period_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $money($summary['income']) }}</td>
                <td>{{ $money($summary['expense']) }}</td>
                <td>{{ $money($summary['balance']) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>{{ $t('cashflow.col_date') }}</th>
                <th>{{ $t('cashflow.col_code') }}</th>
                <th>{{ $t('cashflow.col_description') }}</th>
                <th>{{ $t('cashflow.col_category') }}</th>
                <th>{{ $t('cashflow.col_covenant') }}</th>
                <th>{{ $t('cashflow.col_type') }}</th>
                <th>{{ $t('cashflow.col_status') }}</th>
                <th class="text-end">{{ $t('cashflow.col_value') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td>{{ $entry->entry_date ? $date($entry->entry_date) : '' }}</td>
                    <td>{{ $entry->code }}</td>
                    <td>{{ $entry->description }}</td>
                    <td>{{ $entry->category?->name ?? $t('no_category') }}</td>
                    <td>{{ $entry->covenant?->name ?? $t('no_covenant') }}</td>
                    <td>{{ $entry->type->label() }}</td>
                    <td>{{ $entry->status->label() }}</td>
                    <td class="text-end">{{ $money($entry->amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="muted">{{ $t('cashflow.no_entries') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
