@php
    // Service passa $doc; alias mantido para legibilidade do restante do template.
    $documentation = $doc;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
        /*
           Signature agora está em fluxo normal (não-fixed) — não precisamos
           reservar 130px de padding-bottom. O margin-bottom configurado já
           cobre o espaço necessário; o --footer-html (wkhtmltopdf option)
           renderiza no espaço de margem inferior reservado pelo PDF engine.
        */
        padding: {{ $setting->margin_top ?? 20 }}mm {{ $setting->margin_right ?? 15 }}mm
                 {{ $setting->margin_bottom ?? 20 }}mm {{ $setting->margin_left ?? 15 }}mm;
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
        word-break: break-word;
        font-size: {{ $setting->font_size ?? 11 }}pt;
        line-height: 1.6;
    }
    .content-body p  { margin-bottom: 0.6em; }
    .content-body br { display: block; margin-bottom: 0; }

    /*
       Bloco de observações livres anexado pelo médico (Atestados, etc.).
       NÃO usar `page-break-inside: avoid` aqui — wkhtmltopdf 0.12.6 suprime
       o conteúdo quando o bloco fica perto do final da página (interage mal
       com o spacer/footer fixed do signature).
    */
    .pmr-observations {
        margin-top: 1.5em;
        padding-top: 0.8em;
        border-top: 1px solid #999;
    }
    .pmr-observations p { margin-bottom: 0.5em; }
    .pmr-observations strong { color: #1976d2; }
    .pmr-observations ul,
    .pmr-observations ol { padding-left: 1.5em; margin-bottom: 0.5em; }
    .pmr-observations li { margin-bottom: 0.25em; }

    /* ── Signature ───────────────────────────────────────────────── */
    .signature-block { margin-top: 40px; text-align: center; font-size: 9.5pt; }
    .signature-line  { border-top: 1px solid #333; display: inline-block; width: 200px; margin-bottom: 4px; }

    /* Rodapé gerenciado via --footer-html no MedicalRecordPdfService (não inline) */
</style>
</head>
<body>
@php
    // $documentation já aliasado no topo do arquivo. Aqui derivamos o resto.
    $patient = $doc->patient ?? null;
    $doctor  = $doc->doctor  ?? null;
    $entity  = $doc->medicalRecord?->schedule?->entity
            ?? \App\Models\Entity::find(session('selected_entity_id'));
@endphp

<!-- ─── CABEÇALHO ─────────────────────────────────────────────────────────── -->
@php
    // Usa show_header (campo novo do formulário). Fallback para show_logo (campo
    // legado) para compatibilidade com registros gerados antes da migração de campos.
    $showHeader = $setting->show_header ?? $setting->show_logo ?? false;
@endphp
@if($showHeader)
<div class="header">
    @if($setting->header_show_logo ?? true)
    <div class="header-logo">
        @if($entity?->logo_path)
        <img src="{{ public_path('storage/' . $entity->logo_path) }}" alt="">
        @endif
    </div>
    @endif
    <div class="header-info">
        @if($setting->header_show_name ?? true)
        <h1>{{ $entity?->name }}</h1>
        @endif
        @if(($setting->header_show_address ?? false) && $entity?->address_full)
        <p>{{ $entity->address_full }}</p>
        @endif
        @if(($setting->header_show_phone ?? false) && ($entity?->telephone || $entity?->cellphone))
        <p>{{ $entity->telephone ?? $entity->cellphone }}</p>
        @endif
    </div>
</div>
@endif

<!-- ─── TÍTULO DO DOCUMENTO ───────────────────────────────────────────────── -->
<div class="doc-title-wrap">
{{--    <div class="doc-type-badge">{{ $documentation->getTypeLabel() }}</div><br>--}}
    <div class="doc-title">{{ $documentation->title }}</div>
</div>

<!-- ─── DADOS DO PACIENTE ─────────────────────────────────────────────────── -->
{{-- Nome + gênero + idade são sempre exibidos (compliance clínico — paridade
     entre PDFs do prontuário). Setting `patient_birth/address` controla
     apenas campos extras opcionais. --}}
<div class="patient-block">
    <strong>{{ __('pdf.patient') }}:</strong> {{ $patient->person->full_name }}
    @if($patient->person->gender_label)
     &nbsp;|&nbsp; <strong>{{ __('pdf.gender') }}:</strong> {{ $patient->person->gender_label }}
    @endif
    @if($patient->person->age !== null)
     &nbsp;|&nbsp; <strong>{{ __('pdf.age') }}:</strong> {{ __('pdf.age_years', ['years' => $patient->person->age]) }}
    @endif
    @if($setting->patient_birth && $patient->person->birth_date)
     &nbsp;|&nbsp; <strong>{{ __('pdf.birth') }}:</strong> {{ $patient->person->birth_date->isoFormat('L') }}
    @endif
    @if($setting->patient_address && $patient->person->address_full)
     &nbsp;|&nbsp; {{ $patient->person->address_full }}
    @endif
    &nbsp;|&nbsp; <strong>{{ __('pdf.date') }}:</strong> {{ \Illuminate\Support\Carbon::now()->isoFormat('L') }}
</div>

<!-- ─── CONTEÚDO ─────────────────────────────────────────────────────────────
     `contentForRender()` remove o cabeçalho de data resolvido (CIDADE, DD de MÊS de YYYY)
     do topo do conteúdo. A data continua aparecendo no bloco de assinatura abaixo —
     evita duplicação visual e mantém o banco intocado (auditoria preservada).
-->
<div class="content-body">{!! $documentation->contentForRender() !!}</div>

<!--
    ─── ASSINATURA ─────────────────────────────────────────────────────────────
    Em documentos curtos (receituários, atestados, laudos), preferimos assinatura
    em FLUXO NORMAL. Motivo: `position: fixed` do signature interage mal com
    wkhtmltopdf 0.12.6 quando o conteúdo principal tem blocos adicionais
    (ex.: observações livres em atestados via TinyMCE) — fragmenta o fluxo
    fazendo conteúdo aparecer DEPOIS do bloco de assinatura.
    Em fluxo normal, a assinatura sempre vem após o conteúdo completo.
-->
@include('pdf._signature', ['fixedPosition' => false])

{{-- Rodapé renderizado via --footer-html no MedicalRecordPdfService::buildDocumentationFooterFile() --}}

</body>
</html>
