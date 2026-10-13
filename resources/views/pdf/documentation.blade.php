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
        font-family: {{ $setting?->font_family ?? 'Arial' }}, Helvetica, sans-serif;
        font-size: {{ $setting?->font_size ?? 11 }}pt;
        color: #1a1a1a;
        line-height: 1.55;
        /*
           Signature agora está em fluxo normal (não-fixed) — não precisamos
           reservar 130px de padding-bottom. O margin-bottom configurado já
           cobre o espaço necessário; o --footer-html (wkhtmltopdf option)
           renderiza no espaço de margem inferior reservado pelo PDF engine.
        */
        padding: {{ $setting?->margin_top ?? 20 }}mm {{ $setting?->margin_right ?? 15 }}mm
                 {{ $setting?->margin_bottom ?? 20 }}mm {{ $setting?->margin_left ?? 15 }}mm;
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
    .exams-block {
        border: 1px solid #d0e4f7;
        border-radius: 4px;
        padding: 6px 12px;
        margin-bottom: 18px;
        font-size: 9.5pt;
    }
    .exams-block strong { color: #1976d2; }
    .exams-block ul { margin: 4px 0 0; padding-left: 18px; }

    /* ── Content ─────────────────────────────────────────────────── */
    .content-body {
        min-height: 240px;
        word-break: break-word;
        font-size: {{ $setting?->font_size ?? 11 }}pt;
        line-height: 1.6;
    }
    .content-body p  { margin-bottom: 0.6em; }
    /* Listas (pedido de exames — Fase 2 / B2 —, receitas de pterígio e
       catarata): o reset global (* { padding: 0 }) escondia o marcador. */
    .content-body ul,
    .content-body ol { padding-left: 1.5em; margin-bottom: 0.6em; }
    .content-body li { margin-bottom: 0.2em; }
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

    /*
       Imagens embutidas no laudo (Fase 5 / 5c) — <table>, não CSS grid: o
       QtWebKit do wkhtmltopdf 0.12.6 não suporta display:grid de forma
       confiável (motor congelado ~2013). <table> é a única garantia de
       grid funcionando em qualquer build do wkhtmltopdf.
    */
    .pmr-image-report {
        margin-top: 1.5em;
        padding-top: 0.8em;
        border-top: 1px solid #999;
    }
    .pmr-image-report-table { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
    .pmr-image-report-table td { padding: 4px; vertical-align: top; text-align: center; }
    .pmr-image-report-table img { width: 100%; height: auto; display: block; border: 1px solid #ddd; }
    .pmr-image-report-caption { font-size: 8pt; color: #333; margin-top: 2px; }

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
    // patient->entity ANTES da sessão: documento sem schedule (ex.: laudo de
    // imagem de exame importado manualmente) é visto também por quem não tem
    // clínica selecionada (link público pro médico solicitante) — sessão
    // vazia ou de OUTRA clínica perdia logo/nome/endereço do cabeçalho, ou
    // pior, vazava o de outra clínica (achado via revisão Codex; mesmo
    // ajuste do $entityId em MedicalRecordPdfService::generateDocumentation(),
    // que NÃO passa $entity pra esta view — o rodapé usa o dele, o cabeçalho
    // usa este, resolvidos de forma independente).
    $entity  = $doc->medicalRecord?->schedule?->entity
            ?? $patient?->entity
            ?? \App\Models\Entity::find(session('selected_entity_id'));
@endphp

<!-- ─── CABEÇALHO ─────────────────────────────────────────────────────────── -->
@php
    // Usa show_header (campo novo do formulário). Fallback para show_logo (campo
    // legado) para compatibilidade com registros gerados antes da migração de campos.
    $showHeader = $setting?->show_header ?? $setting?->show_logo ?? false;
@endphp
@if($showHeader)
<div class="header">
    @if($setting?->header_show_logo ?? true)
    <div class="header-logo">
        @if($entity?->logo_path)
        <img src="{{ public_path('storage/' . $entity->logo_path) }}" alt="">
        @endif
    </div>
    @endif
    <div class="header-info">
        @if($setting?->header_show_name ?? true)
        <h1>{{ $entity?->name }}</h1>
        @endif
        @if(($setting?->header_show_address ?? false) && $entity?->address_full)
        <p>{{ $entity->address_full }}</p>
        @endif
        @if(($setting?->header_show_phone ?? false) && ($entity?->telephone || $entity?->cellphone))
        <p>{{ \App\Support\BrazilianFormat::phone($entity->telephone ?: $entity->cellphone) }}</p>
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
    @if($setting?->patient_birth && $patient->person->birth_date)
     &nbsp;|&nbsp; <strong>{{ __('pdf.birth') }}:</strong> {{ $patient->person->birth_date->isoFormat('L') }}
    @endif
    @if($setting?->patient_address && $patient->person->address_full)
     &nbsp;|&nbsp; {{ $patient->person->address_full }}
    @endif
    {{-- Data IMPRESSA: a alternativa escolhida pelo médico ou a da emissão
         (Fase 2 / B3) — reimprimir não troca pela data de hoje. --}}
    &nbsp;|&nbsp; <strong>{{ __('pdf.date') }}:</strong> {{ $documentation->printedDate()->isoFormat('L') }}
</div>

<!-- ─── INDICAÇÃO CLÍNICA (laudo de imagem — Fase 5) ─────────────────────── -->
@if($documentation->imageReportClinicalIndication())
<div class="patient-block">
    <strong>{{ __('eye_images.report_clinical_indication_label') }}:</strong>
    {{ $documentation->imageReportClinicalIndication() }}
</div>
@endif

<!-- ─── EXAMES DO LAUDO ──────────────────────────────────────────────────────
     Laudo do Gerenciador de Imagens: registra quais exames fizeram parte
     (um laudo pode cobrir vários exames do mesmo paciente). Ver
     App\Services\EyeImages\ReportExamSummary. -->
@if(! empty($examSummary))
<div class="exams-block">
    <strong>{{ __('eye_images.report_exams_title') }}:</strong>
    <ul>
        @foreach($examSummary as $exam)
        <li>
            {{ $exam['type'] ?? '—' }}
            @if($exam['date']) — {{ $exam['date'] }}@endif
            @if($exam['eyes']) — {{ implode(', ', $exam['eyes']) }}@endif
            @if($exam['equipment']) — {{ $exam['equipment'] }}@endif
            ({{ trans_choice('eye_images.report_exams_images', $exam['images'], ['count' => $exam['images']]) }})
        </li>
        @endforeach
    </ul>
</div>
@endif

<!-- ─── CONTEÚDO ─────────────────────────────────────────────────────────────
     `contentForRender()` remove o cabeçalho de data resolvido (CIDADE, DD de MÊS de YYYY)
     do topo do conteúdo. A data continua aparecendo no bloco de assinatura abaixo —
     evita duplicação visual e mantém o banco intocado (auditoria preservada).
-->
<div class="content-body">{!! $documentation->contentForRender() !!}</div>

<!-- ─── OBSERVAÇÕES (laudo de imagem — Fase 5) ───────────────────────────── -->
@if($documentation->imageReportObservations())
<div class="pmr-observations">
    <strong>{{ __('eye_images.report_observations_label') }}:</strong>
    <p>{{ $documentation->imageReportObservations() }}</p>
</div>
@endif

<!-- ─── IMAGENS NO LAUDO (Fase 5 / 5c) ─────────────────────────────────────
     Resolvido de novo a cada render (ReportImageBlockBuilder, chamado por
     MedicalRecordPdfService) — nunca um bloco "congelado": exame excluído/
     desabilitado desde a emissão simplesmente não aparece na reimpressão. -->
@if(! empty($imageBlock))
<div class="pmr-image-report">
    <strong>{{ __('eye_images.report_image_block_title') }}</strong>
    <table class="pmr-image-report-table">
        @foreach(array_chunk($imageBlock['images'], $imageBlock['columns']) as $row)
        <tr>
            @foreach($row as $image)
            <td>
                <img src="{{ $image['data_uri'] }}" alt="">
                @if($image['caption'])
                <div class="pmr-image-report-caption">{{ $image['caption'] }}</div>
                @endif
            </td>
            @endforeach
            @for($i = count($row); $i < $imageBlock['columns']; $i++)
            <td></td>
            @endfor
        </tr>
        @endforeach
    </table>
</div>
@endif

<!--
    ─── ASSINATURA ─────────────────────────────────────────────────────────────
    Em documentos curtos (receituários, atestados, laudos), preferimos assinatura
    em FLUXO NORMAL. Motivo: `position: fixed` do signature interage mal com
    wkhtmltopdf 0.12.6 quando o conteúdo principal tem blocos adicionais
    (ex.: observações livres em atestados via TinyMCE) — fragmenta o fluxo
    fazendo conteúdo aparecer DEPOIS do bloco de assinatura.
    Em fluxo normal, a assinatura sempre vem após o conteúdo completo.
-->
@include('pdf._signature', ['fixedPosition' => false, 'documentDate' => $documentation->printedDate()])

{{-- Rodapé renderizado via --footer-html no MedicalRecordPdfService::buildDocumentationFooterFile() --}}

</body>
</html>
