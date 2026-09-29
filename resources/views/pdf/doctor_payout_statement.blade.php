@php
    use Carbon\Carbon;
    use Illuminate\Support\Number;

    $payout = $statement['payout'];
    $money  = fn ($value) => Number::currency((float) $value, 'BRL', $locale);
    $date   = fn ($value) => $value ? Carbon::parse($value)->locale($locale)->isoFormat('L') : '—';
    $t      = fn (string $key, array $replace = []) => __("financial_doctor_payouts.{$key}", $replace);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $t('pdf_title') }} {{ $payout['code'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #1f2937; }
        .header { border-bottom: 2px solid #0d6efd; margin-bottom: 14px; padding-bottom: 8px; }
        .title { font-size: 17px; font-weight: bold; color: #0d6efd; margin-bottom: 3px; }
        .meta { font-size: 9px; color: #6b7280; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .parties td { border: 1px solid #d1d5db; padding: 6px 8px; vertical-align: top; width: 50%; }
        .label { font-weight: 700; color: #374151; display: block; margin-bottom: 2px; }
        h2 { font-size: 12px; margin: 14px 0 6px; color: #111827; }
        table.table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border: 1px solid #e5e7eb; padding: 4px 5px; vertical-align: top; }
        .table th { background: #f3f4f6; font-weight: 700; text-align: left; }
        .text-end { text-align: right; }
        .muted { color: #6b7280; }
        .subtotal td { font-weight: 700; background: #f9fafb; }
        .totals { width: 55%; margin-left: 45%; margin-top: 14px; border-collapse: collapse; }
        .totals td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .totals .net td { font-size: 12px; font-weight: 700; border-top: 2px solid #111827; }
        .notice { margin-top: 10px; padding: 6px 8px; border: 1px solid #fcd34d; background: #fffbeb; }
        .signature { margin-top: 50px; display: table; width: 100%; }
        .signature .line { display: table-cell; width: 50%; text-align: center; padding-top: 6px; border-top: 1px solid #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ $t('pdf_title') }} — {{ $payout['code'] }}</div>
        <div class="meta">
            {{ $entity->name ?? '' }}<br>
            {{ $t('pdf_generated_at', ['date' => $generatedAt->copy()->locale($locale)->isoFormat('L LT')]) }}
        </div>
    </div>

    <table class="parties">
        <tr>
            <td>
                <span class="label">{{ $t('statement_doctor') }}</span>
                {{ $payout['doctor_name'] }}
                @if($payout['doctor_record'])
                    <br><span class="muted">{{ $t('statement_record') }}: {{ $payout['doctor_record'] }}</span>
                @endif
            </td>
            <td>
                <span class="label">{{ $t('statement_period') }}</span>
                {{ $date($payout['period_start']) }} – {{ $date($payout['period_end']) }}<br>
                <span class="muted">{{ $t('statement_status') }}: {{ $t('statuses.' . $payout['status']) }}</span>
                @if($payout['paid_at'])
                    <br><span class="muted">{{ $t('payment_paid_on', ['date' => $date($payout['paid_at'])]) }}</span>
                @endif
            </td>
        </tr>
    </table>

    @foreach($statement['groups'] as $group)
        <h2>{{ $t('service_types_plural.' . $group['service_type']) }} ({{ $group['count'] }})</h2>
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 11%">{{ $t('col_date') }}</th>
                    <th style="width: 22%">{{ $t('col_patient') }}</th>
                    <th>{{ $t('col_service') }}</th>
                    <th style="width: 15%">{{ $t('col_payer') }}</th>
                    <th class="text-end" style="width: 11%">{{ $t('col_charged') }}</th>
                    <th style="width: 14%">{{ $t('col_rule') }}</th>
                    <th class="text-end" style="width: 11%">{{ $t('col_payout') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($group['items'] as $item)
                    <tr>
                        <td>{{ $date($item['date']) }}</td>
                        <td>{{ $item['patient_name'] ?? '—' }}@if($item['patient_code'])<br><span class="muted">{{ $item['patient_code'] }}</span>@endif</td>
                        <td>{{ $item['description'] }}</td>
                        <td>{{ $presenter->payerLabel($item) }}</td>
                        <td class="text-end">{{ $money($item['charged']) }}</td>
                        <td>{{ $presenter->ruleLabel($item['rule']) }}</td>
                        <td class="text-end">{{ $money($item['payout']) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td colspan="4">{{ $t('statement_subtotal') }}</td>
                    <td class="text-end">{{ $money($group['charged']) }}</td>
                    <td></td>
                    <td class="text-end">{{ $money($group['payout']) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    @if(count($statement['adjustments']) > 0)
        <h2>{{ $t('adjustments_title') }}</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>{{ $t('adjustment_description') }}</th>
                    <th class="text-end" style="width: 18%">{{ $t('adjustment_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($statement['adjustments'] as $adjustment)
                    <tr>
                        <td>{{ $adjustment['description'] }}</td>
                        <td class="text-end">{{ $money($adjustment['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr><td>{{ $t('statement_gross') }}</td><td class="text-end">{{ $money($payout['gross_amount']) }}</td></tr>
        <tr><td>{{ $t('statement_items_total') }}</td><td class="text-end">{{ $money($payout['items_amount']) }}</td></tr>
        <tr><td>{{ $t('statement_adjustments_total') }}</td><td class="text-end">{{ $money($payout['adjustments_amount']) }}</td></tr>
        <tr class="net"><td>{{ $t('statement_net_total') }}</td><td class="text-end">{{ $money($payout['total_amount']) }}</td></tr>
    </table>

    @if($payout['status'] === 'cancelled')
        <div class="notice">
            {{ $t('statement_cancelled', ['date' => $date($payout['cancelled_at']), 'user' => $payout['cancelled_by_name'] ?? '—', 'reason' => $payout['cancel_reason'] ?? '—']) }}
        </div>
    @endif

    @if($payout['notes'])
        <div class="notice"><span class="label">{{ $t('statement_notes') }}</span>{{ $payout['notes'] }}</div>
    @endif

    <div class="signature">
        <div class="line">{{ $t('pdf_signature') }}: {{ $payout['doctor_name'] }}</div>
        <div class="line">{{ $entity->name ?? '' }}</div>
    </div>
</body>
</html>
