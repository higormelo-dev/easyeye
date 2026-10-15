<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $issue->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.5; }
        .header { border-bottom: 2px solid #0d6efd; padding-bottom: 8px; margin-bottom: 14px; }
        .clinic { font-size: 14px; font-weight: bold; color: #0d6efd; }
        .muted { color: #6b7280; font-size: 10px; }
        h1 { font-size: 16px; margin: 0 0 10px; text-align: center; text-transform: uppercase; letter-spacing: .4px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.meta td { border: 1px solid #d1d5db; padding: 5px 8px; vertical-align: top; width: 50%; }
        .label { display: block; font-size: 9px; text-transform: uppercase; color: #6b7280; }
        .content { margin: 8px 0 18px; }
        .content table { border-collapse: collapse; width: 100%; }
        .content td, .content th { border: 1px solid #d1d5db; padding: 4px 6px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.items th { background: #f3f4f6; text-align: left; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 5px 7px; }
        .signatures { margin-top: 46px; width: 100%; border-collapse: collapse; }
        .signatures td { width: 50%; text-align: center; padding: 0 14px; vertical-align: bottom; }
        .line { border-top: 1px solid #111827; padding-top: 4px; font-size: 10.5px; }
        .stamp { margin-top: 22px; font-size: 8.5px; color: #6b7280; word-break: break-all; }
    </style>
</head>
<body>
@php
    $clinic  = (array) ($header['clinic'] ?? []);
    $patient = (array) ($header['patient'] ?? []);
    $case    = (array) ($header['case'] ?? []);
    $birth   = ! empty($patient['birth_date']) ? \Illuminate\Support\Carbon::parse($patient['birth_date'])->locale(app()->getLocale())->isoFormat('L') : null;
    $eyeKey  = $case['eye'] ?? null;
@endphp

<div class="header">
    <div class="clinic">{{ $clinic['name'] ?? $entity->name }}</div>
    <div class="muted">
        @if(! empty($clinic['cnpj'])) CNPJ {{ $clinic['cnpj'] }} · @endif
        @if(! empty($clinic['cnes'])) CNES {{ $clinic['cnes'] }} · @endif
        {{ $clinic['address'] ?? '' }} @if(! empty($clinic['phone'])) · {{ $clinic['phone'] }} @endif
    </div>
</div>

<h1>{{ $issue->title }}</h1>

<table class="meta">
    <tr>
        <td><span class="label">{{ __('surgery.pdf.patient') }}</span>{{ $patient['name'] ?? '' }}@if($birth) · {{ $birth }}@endif</td>
        <td><span class="label">{{ __('surgery.pdf.case') }}</span>{{ $case['code'] ?? '' }} — {{ $case['title'] ?? '' }}@if($eyeKey) ({{ __('surgery_enums.surgery_eye.' . $eyeKey) }})@endif</td>
    </tr>
</table>

<div class="content">{!! $issue->content !!}</div>

@includeIf('pdf.partials.surgery_document_data', ['issue' => $issue, 'data' => $data])

<table class="signatures">
    <tr>
        <td><div class="line">{{ $patient['name'] ?? '' }}<br>{{ __('surgery.pdf.patient_or_guardian') }}</div></td>
        <td>
            @if($signer)
                <div class="line">{{ $signer['name'] ?? '' }}<br>{{ __('surgery.pdf.registry') }} {{ $signer['registry'] ?? '—' }}</div>
            @else
                <div class="line">{{ __('surgery.pdf.responsible') }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="stamp">
    {{ __('surgery.pdf.issued_at', ['date' => $issue->issued_at->copy()->locale(app()->getLocale())->isoFormat('L LT')]) }} ·
    {{ __('surgery.pdf.integrity') }}: {{ $issue->content_hash }}
</div>
</body>
</html>
