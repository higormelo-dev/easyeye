<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('surgery_operation.pdf.description_title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.5; }
        .header { border-bottom: 2px solid #0d6efd; padding-bottom: 8px; margin-bottom: 14px; }
        .clinic { font-size: 14px; font-weight: bold; color: #0d6efd; }
        .muted { color: #6b7280; font-size: 10px; }
        h1 { font-size: 16px; margin: 0 0 10px; text-align: center; text-transform: uppercase; }
        h2 { font-size: 12px; margin: 14px 0 4px; text-transform: uppercase; color: #374151; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.meta td { border: 1px solid #d1d5db; padding: 5px 8px; vertical-align: top; width: 50%; }
        .label { display: block; font-size: 9px; text-transform: uppercase; color: #6b7280; }
        .block { white-space: pre-wrap; }
        .draft { border: 2px dashed #dc2626; color: #dc2626; text-align: center; font-weight: bold; padding: 6px; margin-bottom: 12px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; font-size: 10.5px; }
        table.items th { background: #f3f4f6; }
        .addendum { border-left: 3px solid #0d6efd; padding: 4px 10px; margin: 8px 0; }
        .signatures { margin-top: 40px; width: 100%; border-collapse: collapse; }
        .signatures td { text-align: center; padding: 0 14px; }
        .line { border-top: 1px solid #111827; padding-top: 4px; font-size: 10.5px; }
        .stamp { margin-top: 16px; font-size: 8.5px; color: #6b7280; word-break: break-all; }
    </style>
</head>
<body>
@php
    $clinic  = (array) ($header['clinic'] ?? []);
    $patient = (array) ($header['patient'] ?? []);
    $case    = (array) ($header['case'] ?? []);
    $surgeon = (array) ($header['surgeon'] ?? []);
    $dateFmt = (string) __('surgery_operation.date_format');
    $dateTimeFmt = $dateFmt . ' H:i';
    $birth   = ! empty($patient['birth_date']) ? \Illuminate\Support\Carbon::parse($patient['birth_date'])->format($dateFmt) : null;
@endphp

<div class="header">
    <div class="clinic">{{ $clinic['name'] ?? '' }}</div>
    <div class="muted">
        @if(! empty($clinic['cnpj'])) CNPJ {{ $clinic['cnpj'] }} · @endif
        @if(! empty($clinic['cnes'])) CNES {{ $clinic['cnes'] }} · @endif
        {{ $clinic['address'] ?? '' }}
    </div>
</div>

<h1>{{ __('surgery_operation.pdf.description_title') }}</h1>

@unless($description->isFinalized())
    <div class="draft">{{ __('surgery_operation.pdf.draft_banner') }}</div>
@endunless

<table class="meta">
    <tr>
        <td><span class="label">{{ __('surgery_operation.pdf.patient') }}</span>{{ $patient['name'] ?? '' }}@if($birth) · {{ $birth }}@endif</td>
        <td><span class="label">{{ __('surgery_operation.pdf.case') }}</span>{{ $case['code'] ?? '' }} — {{ $case['title'] ?? '' }}@if(! empty($case['eye'])) ({{ __('surgery_enums.surgery_eye.' . $case['eye']) }})@endif</td>
    </tr>
    <tr>
        <td><span class="label">{{ __('surgery_operation.pdf.surgeon') }}</span>{{ $surgeon['name'] ?? '' }} {{ $surgeon['registry'] ?? '' }}</td>
        <td><span class="label">{{ __('surgery_operation.pdf.facility') }}</span>{{ $case['facility'] ?? '—' }}@if(! empty($case['room'])) · {{ $case['room'] }}@endif</td>
    </tr>
    <tr>
        <td><span class="label">{{ __('surgery_operation.pdf.start') }}</span>{{ $description->performed_start_at?->format($dateTimeFmt) ?? '—' }}</td>
        <td><span class="label">{{ __('surgery_operation.pdf.end') }}</span>{{ $description->performed_end_at?->format($dateTimeFmt) ?? '—' }}</td>
    </tr>
    <tr>
        <td><span class="label">{{ __('surgery_operation.pdf.anesthesia') }}</span>{{ $description->anesthesia_used ? __('surgery_enums.anesthesia_type.' . $description->anesthesia_used->value) : '—' }}</td>
        <td>
            <span class="label">{{ __('surgery_operation.pdf.team') }}</span>
            @forelse((array) ($header['team'] ?? []) as $member)
                {{ __('surgery_enums.surgery_team_role.' . $member['role']) }}: {{ $member['name'] }}@if(! empty($member['registry'])) ({{ $member['registry'] }})@endif<br>
            @empty
                —
            @endforelse
        </td>
    </tr>
</table>

<h2>{{ __('surgery_operation.pdf.findings') }}</h2>
<div class="block">{{ $description->findings }}</div>

<h2>{{ __('surgery_operation.pdf.technique') }}</h2>
<div class="block">{{ $description->technique_performed }}</div>

<h2>{{ __('surgery_operation.pdf.complications') }}</h2>
<div class="block">{{ $description->no_complications ? __('surgery_operation.pdf.no_complications') : $description->complications }}</div>

@if(count((array) ($header['implants'] ?? [])))
    <h2>{{ __('surgery_operation.pdf.implants') }}</h2>
    <table class="items">
        <thead>
        <tr>
            <th>{{ __('surgery_operation.fields.product') }}</th>
            <th>{{ __('surgery_operation.fields.power') }}</th>
            <th>{{ __('surgery_operation.fields.lot') }}</th>
            <th>{{ __('surgery_operation.fields.serial') }}</th>
            <th>{{ __('surgery_operation.fields.expiry') }}</th>
            <th>{{ __('surgery_operation.fields.eye') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach((array) $header['implants'] as $imp)
            <tr>
                <td>{{ $imp['product_name'] }}@if(! empty($imp['manufacturer'])) — {{ $imp['manufacturer'] }}@endif</td>
                <td>{{ isset($imp['power']) ? number_format((float) $imp['power'], 2, (string) __('surgery_operation.decimal_separator'), '') : '—' }}</td>
                <td>{{ $imp['lot_number'] ?? '—' }}</td>
                <td>{{ $imp['serial_number'] ?? '—' }}</td>
                <td>{{ ! empty($imp['expiry_date']) ? \Illuminate\Support\Carbon::parse($imp['expiry_date'])->format($dateFmt) : '—' }}</td>
                <td>{{ __('surgery_enums.surgery_eye.' . $imp['eye']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if($description->relationLoaded('addenda') && $description->addenda->isNotEmpty())
    <h2>{{ __('surgery_operation.pdf.addenda') }}</h2>
    @foreach($description->addenda as $addendum)
        <div class="addendum">
            <div class="muted">{{ $addendum->created_at?->format($dateTimeFmt) }} · {{ $addendum->doctor_name }} {{ $addendum->doctor_registry }} · {{ $addendum->reason }}</div>
            <div class="block">{{ $addendum->text }}</div>
            <div class="muted">{{ substr($addendum->content_hash, 0, 16) }}</div>
        </div>
    @endforeach
@endif

<table class="signatures">
    <tr>
        <td><div class="line">{{ $surgeon['name'] ?? '' }}<br>{{ __('surgery_operation.pdf.registry') }} {{ $surgeon['registry'] ?? '—' }}</div></td>
    </tr>
</table>

@if($description->isFinalized())
    <div class="stamp">
        {{ __('surgery_operation.pdf.finalized_at', ['date' => $description->finalized_at?->format($dateTimeFmt)]) }} ·
        {{ __('surgery_operation.pdf.integrity') }}: {{ $description->content_hash }}
    </div>
@endif
</body>
</html>
