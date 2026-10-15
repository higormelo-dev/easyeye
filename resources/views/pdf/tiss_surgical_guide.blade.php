<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('surgery_tiss.pdf.title.' . $guide->guide_type->value) }} {{ $guide->guide_number_provider }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.4; }
        h1 { font-size: 15px; margin: 0 0 2px; color: #0d6efd; }
        .sub { color: #6b7280; font-size: 9.5px; margin-bottom: 10px; }
        .section { border: 1px solid #d1d5db; margin-bottom: 8px; page-break-inside: avoid; }
        .section > .title { background: #f3f4f6; padding: 3px 7px; font-weight: bold; font-size: 9.5px; text-transform: uppercase; letter-spacing: .3px; }
        table.fields { width: 100%; border-collapse: collapse; }
        table.fields td { padding: 4px 7px; vertical-align: top; border-top: 1px solid #e5e7eb; }
        .label { display: block; font-size: 8px; text-transform: uppercase; color: #6b7280; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #f9fafb; text-align: left; font-size: 8.5px; text-transform: uppercase; color: #6b7280; }
        table.items th, table.items td { border-top: 1px solid #e5e7eb; padding: 4px 7px; }
        .text { padding: 5px 7px; border-top: 1px solid #e5e7eb; white-space: pre-line; }
        .stamp { margin-top: 10px; font-size: 8px; color: #6b7280; word-break: break-all; }
        .signature { margin-top: 38px; width: 55%; border-top: 1px solid #111827; padding-top: 3px; text-align: center; font-size: 9.5px; }
    </style>
</head>
<body>
@php
    $isOpme   = $guide->guide_type === \App\Domains\Tiss\Enums\TissGuideType::OpmeAttachment;
    // Data no formato do idioma do documento (pt_BR 03/11/2026, en 11/03/2026) — nunca d/m/Y fixo.
    $fmtDate  = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->locale(app()->getLocale())->isoFormat('L') : '—';
    $pro      = $data->professional;
    $v        = fn (?string $s) => filled($s) ? $s : '—';
@endphp

<h1>{{ __('surgery_tiss.pdf.title.' . $guide->guide_type->value) }}</h1>
<div class="sub">{{ __('surgery_tiss.pdf.subtitle') }} · {{ $data->providerName }}</div>

<div class="section">
    <div class="title">{{ __('surgery_tiss.pdf.identification') }}</div>
    <table class="fields"><tr>
        <td style="width:34%"><span class="label">{{ __('surgery_tiss.pdf.operator') }}</span>{{ $v($operatorName) }} ({{ $data->operatorAnsCode }})</td>
        <td style="width:33%"><span class="label">{{ __('surgery_tiss.pdf.guide_number') }}</span>{{ $data->guideNumber }}</td>
        <td><span class="label">{{ __('surgery_tiss.pdf.request_date') }}</span>{{ $fmtDate($data->requestDate) }}</td>
    </tr>
    @if($isOpme)
    <tr>
        <td colspan="3"><span class="label">{{ __('surgery_tiss.pdf.referenced_guide') }}</span>{{ $v($data->referencedGuideNumber) }}</td>
    </tr>
    @endif
    </table>
</div>

<div class="section">
    <div class="title">{{ __('surgery_tiss.pdf.beneficiary') }}</div>
    <table class="fields"><tr>
        <td style="width:67%"><span class="label">{{ __('surgery_tiss.pdf.name') }}</span>{{ $v($beneficiary) }}</td>
        <td><span class="label">{{ __('surgery_tiss.pdf.card') }}</span>{{ $v($data->beneficiaryCard) }}</td>
    </tr></table>
</div>

<div class="section">
    <div class="title">{{ __('surgery_tiss.pdf.requester') }}</div>
    <table class="fields"><tr>
        <td style="width:40%"><span class="label">{{ __('surgery_tiss.pdf.professional') }}</span>{{ $v($pro['name']) }}</td>
        <td style="width:20%"><span class="label">{{ __('surgery_tiss.pdf.council') }}</span>CRM {{ $v($pro['council_number']) }}</td>
        <td style="width:15%"><span class="label">{{ __('surgery_tiss.pdf.cbos') }}</span>{{ $v($pro['cbos']) }}</td>
        <td><span class="label">{{ __('surgery_tiss.pdf.phone') }}</span>{{ $v($pro['phone']) }}</td>
    </tr><tr>
        <td colspan="4"><span class="label">{{ __('surgery_tiss.pdf.provider') }}</span>{{ $data->providerName }} · {{ __('surgery_tiss.pdf.provider_code') }} {{ $v($data->providerCode) }}@if($data->providerCnes) · CNES {{ $data->providerCnes }}@endif</td>
    </tr></table>
</div>

@unless($isOpme)
<div class="section">
    <div class="title">{{ __('surgery_tiss.pdf.hospital') }}</div>
    <table class="fields"><tr>
        <td style="width:50%"><span class="label">{{ __('surgery_tiss.pdf.hospital_name') }}</span>{{ $v($data->hospital['name']) }}</td>
        <td style="width:25%"><span class="label">{{ __('surgery_tiss.pdf.hospital_code') }}</span>{{ $v($data->hospital['operator_code']) }}</td>
        <td><span class="label">{{ __('surgery_tiss.pdf.suggested_date') }}</span>{{ $fmtDate($data->suggestedDate) }}</td>
    </tr></table>
</div>

<div class="section">
    <div class="title">{{ __('surgery_tiss.pdf.stay') }}</div>
    <table class="fields"><tr>
        <td style="width:25%"><span class="label">{{ __('surgery_tiss.pdf.character') }}</span>{{ __('surgery_tiss.pdf.urgency.' . $data->urgencyCode) }}</td>
        <td style="width:25%"><span class="label">{{ __('surgery_tiss.pdf.stay_type') }}</span>{{ __('surgery_tiss.pdf.inpatient_type.' . $data->inpatientTypeCode) }}</td>
        <td style="width:25%"><span class="label">{{ __('surgery_tiss.pdf.regime') }}</span>{{ __('surgery_tiss.pdf.regime_code.' . $data->regimeCode) }}</td>
        <td><span class="label">{{ __('surgery_tiss.pdf.days') }}</span>{{ $data->daysRequested }}</td>
    </tr><tr>
        <td colspan="2"><span class="label">{{ __('surgery_tiss.pdf.opme') }}</span>{{ $data->opme ? __('surgery_tiss.pdf.yes') : __('surgery_tiss.pdf.no') }}</td>
        <td colspan="2"><span class="label">{{ __('surgery_tiss.pdf.diagnoses') }}</span>{{ $data->diagnoses ? implode(', ', $data->diagnoses) : '—' }}</td>
    </tr></table>
    <div class="text"><span class="label">{{ __('surgery_tiss.pdf.clinical_indication') }}</span>{{ $v($data->clinicalIndication) }}</div>
</div>
@endunless

<div class="section">
    <div class="title">{{ $isOpme ? __('surgery_tiss.pdf.opme_items') : __('surgery_tiss.pdf.procedures') }}</div>
    <table class="items">
        <thead><tr>
            <th scope="col" style="width:8%">{{ __('surgery_tiss.pdf.table') }}</th>
            <th scope="col" style="width:16%">{{ __('surgery_tiss.pdf.code') }}</th>
            <th scope="col">{{ __('surgery_tiss.pdf.description') }}</th>
            <th scope="col" style="width:8%">{{ __('surgery_tiss.pdf.quantity') }}</th>
            @if($isOpme)
                <th scope="col" style="width:9%">{{ __('surgery_tiss.pdf.manufacturer_option') }}</th>
                <th scope="col" style="width:15%">{{ __('surgery_tiss.pdf.anvisa') }}</th>
            @endif
        </tr></thead>
        <tbody>
        @foreach($data->items as $item)
            <tr>
                <td>{{ $item->tableCode }}</td>
                <td>{{ $v($item->code) }}</td>
                <td>{{ $item->description }}@if($isOpme && ($item->manufacturerRef || $item->operatingAuthorization))<br><span class="label">{{ __('surgery_tiss.pdf.manufacturer_ref') }} {{ $item->manufacturerRef ?: '—' }} · {{ __('surgery_tiss.pdf.operating_authorization') }} {{ $item->operatingAuthorization ?: '—' }}</span>@endif</td>
                <td>{{ $item->quantity }}</td>
                @if($isOpme)
                    <td>{{ $v($item->manufacturerOption) }}</td>
                    <td>{{ $v($item->anvisaRegistration) }}</td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if($isOpme)
<div class="section">
    <div class="title">{{ __('surgery_tiss.pdf.justification') }}</div>
    <div class="text">{{ $v($data->justification) }}</div>
    @if($data->specification)
        <div class="text"><span class="label">{{ __('surgery_tiss.pdf.specification') }}</span>{{ $data->specification }}</div>
    @endif
</div>
@endif

@if($data->observation)
<div class="section">
    <div class="title">{{ __('surgery_tiss.pdf.observation') }}</div>
    <div class="text">{{ $data->observation }}</div>
</div>
@endif

<div class="signature">{{ $v($pro['name']) }}<br>CRM {{ $v($pro['council_number']) }}</div>

@if($xmlHash)
<div class="stamp">{{ __('surgery_tiss.pdf.xml_hash') }}: {{ $xmlHash }}</div>
@endif
</body>
</html>
