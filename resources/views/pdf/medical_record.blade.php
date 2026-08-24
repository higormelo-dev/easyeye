<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<title>{{ __('pdf.title.medical_record') }} {{ $record->code }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 11pt;
        color: #1a1a1a;
        line-height: 1.45;
        /* Reserva espaço inferior para signature fixa + footer (ver _signature.blade.php). */
        padding-bottom: 140px;
    }
    /* ── Header ─────────────────────────────────────────────────── */
    .header {
        display: table;
        width: 100%;
        border-bottom: 2px solid #1976d2;
        padding-bottom: 8px;
        margin-bottom: 14px;
    }
    .header-logo { display: table-cell; width: 70px; vertical-align: middle; }
    .header-logo img { max-height: 52px; max-width: 60px; }
    .header-text { display: table-cell; vertical-align: middle; padding-left: 10px; }
    .header-text h1 { font-size: 14pt; color: #1976d2; }
    .header-text p  { font-size: 9pt; color: #555; }
    .header-code    { display: table-cell; text-align: right; vertical-align: middle; font-size: 9pt; color: #555; white-space: nowrap; }

    /* ── Section titles ──────────────────────────────────────────── */
    .section-title {
        background: #e3eef9;
        color: #1976d2;
        font-size: 10pt;
        font-weight: bold;
        padding: 3px 8px;
        margin: 12px 0 6px;
        border-left: 4px solid #1976d2;
    }

    /* ── Grid ────────────────────────────────────────────────────── */
    .row   { display: table; width: 100%; margin-bottom: 4px; }
    .col   { display: table-cell; vertical-align: top; padding-right: 8px; }
    .col-3 { width: 25%; }
    .col-4 { width: 33.33%; }
    .col-6 { width: 50%; }
    .col-12{ width: 100%; }

    .field-label { font-size: 8.5pt; color: #777; display: block; }
    .field-value { font-size: 10.5pt; word-break: break-word; }
    .field-value.empty { color: #aaa; font-style: italic; }

    /* ── Table (ex: refração) ────────────────────────────────────── */
    table.clinical { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 6px; }
    table.clinical th {
        background: #f0f4f8;
        border: 1px solid #c8d5e0;
        padding: 4px 6px;
        text-align: center;
        font-size: 9pt;
        color: #444;
    }
    table.clinical td {
        border: 1px solid #e0e8f0;
        padding: 4px 6px;
        text-align: center;
    }
    table.clinical td.label { text-align: left; font-weight: bold; color: #1976d2; background: #f5f9ff; }

    /* ── Booleans (anamnese) ─────────────────────────────────────── */
    .bool-grid { display: table; width: 100%; }
    .bool-item { display: table-cell; padding-right: 16px; font-size: 10pt; white-space: nowrap; }
    .bool-yes  { color: #c62828; font-weight: bold; }
    .bool-no   { color: #888; }

    /* ── Assinatura ──────────────────────────────────────────────── */
    .signature-block { margin-top: 30px; text-align: center; font-size: 9.5pt; }
    .signature-line  { border-top: 1px solid #333; display: inline-block; width: 200px; margin-bottom: 4px; }

    /* ── Footer ──────────────────────────────────────────────────── */
    .footer {
        position: fixed;
        bottom: 0;
        left: 0; right: 0;
        font-size: 8pt;
        color: #999;
        text-align: center;
        border-top: 1px solid #e0e0e0;
        padding-top: 4px;
    }
    .page-break { page-break-before: always; }

    /* ── Quebra de página (wkhtmltopdf) ──────────────────────────────
       Bloco/tabela que não cabe no espaço restante vai INTEIRO pra
       próxima página; se a seção for maior que uma página, o motor
       quebra mesmo assim, mas respeitando .row e tr (nunca corta um
       campo ou linha da tabela no meio). */
    .section        { page-break-inside: avoid; }
    .section-title  { page-break-after: avoid; page-break-inside: avoid; }
    .row            { page-break-inside: avoid; }
    .bool-grid      { page-break-inside: avoid; }
    table.clinical  { page-break-inside: avoid; }
    table.clinical tr { page-break-inside: avoid; }
    /* wkhtmltopdf repete o <thead> na página seguinte SOBREPONDO a
       primeira linha (bug conhecido) — display:table-row-group desativa
       a repetição; como a tabela inteira tem avoid, o cabeçalho
       acompanha o bloco. */
    table.clinical thead { display: table-row-group; }
</style>
</head>
<body>
@php
    // Variáveis derivadas do $record p/ uso direto + repasse a partials.
    // A service envia apenas (record, setting); o resto vem das relações já carregadas.
    $patient = $record->patient ?? null;
    $doctor  = $record->doctor  ?? null;
    $entity  = $record->schedule?->entity
            ?? \App\Models\Entity::find(session('selected_entity_id'));
@endphp

<!-- ─── CABEÇALHO ─────────────────────────────────────────────────────────── -->
<div class="header">
    <div class="header-logo">
        @if($entity->logo_path)
        <img src="{{ public_path('storage/' . $entity->logo_path) }}" alt="">
        @endif
    </div>
    <div class="header-text">
        <h1>{{ $entity->name }}</h1>
        <p>{{ $entity->address_full ?? '' }}</p>
    </div>
    <div class="header-code">
        <strong>{{ $record->code }}</strong><br>
        {{ $record->created_at->isoFormat('L LT') }}
    </div>
</div>

<!-- ─── DADOS DO PACIENTE ─────────────────────────────────────────────────── -->
<div class="section">
<div class="section-title">{{ __('pdf.section_patient_data') }}</div>
<div class="row">
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.name') }}</span>
        <span class="field-value">{{ $patient->person->full_name }}</span>
    </div>
    <div class="col col-3">
        <span class="field-label">{{ __('pdf.gender') }}</span>
        <span class="field-value">{{ $patient->person->gender_label ?? '—' }}</span>
    </div>
    <div class="col col-3">
        <span class="field-label">{{ __('pdf.age') }}</span>
        <span class="field-value">
            {{ $patient->person->age !== null ? __('pdf.age_years', ['years' => $patient->person->age]) : '—' }}
        </span>
    </div>
</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.birth_date') }}</span>
        <span class="field-value">{{ $patient->person->birth_date?->isoFormat('L') ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.code') }}</span>
        <span class="field-value">{{ $patient->code }}</span>
    </div>
    <div class="col col-4"></div>
</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.doctor') }}</span>
        <span class="field-value">{{ $record->doctor?->person?->full_name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.crm') }}</span>
        <span class="field-value">
            {{ $record->doctor?->record ?? '—' }}
            @if($record->doctor?->record_specialty)
            &nbsp;· RQE {{ $record->doctor->record_specialty }}
            @endif
        </span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.covenant') }}</span>
        <span class="field-value">{{ $patient->covenant?->name ?? __('pdf.private_payment') }}</span>
    </div>
</div>
</div>

<!-- ─── ANAMNESE ──────────────────────────────────────────────────────────── -->
<div class="section">
<div class="section-title">{{ __('pdf.section_anamnesis') }}</div>
@if($record->main_complaint)
<div class="row"><div class="col col-12">
    <span class="field-label">{{ __('pdf.main_complaint') }}</span>
    <span class="field-value">{{ $record->main_complaint }}</span>
</div></div>
@endif
@if($record->hda)
<div class="row"><div class="col col-12">
    <span class="field-label">{{ __('pdf.hda') }}</span>
    <span class="field-value">{{ $record->hda }}</span>
</div></div>
@endif
<div class="bool-grid">
    <div class="bool-item @if($record->diabetic) bool-yes @else bool-no @endif">
        {{ __('pdf.diabetic') }}: {{ $record->diabetic ? __('pdf.yes') : __('pdf.no') }}
        @if($record->diabetic_family) {{ __('pdf.family_history_short') }} @endif
    </div>
    <div class="bool-item @if($record->hypertensive) bool-yes @else bool-no @endif">
        {{ __('pdf.hypertensive') }}: {{ $record->hypertensive ? __('pdf.yes') : __('pdf.no') }}
        @if($record->hypertensive_family) {{ __('pdf.family_history_short') }} @endif
    </div>
    <div class="bool-item @if($record->glaucomatous) bool-yes @else bool-no @endif">
        {{ __('pdf.glaucomatous') }}: {{ $record->glaucomatous ? __('pdf.yes') : __('pdf.no') }}
        @if($record->glaucomatous_family) {{ __('pdf.family_history_short') }} @endif
    </div>
</div>
@if($record->ocular_surgical_history)
<div class="row" style="margin-top:6px;"><div class="col col-12">
    <span class="field-label">{{ __('pdf.ocular_surgical_history') }}</span>
    <span class="field-value">{{ $record->ocular_surgical_history }}</span>
</div></div>
@endif
@if($record->medications_in_use)
<div class="row"><div class="col col-12">
    <span class="field-label">{{ __('pdf.medications_in_use') }}</span>
    <span class="field-value">{{ $record->medications_in_use }}</span>
</div></div>
@endif
</div>

<!-- ─── EXAME FÍSICO ───────────────────────────────────────────────────────── -->
<div class="section">
<div class="section-title">{{ __('pdf.section_physical_exam') }}</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.av_without_od') }}</span>
        <span class="field-value">{{ $record->visualAcuityWithoutCorrectionRight?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.av_without_oe') }}</span>
        <span class="field-value">{{ $record->visualAcuityWithoutCorrectionLeft?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.visual_acuity') }}</span>
        <span class="field-value">{{ $record->visualAcuityType?->name ?? '—' }}</span>
    </div>
</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.near_point_convergence') }}</span>
        <span class="field-value">{{ $record->nearPointConvergence?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.cover_test') }}</span>
        <span class="field-value">{{ $record->coverTestType?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.color_vision') }}</span>
        <span class="field-value">{{ $record->colorVisionType?->name ?? '—' }}</span>
    </div>
</div>
@if($record->ocular_motility)
<div class="row"><div class="col col-12">
    <span class="field-label">{{ __('pdf.ocular_motility') }}</span>
    <span class="field-value">{{ $record->ocular_motility }}</span>
</div></div>
@endif
<div class="row">
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.tonometry_od') }}</span>
        <span class="field-value">{{ $record->tonometer_right ? $record->tonometer_right . ' mmHg' : '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.tonometry_oe') }}</span>
        <span class="field-value">{{ $record->tonometer_left ? $record->tonometer_left . ' mmHg' : '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.time_label') }}</span>
        <span class="field-value">{{ $record->tonometer_time ?? '—' }}</span>
    </div>
</div>
@if($record->pachymetry_right || $record->pachymetry_left)
<div class="row">
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.pachymetry_od') }}</span>
        <span class="field-value">{{ $record->pachymetry_right ? $record->pachymetry_right . ' μm' : '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.pachymetry_oe') }}</span>
        <span class="field-value">{{ $record->pachymetry_left ? $record->pachymetry_left . ' μm' : '—' }}</span>
    </div>
</div>
@endif
@if($record->gonioscopy_right || $record->gonioscopy_left)
<div class="row">
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.gonioscopy_od') }}</span>
        <span class="field-value">{{ $record->gonioscopy_right ?? '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.gonioscopy_oe') }}</span>
        <span class="field-value">{{ $record->gonioscopy_left ?? '—' }}</span>
    </div>
</div>
@endif
</div>

<!-- ─── REFRAÇÃO ───────────────────────────────────────────────────────────── -->
<div class="section">
<div class="section-title">{{ __('pdf.section_refraction') }}</div>
<table class="clinical">
    <thead>
        <tr>
            <th style="width:18%">{{ __('pdf.eye') }}</th>
            <th>{{ __('pdf.spherical') }}</th>
            <th>{{ __('pdf.cylindrical') }}</th>
            <th>{{ __('pdf.axis') }}</th>
            <th>{{ __('pdf.av_sc_short') }}</th>
            <th>{{ __('pdf.av_cc_short') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="label">OD — {{ __('pdf.dynamic') }}</td>
            <td>{{ $record->dynamic_spherical_right ?? '—' }}</td>
            <td>{{ $record->dynamic_cylindrical_right ?? '—' }}</td>
            <td>{{ $record->dynamic_axis_right ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithoutCorrectionRight?->name ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithCorrectionRight?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">OE — {{ __('pdf.dynamic') }}</td>
            <td>{{ $record->dynamic_spherical_left ?? '—' }}</td>
            <td>{{ $record->dynamic_cylindrical_left ?? '—' }}</td>
            <td>{{ $record->dynamic_axis_left ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithoutCorrectionLeft?->name ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithCorrectionLeft?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">OD — {{ __('pdf.static') }}</td>
            <td>{{ $record->static_spherical_right ?? '—' }}</td>
            <td>{{ $record->static_cylindrical_right ?? '—' }}</td>
            <td>{{ $record->static_axis_right ?? '—' }}</td>
            <td colspan="2">—</td>
        </tr>
        <tr>
            <td class="label">OE — {{ __('pdf.static') }}</td>
            <td>{{ $record->static_spherical_left ?? '—' }}</td>
            <td>{{ $record->static_cylindrical_left ?? '—' }}</td>
            <td>{{ $record->static_axis_left ?? '—' }}</td>
            <td colspan="2">—</td>
        </tr>
    </tbody>
</table>
@if($record->additionType || $record->lensAway || $record->lensNear)
<div class="row">
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.addition') }}</span>
        <span class="field-value">{{ $record->additionType?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.lens_away') }}</span>
        <span class="field-value">{{ $record->lens_away_names_text ?: '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">{{ __('pdf.lens_near') }}</span>
        <span class="field-value">{{ $record->lens_near_names_text ?: '—' }}</span>
    </div>
</div>
@endif
</div>

<!-- ─── ACHADOS CLÍNICOS ───────────────────────────────────────────────────── -->
<div class="section">
@if($record->biomicroscopy_right || $record->biomicroscopy_left || $record->fundoscopy_right || $record->fundoscopy_left)
<div class="section-title">{{ __('pdf.section_clinical_findings') }}</div>
<div class="row">
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.biomicroscopy_od') }}</span>
        <span class="field-value">{{ $record->biomicroscopy_right ?? '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.biomicroscopy_oe') }}</span>
        <span class="field-value">{{ $record->biomicroscopy_left ?? '—' }}</span>
    </div>
</div>
<div class="row">
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.fundoscopy_od') }}</span>
        <span class="field-value">{{ $record->fundoscopy_right ?? '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">{{ __('pdf.fundoscopy_oe') }}</span>
        <span class="field-value">{{ $record->fundoscopy_left ?? '—' }}</span>
    </div>
</div>
@endif
@if($record->observation_general)
<div class="row"><div class="col col-12">
    <span class="field-label">{{ __('pdf.observations_general') }}</span>
    <span class="field-value">{{ $record->observation_general }}</span>
</div></div>
@endif
@if($record->observation_of_lenses)
<div class="row"><div class="col col-12">
    <span class="field-label">{{ __('pdf.observations_lenses') }}</span>
    <span class="field-value">{{ $record->observation_of_lenses }}</span>
</div></div>
@endif
</div>

<!-- ─── DIAGNÓSTICO & CONDUTA ─────────────────────────────────────────────── -->
<div class="section">
@php $hasDiagnosis = !empty($record->diagnosis_cids); @endphp
@if($hasDiagnosis || $record->clinical_conduct)
<div class="section-title">{{ __('pdf.section_diagnosis_conduct') }}</div>
@if($hasDiagnosis)
@foreach($record->diagnosis_cids as $cid)
<div class="row">
    <div class="col col-3">
        <span class="field-label">{{ __('pdf.cid10') }}</span>
        <span class="field-value">{{ $cid['code'] }}</span>
    </div>
    <div class="col col-9">
        <span class="field-label">{{ __('pdf.description') }}</span>
        <span class="field-value">{{ $cid['description'] ?? '—' }}</span>
    </div>
</div>
@endforeach
@endif
@if($record->clinical_conduct)
<div class="row"><div class="col col-12">
    <span class="field-label">{{ __('pdf.clinical_conduct') }}</span>
    <span class="field-value">{{ $record->clinical_conduct }}</span>
</div></div>
@endif
@if($record->follow_up_days)
<div class="row"><div class="col col-4">
    <span class="field-label">{{ __('pdf.follow_up') }}</span>
    <span class="field-value">{{ $record->follow_up_days }} {{ __('pdf.days') }}</span>
</div></div>
@endif
@endif
</div>

<!-- ─── ASSINATURA (ancorada no rodapé via position:fixed) ─────────────────── -->
@php
    /** @var \App\Services\LocationFormatter $_locFormatter */
    $_locFormatter = app(\App\Services\LocationFormatter::class);
    $_locFmt       = $_locFormatter->format($entity);
    $_dateLine     = $record->isSigned()
        ? \Illuminate\Support\Carbon::parse($record->signed_at)->isoFormat('LL')
        : \Illuminate\Support\Carbon::now()->isoFormat('LL');
@endphp
<div class="pmr-signature-fixed" style="
    position: fixed;
    left: 0; right: 0;
    bottom: 30px;
    text-align: center;
    font-size: 9.5pt;
    background: #fff;
    padding: 0 10mm;
">
    <div style="margin-bottom:18px;">
        {{ trim($_locFmt ? $_locFmt . ', ' : '') }}{{ $_dateLine }}.
    </div>
    <div class="signature-block" style="display:inline-block; min-width:240px;">
        <div class="signature-line" style="border-top:1px solid #333; width:240px; margin:0 auto 4px;"></div>
        @if($record->isSigned())
            {{ $record->signedBy?->user?->name ?? $record->doctor?->person?->full_name }}
            @if($record->doctor?->record)<br>CRM {{ $record->doctor->record }}@endif
            @if($record->doctor?->record_specialty)<br>RQE {{ $record->doctor->record_specialty }}@endif
            <br>{{ __('pdf.signed_at') }} {{ $record->signed_at->isoFormat('L LT') }}<br>
            <small style="color:#999;">{{ __('pdf.signature_hash') }}: {{ $record->signature_hash }}</small>
        @else
            {{ $record->doctor?->person?->full_name ?? '' }}
            @if($record->doctor?->record)<br>CRM {{ $record->doctor->record }}@endif
            @if($record->doctor?->record_specialty)<br>RQE {{ $record->doctor->record_specialty }}@endif
        @endif
    </div>
</div>

<!-- ─── RODAPÉ ─────────────────────────────────────────────────────────────── -->
<div class="footer">
    {{ $entity->name }} · {{ __('pdf.generated_at') }} {{ \Illuminate\Support\Carbon::now()->isoFormat('L LT') }} · {{ $record->code }}
</div>

</body>
</html>
