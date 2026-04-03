<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Prontuário {{ $record->code }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 11pt;
        color: #1a1a1a;
        line-height: 1.45;
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
</style>
</head>
<body>

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
        {{ $record->created_at->format('d/m/Y H:i') }}
    </div>
</div>

<!-- ─── DADOS DO PACIENTE ─────────────────────────────────────────────────── -->
<div class="section-title">Dados do Paciente</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">Nome</span>
        <span class="field-value">{{ $patient->person->full_name }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Data de Nascimento</span>
        <span class="field-value">{{ $patient->person->birth_date?->format('d/m/Y') ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Código</span>
        <span class="field-value">{{ $patient->code }}</span>
    </div>
</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">Médico responsável</span>
        <span class="field-value">{{ $record->doctor?->person?->full_name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">CRM</span>
        <span class="field-value">{{ $record->doctor?->crm ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Convênio</span>
        <span class="field-value">{{ $patient->covenant?->name ?? 'Particular' }}</span>
    </div>
</div>

<!-- ─── ANAMNESE ──────────────────────────────────────────────────────────── -->
<div class="section-title">Anamnese</div>
@if($record->main_complaint)
<div class="row"><div class="col col-12">
    <span class="field-label">Queixa principal</span>
    <span class="field-value">{{ $record->main_complaint }}</span>
</div></div>
@endif
@if($record->hda)
<div class="row"><div class="col col-12">
    <span class="field-label">HDA</span>
    <span class="field-value">{{ $record->hda }}</span>
</div></div>
@endif
<div class="bool-grid">
    <div class="bool-item @if($record->diabetic) bool-yes @else bool-no @endif">
        Diabético: {{ $record->diabetic ? 'Sim' : 'Não' }}
        @if($record->diabetic_family) (HF) @endif
    </div>
    <div class="bool-item @if($record->hypertensive) bool-yes @else bool-no @endif">
        Hipertenso: {{ $record->hypertensive ? 'Sim' : 'Não' }}
        @if($record->hypertensive_family) (HF) @endif
    </div>
    <div class="bool-item @if($record->glaucomatous) bool-yes @else bool-no @endif">
        Glaucomatoso: {{ $record->glaucomatous ? 'Sim' : 'Não' }}
        @if($record->glaucomatous_family) (HF) @endif
    </div>
</div>
@if($record->ocular_surgical_history)
<div class="row" style="margin-top:6px;"><div class="col col-12">
    <span class="field-label">Histórico cirúrgico ocular</span>
    <span class="field-value">{{ $record->ocular_surgical_history }}</span>
</div></div>
@endif
@if($record->medications_in_use)
<div class="row"><div class="col col-12">
    <span class="field-label">Medicamentos em uso</span>
    <span class="field-value">{{ $record->medications_in_use }}</span>
</div></div>
@endif

<!-- ─── EXAME FÍSICO ───────────────────────────────────────────────────────── -->
<div class="section-title">Exame Físico</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">AV sem correção OD</span>
        <span class="field-value">{{ $record->visualAcuityWithoutCorrectionRight?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">AV sem correção OE</span>
        <span class="field-value">{{ $record->visualAcuityWithoutCorrectionLeft?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Acuidade Visual</span>
        <span class="field-value">{{ $record->visualAcuityType?->name ?? '—' }}</span>
    </div>
</div>
<div class="row">
    <div class="col col-4">
        <span class="field-label">PPC</span>
        <span class="field-value">{{ $record->nearPointConvergence?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Cover Test</span>
        <span class="field-value">{{ $record->coverTestType?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Visão Cromática</span>
        <span class="field-value">{{ $record->colorVisionType?->name ?? '—' }}</span>
    </div>
</div>
@if($record->ocular_motility)
<div class="row"><div class="col col-12">
    <span class="field-label">Motilidade ocular</span>
    <span class="field-value">{{ $record->ocular_motility }}</span>
</div></div>
@endif
<div class="row">
    <div class="col col-4">
        <span class="field-label">Tonometria OD</span>
        <span class="field-value">{{ $record->tonometer_right ? $record->tonometer_right . ' mmHg' : '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Tonometria OE</span>
        <span class="field-value">{{ $record->tonometer_left ? $record->tonometer_left . ' mmHg' : '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Horário</span>
        <span class="field-value">{{ $record->tonometer_time ?? '—' }}</span>
    </div>
</div>
@if($record->pachymetry_right || $record->pachymetry_left)
<div class="row">
    <div class="col col-6">
        <span class="field-label">Paquimetria OD</span>
        <span class="field-value">{{ $record->pachymetry_right ? $record->pachymetry_right . ' μm' : '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">Paquimetria OE</span>
        <span class="field-value">{{ $record->pachymetry_left ? $record->pachymetry_left . ' μm' : '—' }}</span>
    </div>
</div>
@endif
@if($record->gonioscopy_right || $record->gonioscopy_left)
<div class="row">
    <div class="col col-6">
        <span class="field-label">Gonioscopia OD</span>
        <span class="field-value">{{ $record->gonioscopy_right ?? '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">Gonioscopia OE</span>
        <span class="field-value">{{ $record->gonioscopy_left ?? '—' }}</span>
    </div>
</div>
@endif

<!-- ─── REFRAÇÃO ───────────────────────────────────────────────────────────── -->
<div class="section-title">Refração</div>
<table class="clinical">
    <thead>
        <tr>
            <th style="width:18%">Olho</th>
            <th>Esférico</th>
            <th>Cilíndrico</th>
            <th>Eixo</th>
            <th>AV s/c</th>
            <th>AV c/c</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="label">OD — Dinâmica</td>
            <td>{{ $record->dynamic_spherical_right ?? '—' }}</td>
            <td>{{ $record->dynamic_cylindrical_right ?? '—' }}</td>
            <td>{{ $record->dynamic_axis_right ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithoutCorrectionRight?->name ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithCorrectionRight?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">OE — Dinâmica</td>
            <td>{{ $record->dynamic_spherical_left ?? '—' }}</td>
            <td>{{ $record->dynamic_cylindrical_left ?? '—' }}</td>
            <td>{{ $record->dynamic_axis_left ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithoutCorrectionLeft?->name ?? '—' }}</td>
            <td>{{ $record->visualAcuityWithCorrectionLeft?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">OD — Estática</td>
            <td>{{ $record->static_spherical_right ?? '—' }}</td>
            <td>{{ $record->static_cylindrical_right ?? '—' }}</td>
            <td>{{ $record->static_axis_right ?? '—' }}</td>
            <td colspan="2">—</td>
        </tr>
        <tr>
            <td class="label">OE — Estática</td>
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
        <span class="field-label">Adição</span>
        <span class="field-value">{{ $record->additionType?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Lente Longe</span>
        <span class="field-value">{{ $record->lensAway?->name ?? '—' }}</span>
    </div>
    <div class="col col-4">
        <span class="field-label">Lente Perto</span>
        <span class="field-value">{{ $record->lensNear?->name ?? '—' }}</span>
    </div>
</div>
@endif

<!-- ─── ACHADOS CLÍNICOS ───────────────────────────────────────────────────── -->
@if($record->biomicroscopy_right || $record->biomicroscopy_left || $record->fundoscopy_right || $record->fundoscopy_left)
<div class="section-title">Achados Clínicos</div>
<div class="row">
    <div class="col col-6">
        <span class="field-label">Biomicroscopia OD</span>
        <span class="field-value">{{ $record->biomicroscopy_right ?? '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">Biomicroscopia OE</span>
        <span class="field-value">{{ $record->biomicroscopy_left ?? '—' }}</span>
    </div>
</div>
<div class="row">
    <div class="col col-6">
        <span class="field-label">Fundoscopia OD</span>
        <span class="field-value">{{ $record->fundoscopy_right ?? '—' }}</span>
    </div>
    <div class="col col-6">
        <span class="field-label">Fundoscopia OE</span>
        <span class="field-value">{{ $record->fundoscopy_left ?? '—' }}</span>
    </div>
</div>
@endif
@if($record->observation_general)
<div class="row"><div class="col col-12">
    <span class="field-label">Observações gerais</span>
    <span class="field-value">{{ $record->observation_general }}</span>
</div></div>
@endif
@if($record->observation_of_lenses)
<div class="row"><div class="col col-12">
    <span class="field-label">Observações sobre lentes</span>
    <span class="field-value">{{ $record->observation_of_lenses }}</span>
</div></div>
@endif

<!-- ─── DIAGNÓSTICO & CONDUTA ─────────────────────────────────────────────── -->
@php $hasDiagnosis = !empty($record->diagnosis_cids); @endphp
@if($hasDiagnosis || $record->clinical_conduct)
<div class="section-title">Diagnóstico &amp; Conduta</div>
@if($hasDiagnosis)
@foreach($record->diagnosis_cids as $cid)
<div class="row">
    <div class="col col-3">
        <span class="field-label">CID-10</span>
        <span class="field-value">{{ $cid['code'] }}</span>
    </div>
    <div class="col col-9">
        <span class="field-label">Descrição</span>
        <span class="field-value">{{ $cid['description'] ?? '—' }}</span>
    </div>
</div>
@endforeach
@endif
@if($record->clinical_conduct)
<div class="row"><div class="col col-12">
    <span class="field-label">Conduta clínica</span>
    <span class="field-value">{{ $record->clinical_conduct }}</span>
</div></div>
@endif
@if($record->follow_up_days)
<div class="row"><div class="col col-4">
    <span class="field-label">Retorno em</span>
    <span class="field-value">{{ $record->follow_up_days }} dias</span>
</div></div>
@endif
@endif

<!-- ─── ASSINATURA ─────────────────────────────────────────────────────────── -->
@if($record->isSigned())
<div class="signature-block">
    <div class="signature-line"></div><br>
    {{ $record->signedBy?->user?->name ?? $record->doctor?->person?->full_name }}<br>
    @if($record->doctor?->crm) CRM {{ $record->doctor->crm }}<br>@endif
    Assinado eletronicamente em {{ $record->signed_at->format('d/m/Y \à\s H:i') }}<br>
    <small style="color:#999;">Hash: {{ $record->signature_hash }}</small>
</div>
@else
<div class="signature-block">
    <div class="signature-line"></div><br>
    {{ $record->doctor?->person?->full_name ?? '' }}<br>
    @if($record->doctor?->crm) CRM {{ $record->doctor->crm }} @endif
</div>
@endif

<!-- ─── RODAPÉ ─────────────────────────────────────────────────────────────── -->
<div class="footer">
    {{ $entity->name }} · Gerado em {{ now()->format('d/m/Y H:i') }} · {{ $record->code }}
</div>

</body>
</html>
