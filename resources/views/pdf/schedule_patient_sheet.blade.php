@php
    // Ficha do paciente a partir do agendamento — idioma do usuário (lang schedules).
    $locale = app()->getLocale();
    $t      = fn (string $key, array $replace = []): string => __("schedules.{$key}", $replace);
    $value  = fn ($v): string => filled($v) ? (string) $v : '—';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $t('sheet_title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #1f2937; }
        .header { border-bottom: 2px solid #0d6efd; margin-bottom: 14px; padding-bottom: 8px; }
        .title { font-size: 17px; font-weight: bold; color: #0d6efd; }
        .meta { font-size: 9px; color: #6b7280; margin-top: 2px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #374151; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin: 14px 0 6px; }
        table.grid { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { padding: 4px 6px; vertical-align: top; text-align: left; border-bottom: 1px solid #f3f4f6; }
        .grid th { width: 26%; color: #6b7280; font-weight: 600; }
        .tag { display: inline-block; border: 1px solid #b45309; color: #92400e; border-radius: 3px; padding: 0 4px; font-size: 9px; font-weight: bold; }
        .notes { white-space: pre-wrap; }
        .footer { margin-top: 16px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ $t('sheet_title') }} — {{ $patient['name'] }}</div>
        <div class="meta">{{ $entity->name }}</div>
    </div>

    <h2>{{ $t('sheet_section_identification') }}</h2>
    <table class="grid">
        <tr><th>{{ $t('sheet_name') }}</th><td>{{ $value($patient['name']) }}</td></tr>
        <tr><th>{{ $t('sheet_code') }}</th><td>{{ $value($patient['code']) }}</td></tr>
        <tr>
            <th>{{ $t('sheet_birth_date') }}</th>
            <td>
                {{ $value($patient['birth_date']) }}
                @if($patient['age'] !== null) ({{ trans_choice('schedules.print_age', $patient['age'], ['age' => $patient['age']]) }})@endif
            </td>
        </tr>
        <tr><th>{{ $t('sheet_gender') }}</th><td>{{ $value($patient['gender']) }}</td></tr>
        <tr><th>{{ $t('sheet_cpf') }}</th><td>{{ $value($patient['cpf']) }}</td></tr>
        <tr><th>{{ $t('sheet_rg') }}</th><td>{{ $value($patient['rg']) }}</td></tr>
        <tr><th>{{ $t('sheet_mother') }}</th><td>{{ $value($patient['mother']) }}</td></tr>
    </table>

    <h2>{{ $t('sheet_section_contact') }}</h2>
    <table class="grid">
        <tr><th>{{ $t('drawer_label_cellphone') }}</th><td>{{ $value($patient['cellphone']) }}</td></tr>
        <tr><th>{{ $t('drawer_label_telephone') }}</th><td>{{ $value($patient['telephone']) }}</td></tr>
        <tr><th>{{ $t('sheet_email') }}</th><td>{{ $value($patient['email']) }}</td></tr>
        <tr><th>{{ $t('sheet_address') }}</th><td>{{ $value($patient['address']) }}</td></tr>
    </table>

    <h2>{{ $t('sheet_section_covenant') }}</h2>
    <table class="grid">
        <tr><th>{{ $t('drawer_label_covenant') }}</th><td>{{ $value($patient['covenant']) }}</td></tr>
        <tr><th>{{ $t('sheet_plan') }}</th><td>{{ $value($patient['plan']) }}</td></tr>
        <tr><th>{{ $t('sheet_card_number') }}</th><td>{{ $value($patient['card_number']) }}</td></tr>
    </table>

    <h2>{{ $t('sheet_section_schedule') }}</h2>
    <table class="grid">
        <tr><th>{{ $t('drawer_label_code') }}</th><td>{{ $value($schedule['code']) }}</td></tr>
        <tr>
            <th>{{ $t('drawer_label_datetime') }}</th>
            <td>
                {{ ucfirst($schedule['date_time']) }}
                @if($schedule['fit_in']) <span class="tag">{{ $t('fit_in_badge') }}</span>@endif
                @if($schedule['urgent'] ?? false) <span class="tag">{{ $t('urgent_care_badge') }}</span>@endif
                @if($schedule['session']) <span class="tag">{{ $t('session_label', ['session' => $schedule['session']]) }}</span>@endif
            </td>
        </tr>
        <tr>
            <th>{{ $t('form_doctor') }}</th>
            <td>{{ $value($schedule['doctor']) }}@if($schedule['record']) — {{ $t('drawer_label_record') }} {{ $schedule['record'] }}@endif</td>
        </tr>
        <tr><th>{{ $t('drawer_label_covenant') }}</th><td>{{ $value($schedule['covenant']) }}</td></tr>
        <tr><th>{{ $t('form_visit_type') }}</th><td>{{ $value($schedule['visit']) }}</td></tr>
        <tr><th>{{ $t('form_specialty_area') }}</th><td>{{ $value($schedule['specialty']) }}</td></tr>
        <tr><th>{{ $t('drawer_label_situation') }}</th><td>{{ $schedule['situation'] }}</td></tr>
        <tr><th>{{ $t('print_col_room') }}</th><td>{{ $value($schedule['resources']) }}</td></tr>
        <tr><th>{{ $t('sheet_schedule_phone') }}</th><td>{{ $value($schedule['phone']) }}</td></tr>
        <tr><th>{{ $t('show_notes') }}</th><td class="notes">{{ $value($schedule['notes']) }}</td></tr>
    </table>

    <div class="footer">
        {{ $t('print_generated', ['datetime' => $generatedAt->copy()->locale($locale)->isoFormat('L LT'), 'user' => $printedBy ?? '—']) }}
        · {{ $t('print_confidential') }}
    </div>
</body>
</html>
