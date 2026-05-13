@php
    /**
     * Preview PDF de modelo de documento.
     *
     * Usa dados fictícios (clínica demo, paciente demo, médico demo) para
     * demonstrar o layout sem depender de registros reais do banco.
     *
     * Variáveis recebidas:
     *   $reportSetting  App\Models\ReportSetting  (com activeContents carregado)
     */
    $s = $reportSetting;

    $fontFamily = $s->font_family ?? 'Arial';
    $fontSize   = ($s->font_size ?? 11) . 'pt';

    // ── Dados fictícios de demonstração ──────────────────────────────────
    $mockClinic  = 'Clínica Oftalmológica Demo';
    $mockAddress = 'Av. Paulista, 1234, Bela Vista — São Paulo/SP, CEP 01310-100';
    $mockPhone   = '(11) 3000-0000';
    $mockPatient = 'Maria Aparecida da Silva';
    $mockGender  = 'Feminino';
    $mockAge     = '45 anos';
    $mockDoctor  = 'Dr. João Carlos Mendes';
    $mockCrm     = 'CRM 12345/SP';
    $mockRqe     = 'RQE 5678';
    $mockDate    = \Illuminate\Support\Carbon::now()->isoFormat('LL');
    $mockCity    = 'São Paulo/SP';

    $contents = $s->activeContents ?? collect();
    $hasContents = $contents->isNotEmpty();
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>PREVIEW — {{ $s->title }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: {{ $fontFamily }}, Helvetica, sans-serif;
        font-size: {{ $fontSize }};
        color: #1a1a1a;
        line-height: 1.55;
    }

    /* ── Watermark ──────────────────────────────────────────────────────── */
    .watermark {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-35deg);
        font-size: 48pt;
        font-weight: bold;
        color: rgba(0,0,0,0.04);
        pointer-events: none;
        z-index: 0;
        letter-spacing: 10px;
        white-space: nowrap;
    }

    /* ── Header ─────────────────────────────────────────────────────────── */
    .header {
        display: table;
        width: 100%;
        border-bottom: 2px solid #1976d2;
        padding-bottom: 10px;
        margin-bottom: 18px;
    }
    .header-logo-cell {
        display: table-cell;
        width: 68px;
        vertical-align: middle;
    }
    .header-logo-placeholder {
        width: 58px;
        height: 52px;
        border: 1px dashed #90bce8;
        border-radius: 4px;
        background: #e8f1fb;
        color: #1976d2;
        font-size: 7.5pt;
        font-weight: bold;
        text-align: center;
        line-height: 52px;
        letter-spacing: 1px;
    }
    .header-info {
        display: table-cell;
        vertical-align: middle;
        padding-left: 10px;
    }
    .header-info h1 { font-size: 13pt; color: #1976d2; margin-bottom: 2px; }
    .header-info p  { font-size: 9pt; color: #666; margin-top: 2px; }

    /* ── Document title ──────────────────────────────────────────────────── */
    .doc-title-wrap {
        text-align: center;
        margin: 14px 0 18px;
    }
    .doc-title { font-size: 15pt; font-weight: bold; color: #212121; }

    /* ── Patient block ───────────────────────────────────────────────────── */
    .patient-block {
        background: #f5f9ff;
        border: 1px solid #d0e4f7;
        border-radius: 4px;
        padding: 8px 12px;
        margin-bottom: 18px;
        font-size: 10pt;
    }
    .patient-block strong { color: #1976d2; }

    /* ── Content body ────────────────────────────────────────────────────── */
    .content-body {
        min-height: 200px;
        font-size: {{ $fontSize }};
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }

    /* ── No content placeholder ──────────────────────────────────────────── */
    .content-placeholder {
        min-height: 200px;
        border: 1px dashed #aec4d8;
        border-radius: 4px;
        padding: 32px;
        color: #888;
        font-size: 10pt;
        text-align: center;
        background: repeating-linear-gradient(
            -45deg,
            #f8fafc,
            #f8fafc 8px,
            #f0f5fa 8px,
            #f0f5fa 16px
        );
    }

    /* ── Signature block ─────────────────────────────────────────────────── */
    .signature-spacer { height: 120px; }
    .signature-block {
        position: fixed;
        left: 0; right: 0; bottom: 30px;
        background: #fff;
        text-align: center;
        font-size: 9.5pt;
        padding: 0 10mm;
    }
    .signature-location { margin-bottom: 16px; color: #555; }
    .signature-inner {
        display: inline-block;
        min-width: 240px;
        text-align: center;
    }
    .signature-space { height: 90px; }
    .signature-line {
        border-top: 1px solid #333;
        width: 240px;
        margin: 0 auto 5px;
    }

    /* ── Page break ──────────────────────────────────────────────────────── */
    .page-break { page-break-after: always; }

    /* ── Meta info strip (topo) ──────────────────────────────────────────── */
    .meta-strip {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 4px;
        padding: 5px 10px;
        font-size: 8pt;
        color: #856404;
        margin-bottom: 14px;
        text-align: center;
    }
</style>
</head>
<body>

<div class="watermark">MODELO</div>

@php $totalPages = $hasContents ? $contents->count() : 1; @endphp

@if($hasContents)
    @foreach($contents as $content)
    @php $isLast = $loop->last; @endphp

    {{-- ── Faixa informativa de preview ─────────────────────────────────────── --}}
    <div class="meta-strip">
        PREVIEW — {{ $s->title }}
        &nbsp;·&nbsp; Página {{ $loop->iteration }} de {{ $totalPages }}
        &nbsp;·&nbsp; Tipo: {{ $content->display_label ?? $content->label }}
        @if($s->paper_size)&nbsp;·&nbsp; Papel: {{ $s->paper_size?->value ?? $s->paper_size }}@endif
    </div>

    {{-- ── Cabeçalho ──────────────────────────────────────────────────────────── --}}
    @if($s->show_header)
    <div class="header">
        @if($s->header_show_logo)
        <div class="header-logo-cell">
            <div class="header-logo-placeholder">LOGO</div>
        </div>
        @endif
        <div class="header-info">
            @if($s->header_show_name)
            <h1>{{ $mockClinic }}</h1>
            @endif
            @if($s->header_show_address)
            <p>{{ $mockAddress }}</p>
            @endif
            @if($s->header_show_phone)
            <p>{{ $mockPhone }}</p>
            @endif
        </div>
    </div>
    @endif

    {{-- ── Título do documento ──────────────────────────────────────────────── --}}
    <div class="doc-title-wrap">
        <div class="doc-title">{{ $content->display_label ?? $content->label }}</div>
    </div>

    {{-- ── Bloco do paciente ────────────────────────────────────────────────── --}}
    <div class="patient-block">
        <strong>Paciente:</strong> {{ $mockPatient }}
        &nbsp;|&nbsp; <strong>Gênero:</strong> {{ $mockGender }}
        &nbsp;|&nbsp; <strong>Idade:</strong> {{ $mockAge }}
        &nbsp;|&nbsp; <strong>Data:</strong> {{ \Illuminate\Support\Carbon::now()->isoFormat('L') }}
    </div>

    {{-- ── Corpo do conteúdo ────────────────────────────────────────────────── --}}
    <div class="content-body">{!! $content->content !!}</div>

    {{-- ── Assinatura ──────────────────────────────────────────────────────── --}}
    @if($s->show_signature)
    <div class="signature-spacer"></div>
    <div class="signature-block">
        <div class="signature-location">
            {{ $mockCity }}, {{ $mockDate }}.
        </div>
        <div class="signature-inner">
            <div class="signature-space"></div>
            <div class="signature-line"></div>
            @if($s->signature_show_name)<div>{{ $mockDoctor }}</div>@endif
            @if($s->signature_show_crm)<div>{{ $mockCrm }}</div>@endif
            @if($s->signature_show_rqe)<div>{{ $mockRqe }}</div>@endif
        </div>
    </div>
    @endif

    {{-- Rodapé renderizado via --footer-html no controller (não inline) --}}

    @if(!$isLast)
    <div class="page-break"></div>
    @endif

    @endforeach

@else
    {{-- Sem conteúdos ativos: página única com layout base ─────────────────── --}}
    <div class="meta-strip">
        PREVIEW — {{ $s->title }}
        &nbsp;·&nbsp; Nenhum conteúdo ativo cadastrado
        @if($s->paper_size)&nbsp;·&nbsp; Papel: {{ $s->paper_size?->value ?? $s->paper_size }}@endif
    </div>

    @if($s->show_header)
    <div class="header">
        @if($s->header_show_logo)
        <div class="header-logo-cell">
            <div class="header-logo-placeholder">LOGO</div>
        </div>
        @endif
        <div class="header-info">
            @if($s->header_show_name)<h1>{{ $mockClinic }}</h1>@endif
            @if($s->header_show_address)<p>{{ $mockAddress }}</p>@endif
            @if($s->header_show_phone)<p>{{ $mockPhone }}</p>@endif
        </div>
    </div>
    @endif

    <div class="doc-title-wrap">
        <div class="doc-title">{{ $s->title }}</div>
    </div>

    <div class="patient-block">
        <strong>Paciente:</strong> {{ $mockPatient }}
        &nbsp;|&nbsp; <strong>Gênero:</strong> {{ $mockGender }}
        &nbsp;|&nbsp; <strong>Idade:</strong> {{ $mockAge }}
        &nbsp;|&nbsp; <strong>Data:</strong> {{ \Illuminate\Support\Carbon::now()->isoFormat('L') }}
    </div>

    <div class="content-placeholder">
        <p style="font-size:32pt; margin-bottom:8px;">📄</p>
        <p style="font-weight:bold; margin-bottom:4px;">Nenhum conteúdo ativo</p>
        <p>Adicione seções de conteúdo ao modelo para visualizá-las aqui.</p>
    </div>

    @if($s->show_signature)
    <div class="signature-spacer"></div>
    <div class="signature-block">
        <div class="signature-location">{{ $mockCity }}, {{ $mockDate }}.</div>
        <div class="signature-inner">
            <div class="signature-space"></div>
            <div class="signature-line"></div>
            @if($s->signature_show_name)<div>{{ $mockDoctor }}</div>@endif
            @if($s->signature_show_crm)<div>{{ $mockCrm }}</div>@endif
            @if($s->signature_show_rqe)<div>{{ $mockRqe }}</div>@endif
        </div>
    </div>
    @endif

    @if($s->show_footer)
    <div class="footer-block">
        {{ $s->footer_text ?: $mockClinic }}
        @if($s->footer_show_address)&nbsp;·&nbsp; {{ $mockAddress }}@endif
        @if($s->footer_show_phone)&nbsp;·&nbsp; {{ $mockPhone }}@endif
        &nbsp;·&nbsp; Emitido em {{ \Illuminate\Support\Carbon::now()->isoFormat('L LT') }}
    </div>
    @endif
@endif

</body>
</html>
