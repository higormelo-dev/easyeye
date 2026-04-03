<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>{{ $documentation->getTypeLabel() }} — {{ $documentation->title }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: {{ $setting->font_family ?? 'Arial' }}, Helvetica, sans-serif;
        font-size: {{ $setting->font_size ?? 11 }}pt;
        color: #1a1a1a;
        line-height: 1.55;
        padding: {{ $setting->margin_top ?? 20 }}mm {{ $setting->margin_right ?? 15 }}mm {{ $setting->margin_bottom ?? 20 }}mm {{ $setting->margin_left ?? 15 }}mm;
    }

    /* ── Header ─────────────────────────────────────────────────── */
    .header {
        display: table;
        width: 100%;
        border-bottom: 2px solid #1976d2;
        padding-bottom: 10px;
        margin-bottom: 18px;
    }
    .header-logo { display: table-cell; width: 70px; vertical-align: middle; }
    .header-logo img { max-height: 55px; }
    .header-info { display: table-cell; vertical-align: middle; padding-left: 12px; }
    .header-info h1 { font-size: 13pt; color: #1976d2; }
    .header-info p  { font-size: 9pt; color: #666; margin-top: 3px; }

    /* ── Document title ──────────────────────────────────────────── */
    .doc-title-wrap {
        text-align: center;
        margin: 16px 0 20px;
    }
    .doc-type-badge {
        display: inline-block;
        background: #e3eef9;
        color: #1976d2;
        font-size: 9pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 3px 12px;
        border-radius: 20px;
        margin-bottom: 6px;
    }
    .doc-title { font-size: 15pt; font-weight: bold; color: #212121; }

    /* ── Patient block ───────────────────────────────────────────── */
    .patient-block {
        background: #f5f9ff;
        border: 1px solid #d0e4f7;
        border-radius: 4px;
        padding: 8px 12px;
        margin-bottom: 18px;
        font-size: 10pt;
    }
    .patient-block strong { color: #1976d2; }

    /* ── Content ─────────────────────────────────────────────────── */
    .content-body {
        min-height: 240px;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: {{ $setting->font_size ?? 11 }}pt;
        line-height: 1.6;
    }

    /* ── Signature ───────────────────────────────────────────────── */
    .signature-block { margin-top: 40px; text-align: center; font-size: 9.5pt; }
    .signature-line  { border-top: 1px solid #333; display: inline-block; width: 200px; margin-bottom: 4px; }

    /* ── Footer ──────────────────────────────────────────────────── */
    .footer {
        position: fixed;
        bottom: 0; left: 0; right: 0;
        font-size: 8pt;
        color: #aaa;
        text-align: center;
        padding-top: 4px;
        border-top: 1px solid #e0e0e0;
    }
</style>
</head>
<body>

<!-- ─── CABEÇALHO ─────────────────────────────────────────────────────────── -->
@if($setting->show_logo)
<div class="header">
    <div class="header-logo">
        @if($entity->logo_path)
        <img src="{{ public_path('storage/' . $entity->logo_path) }}" alt="">
        @endif
    </div>
    <div class="header-info">
        <h1>{{ $entity->name }}</h1>
        <p>{{ $entity->address_full ?? '' }}</p>
    </div>
</div>
@endif

<!-- ─── TÍTULO DO DOCUMENTO ───────────────────────────────────────────────── -->
<div class="doc-title-wrap">
    <div class="doc-type-badge">{{ $documentation->getTypeLabel() }}</div><br>
    <div class="doc-title">{{ $documentation->title }}</div>
</div>

<!-- ─── DADOS DO PACIENTE ─────────────────────────────────────────────────── -->
@if($setting->patient_name)
<div class="patient-block">
    <strong>Paciente:</strong> {{ $patient->person->full_name }}
    @if($setting->patient_birth && $patient->person->birth_date)
     &nbsp;|&nbsp; <strong>Nascimento:</strong> {{ $patient->person->birth_date->format('d/m/Y') }}
    @endif
    @if($setting->patient_address && $patient->person->address_full)
     &nbsp;|&nbsp; {{ $patient->person->address_full }}
    @endif
    &nbsp;|&nbsp; <strong>Data:</strong> {{ now()->format('d/m/Y') }}
</div>
@endif

<!-- ─── CONTEÚDO ───────────────────────────────────────────────────────────── -->
<div class="content-body">{!! nl2br(e($documentation->content)) !!}</div>

<!-- ─── ASSINATURA ─────────────────────────────────────────────────────────── -->
@php $doctor = $doc->doctor ?? null; @endphp
@include('pdf._signature')

<!-- ─── RODAPÉ ─────────────────────────────────────────────────────────────── -->
@if($setting->show_footer)
<div class="footer">
    {{ $setting->footer_text ?? $entity->name }}
    &nbsp;·&nbsp; Emitido em {{ now()->format('d/m/Y \à\s H:i') }}
</div>
@endif

</body>
</html>
