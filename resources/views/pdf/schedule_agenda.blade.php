@php
    // Agenda impressa — idioma do usuário (lang schedules), datas no locale.
    $locale = app()->getLocale();
    $t      = fn (string $key, array $replace = []): string => __("schedules.{$key}", $replace);
    $cols   = 9 + ($withDoctor ? 1 : 0) + ($withPhones ? 1 : 0);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $t('print_agenda_title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #1f2937; }
        .header { border-bottom: 2px solid #0d6efd; margin-bottom: 10px; padding-bottom: 8px; }
        .title { font-size: 16px; font-weight: bold; color: #0d6efd; margin-bottom: 2px; }
        .subtitle { font-size: 12px; font-weight: bold; margin-bottom: 4px; }
        .meta { font-size: 9px; color: #6b7280; }
        table.table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border: 1px solid #e5e7eb; padding: 4px 5px; vertical-align: top; text-align: left; }
        .table th { background: #f3f4f6; font-weight: 700; font-size: 9px; text-transform: uppercase; }
        .table tr { page-break-inside: avoid; }
        .nowrap { white-space: nowrap; }
        .muted { color: #6b7280; }
        .tag { display: inline-block; border: 1px solid #b45309; color: #92400e; border-radius: 3px; padding: 0 3px; font-size: 8px; font-weight: bold; }
        .tag-session { border-color: #1d4ed8; color: #1e40af; }
        .tag-urgent { border-color: #b91c1c; color: #991b1b; }
        tr.row-other td { background: #f9fafb; color: #4b5563; font-style: italic; }
        .footer { margin-top: 10px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ $entity->name }} — {{ $t('print_agenda_title') }}</div>
        <div class="subtitle">{{ ucfirst($heading['date']) }}</div>
        <div class="meta">
            {{ $t('print_doctor') }}: <strong>{{ $heading['doctor'] }}</strong>
            &nbsp;·&nbsp; {{ $t('print_shift') }}: <strong>{{ $heading['shift'] }}</strong>
            &nbsp;·&nbsp; {{ trans_choice('schedules.print_total', $total, ['count' => $total]) }}
            @foreach($filterLines as $line)
                &nbsp;·&nbsp; {{ $line }}
            @endforeach
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th class="nowrap">{{ $t('print_col_time') }}</th>
                <th>{{ $t('print_col_patient') }}</th>
                <th class="nowrap">{{ $t('print_col_age') }}</th>
                <th>{{ $t('print_col_covenant') }}</th>
                <th>{{ $t('print_col_type') }}</th>
                <th>{{ $t('print_col_situation') }}</th>
                <th>{{ $t('print_col_fit_in') }}</th>
                <th>{{ $t('print_col_session') }}</th>
                <th>{{ $t('print_col_room') }}</th>
                @if($withDoctor)
                    <th>{{ $t('print_col_doctor') }}</th>
                @endif
                @if($withPhones)
                    <th class="nowrap">{{ $t('print_col_phone') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                @if($row['kind'] === 'schedule')
                    <tr>
                        <td class="nowrap"><strong>{{ $row['time'] }}</strong></td>
                        <td>
                            {{ $row['name'] }}
                            @if($row['urgent'] ?? false) <span class="tag tag-urgent">{{ $t('urgent_care_badge') }}</span>@endif
                            @if($row['code'])<br><span class="muted">{{ $row['code'] }}</span>@endif
                        </td>
                        <td class="nowrap">{{ $row['age'] !== null ? trans_choice('schedules.print_age', $row['age'], ['age' => $row['age']]) : '—' }}</td>
                        <td>{{ $row['covenant'] ?? '—' }}</td>
                        <td>{{ $row['visit'] ?? '—' }}</td>
                        <td>{{ $row['situation'] }}</td>
                        <td>@if($row['fit_in'])<span class="tag">{{ $t('fit_in_badge') }}</span>@else — @endif</td>
                        <td>@if($row['session'])<span class="tag tag-session">{{ $row['session'] }}</span>@else — @endif</td>
                        <td>{{ $row['resources'] !== '' ? $row['resources'] : '—' }}</td>
                        @if($withDoctor)
                            <td>{{ $row['doctor'] ?? '—' }}</td>
                        @endif
                        @if($withPhones)
                            <td class="nowrap">{{ $row['phone'] ?? '—' }}</td>
                        @endif
                    </tr>
                @else
                    <tr class="row-other">
                        <td class="nowrap">{{ $row['time'] }}</td>
                        <td colspan="{{ $cols - 1 }}">
                            {{ $row['kind'] === 'block' ? $t('block_badge') : $t('event_badge') }}: {{ $row['title'] }}
                            @if($row['note']) — {{ $row['note'] }}@endif
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ $cols }}" class="muted">{{ $t('empty') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        {{ $t('print_generated', ['datetime' => $generatedAt->copy()->locale($locale)->isoFormat('L LT'), 'user' => $printedBy ?? '—']) }}
        · {{ $t('print_confidential') }}
    </div>
</body>
</html>
