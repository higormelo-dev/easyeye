<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('surgery_schedule.map.title') }} — {{ $date->format(__('surgery_operation.date_format')) }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10.5px; color: #1f2937; }
        .header { border-bottom: 2px solid #0d6efd; padding-bottom: 6px; margin-bottom: 10px; }
        .clinic { font-size: 13px; font-weight: bold; color: #0d6efd; }
        h1 { font-size: 16px; margin: 4px 0 0; }
        h2 { font-size: 12px; margin: 14px 0 4px; background: #f3f4f6; padding: 4px 6px; border-left: 4px solid #0d6efd; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        th { text-align: left; background: #f9fafb; font-size: 9px; text-transform: uppercase; color: #6b7280; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 5px; vertical-align: top; }
        .time { white-space: nowrap; font-weight: bold; }
        .alert { color: #b91c1c; font-weight: bold; }
        .empty { padding: 20px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
@php
    $birth = fn ($case) => $case->patient?->person?->birth_date?->age;
    $allergy = fn ($case) => data_get($case->clinical_safety_snapshot, 'allergies_snapshot.state');
@endphp

<div class="header">
    <div class="clinic">{{ $entity->name }}</div>
    <h1>{{ __('surgery_schedule.map.title') }} — {{ $date->translatedFormat('l, ' . __('surgery_operation.date_format')) }}</h1>
    <div class="muted">
        @if($facility) {{ $facility->name }} · @endif
        {{ __('surgery_schedule.map.total', ['count' => $total]) }} ·
        {{ __('surgery_schedule.map.generated', ['date' => $generatedAt->format(__('surgery_operation.date_format') . ' H:i')]) }}
    </div>
</div>

@if($total === 0)
    <p class="empty">{{ __('surgery_schedule.map.empty') }}</p>
@endif

@foreach($groups as $room => $cases)
    <h2>{{ $room !== '' ? $room : __('surgery_schedule.agenda.no_room') }}</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 9%">{{ __('surgery_schedule.map.time') }}</th>
                <th style="width: 20%">{{ __('surgery_schedule.map.patient') }}</th>
                <th style="width: 24%">{{ __('surgery_schedule.map.procedure') }}</th>
                <th style="width: 6%">{{ __('surgery_schedule.map.eye') }}</th>
                <th style="width: 16%">{{ __('surgery_schedule.map.team') }}</th>
                <th style="width: 25%">{{ __('surgery_schedule.map.notes') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($cases as $case)
            <tr>
                <td class="time">{{ $case->scheduled_start->format('H:i') }}–{{ $case->scheduled_end->format('H:i') }}</td>
                <td>
                    {{ $case->patient?->person?->full_name }}
                    @if($birth($case) !== null)<span class="muted">({{ __('surgery_schedule.map.years', ['n' => $birth($case)]) }})</span>@endif
                    <div class="muted">{{ $case->code }}@if($case->covenant) · {{ $case->covenant->name }}@endif</div>
                </td>
                <td>
                    {{ $case->title }}
                    @if($case->anesthesia)<div class="muted">{{ __('surgery_schedule.map.anesthesia') }}: {{ $case->anesthesia->label() }}</div>@endif
                </td>
                <td>{{ $case->eye->short() }}</td>
                <td>
                    <strong>{{ $case->surgeon?->person?->full_name }}</strong>
                    @foreach($case->team as $member)
                        <div class="muted">{{ $member->role->label() }}: {{ $member->name }}</div>
                    @endforeach
                </td>
                <td>
                    @if($allergy($case) === 'has')<div class="alert">{{ __('surgery_schedule.map.allergy') }}</div>
                    @elseif($allergy($case) !== 'denied')<div class="alert">{{ __('surgery_schedule.map.allergy_unknown') }}</div>@endif
                    @foreach($case->iolPlans as $plan)
                        <div>{{ __('surgery_schedule.map.iol') }} {{ $plan->eye->short() }}: {{ $plan->model_label }} ({{ \Illuminate\Support\Number::format((float) $plan->power, 2, null, str_replace('_', '-', app()->getLocale())) }} D)</div>
                    @endforeach
                    @if($case->status->value === 'performed')<div class="muted">{{ $case->status->label() }}</div>@endif
                    @if($case->notes)<div>{{ $case->notes }}</div>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endforeach
</body>
</html>
