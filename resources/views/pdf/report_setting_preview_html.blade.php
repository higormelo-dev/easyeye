@php
/**
 * Preview HTML do modelo de documento para exibição no modal.
 * Usa flexbox + dimensões reais do papel em mm para centralizar a página
 * de forma consistente entre A4, A5, Letter e Legal.
 */
use App\Enums\PaperSize;

$s = $reportSetting;

$paperSize  = $s->paper_size instanceof PaperSize ? $s->paper_size : PaperSize::tryFrom((string) $s->paper_size) ?? PaperSize::A5;
[$pw, $ph]  = $paperSize->dimensions(); // largura e altura em mm

$fontFamily = $s->font_family ?? 'Arial, Helvetica, sans-serif';
$fontSize   = ($s->font_size ?? 11) . 'pt';
$mTop       = (float) ($s->margin_top    ?? 0);
$mRight     = (float) ($s->margin_right  ?? 1.5);
$mBottom    = (float) ($s->margin_bottom ?? 0);
$mLeft      = (float) ($s->margin_left   ?? 1.5);

$mockClinic  = 'Clínica Oftalmológica Demo';
$mockAddress = 'Av. Paulista, 1234 — São Paulo/SP, CEP 01310-100';
$mockPhone   = '(11) 3000-0000';
$mockPatient = 'Maria Aparecida da Silva';
$mockDoctor  = 'Dr. João Carlos Mendes';
$mockCrm     = '12345/SP';
$mockRqe     = '5678';
$mockDate    = \Illuminate\Support\Carbon::now()->isoFormat('LL');
$mockCity    = 'São Paulo/SP';

$contents   = $s->activeContents ?? collect();
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preview — {{ $s->title }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
        background: #525659;
        min-height: 100%;
    }

    body {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 28px 20px 40px;
        gap: 28px;
    }

    /* ── Página (folha branca centralizada) ──────────────────────────── */
    .page {
        background: #fff;
        width: {{ $pw }}mm;
        min-height: {{ $ph }}mm;
        box-shadow: 0 4px 24px rgba(0,0,0,0.5);
        display: flex;
        flex-direction: column;
        position: relative;
        overflow: hidden;
        font-family: {{ $fontFamily }};
        font-size: {{ $fontSize }};
        color: #1a1a1a;
        line-height: 1.55;
    }

    /* ── Watermark ───────────────────────────────────────────────────── */
    .watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-35deg);
        font-size: 52pt;
        font-weight: bold;
        color: rgba(0,0,0,0.04);
        letter-spacing: 10px;
        white-space: nowrap;
        pointer-events: none;
        z-index: 0;
        user-select: none;
    }

    /* ── Faixa de preview ────────────────────────────────────────────── */
    .meta-strip {
        background: #fff3cd;
        border-bottom: 1px solid #ffc107;
        padding: 5px {{ $mRight }}cm;
        font-size: 8pt;
        color: #856404;
        text-align: center;
        flex-shrink: 0;
        position: relative;
        z-index: 1;
    }

    /* ── Header ──────────────────────────────────────────────────────── */
    .header {
        display: flex;
        align-items: center;
        gap: 12px;
        border-bottom: 2px solid #1976d2;
        padding: 10px {{ $mRight }}cm 10px {{ $mLeft }}cm;
        flex-shrink: 0;
        position: relative;
        z-index: 1;
    }
    .header-logo-placeholder {
        width: 54px; height: 48px;
        border: 1px dashed #90bce8;
        border-radius: 4px;
        background: #e8f1fb;
        color: #1976d2;
        font-size: 7pt;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .header-info h1 { font-size: 13pt; color: #1976d2; margin-bottom: 2px; }
    .header-info p  { font-size: 9pt; color: #666; }

    /* ── Conteúdo da página ──────────────────────────────────────────── */
    .page-body {
        flex: 1;
        padding: 14px {{ $mRight }}cm 10px {{ $mLeft }}cm;
        position: relative;
        z-index: 1;
    }

    /* ── Título do documento ─────────────────────────────────────────── */
    .doc-title {
        text-align: center;
        font-size: 15pt;
        font-weight: bold;
        color: #212121;
        margin-bottom: 14px;
    }

    /* ── Bloco do paciente ───────────────────────────────────────────── */
    .patient-block {
        background: #f5f9ff;
        border: 1px solid #d0e4f7;
        border-radius: 4px;
        padding: 7px 12px;
        margin-bottom: 14px;
        font-size: 9.5pt;
    }
    .patient-block strong { color: #1976d2; }

    /* ── Corpo do documento ──────────────────────────────────────────── */
    .content-body {
        font-size: {{ $fontSize }};
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
        min-height: 60px;
    }

    /* ── Placeholder sem conteúdo ────────────────────────────────────── */
    .content-placeholder {
        border: 1px dashed #b0c8df;
        border-radius: 4px;
        padding: 24px;
        color: #999;
        font-size: 9.5pt;
        text-align: center;
        background: repeating-linear-gradient(-45deg,#f8fafc,#f8fafc 8px,#eef4fa 8px,#eef4fa 16px);
        min-height: 80px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
    }

    /* ── Assinatura (fluxo normal, empurrada para o rodapé) ──────────── */
    .signature-area {
        padding: 0 {{ $mRight }}cm 10px {{ $mLeft }}cm;
        text-align: center;
        font-size: 9.5pt;
        position: relative;
        z-index: 1;
    }
    .signature-location { margin-bottom: 14px; color: #555; }
    .signature-space    { height: 90px; }
    .signature-line     { border-top: 1px solid #333; width: 220px; margin: 0 auto 5px; }

    /* ── Rodapé ──────────────────────────────────────────────────────── */
    .footer-area {
        padding: 5px {{ $mRight }}cm;
        font-size: 8pt;
        color: #aaa;
        text-align: center;
        border-top: 1px solid #e0e0e0;
        flex-shrink: 0;
        position: relative;
        z-index: 1;
    }
</style>
</head>
<body>

@php $total = $contents->isNotEmpty() ? $contents->count() : 1; @endphp

@if($contents->isNotEmpty())
    @foreach($contents as $content)
    <div class="page">
        <div class="watermark">MODELO</div>

        {{-- Faixa de identificação --}}
        <div class="meta-strip">
            PREVIEW &nbsp;·&nbsp; {{ $s->title }}
            &nbsp;·&nbsp; {{ $content->display_label ?? $content->label }}
            &nbsp;·&nbsp; Pág. {{ $loop->iteration }}/{{ $total }}
            &nbsp;·&nbsp; {{ $paperSize->value }}
        </div>

        {{-- Cabeçalho --}}
        @if($s->show_header)
        <div class="header">
            @if($s->header_show_logo)
            <div class="header-logo-placeholder">LOGO</div>
            @endif
            <div class="header-info">
                @if($s->header_show_name)<h1>{{ $mockClinic }}</h1>@endif
                @if($s->header_show_address)<p>{{ $mockAddress }}</p>@endif
                @if($s->header_show_phone)<p>{{ $mockPhone }}</p>@endif
            </div>
        </div>
        @endif

        {{-- Corpo --}}
        <div class="page-body">
            <div class="doc-title">{{ $content->display_label ?? $content->label }}</div>
            <div class="patient-block">
                <strong>Paciente:</strong> {{ $mockPatient }}
                &nbsp;|&nbsp; <strong>Gênero:</strong> Feminino
                &nbsp;|&nbsp; <strong>Idade:</strong> 45 anos
                &nbsp;|&nbsp; <strong>Data:</strong> {{ \Illuminate\Support\Carbon::now()->isoFormat('L') }}
            </div>
            <div class="content-body">{!! $content->content !!}</div>
        </div>

        {{-- Assinatura --}}
        @if($s->show_signature)
        <div class="signature-area">
            <div class="signature-location">{{ $mockCity }}, {{ $mockDate }}.</div>
            <div class="signature-space"></div>
            <div class="signature-line"></div>
            @if($s->signature_show_name)<div>{{ $mockDoctor }}</div>@endif
            @if($s->signature_show_crm)<div>CRM {{ $mockCrm }}</div>@endif
            @if($s->signature_show_rqe)<div>RQE {{ $mockRqe }}</div>@endif
        </div>
        @endif

        {{-- Rodapé --}}
        @if($s->show_footer)
        <div class="footer-area">
            {{ $s->footer_text ?: $mockClinic }}
            @if($s->footer_show_address)&nbsp;·&nbsp; {{ $mockAddress }}@endif
            @if($s->footer_show_phone)&nbsp;·&nbsp; {{ $mockPhone }}@endif
            &nbsp;·&nbsp; Emitido em {{ \Illuminate\Support\Carbon::now()->isoFormat('L LT') }}
        </div>
        @endif
    </div>
    @endforeach

@else
    {{-- Nenhum conteúdo ativo --}}
    <div class="page">
        <div class="watermark">MODELO</div>
        <div class="meta-strip">PREVIEW &nbsp;·&nbsp; {{ $s->title }} &nbsp;·&nbsp; Nenhum conteúdo ativo</div>

        @if($s->show_header)
        <div class="header">
            @if($s->header_show_logo)<div class="header-logo-placeholder">LOGO</div>@endif
            <div class="header-info">
                @if($s->header_show_name)<h1>{{ $mockClinic }}</h1>@endif
                @if($s->header_show_address)<p>{{ $mockAddress }}</p>@endif
                @if($s->header_show_phone)<p>{{ $mockPhone }}</p>@endif
            </div>
        </div>
        @endif

        <div class="page-body">
            <div class="doc-title">{{ $s->title }}</div>
            <div class="patient-block">
                <strong>Paciente:</strong> {{ $mockPatient }}
                &nbsp;|&nbsp; <strong>Data:</strong> {{ \Illuminate\Support\Carbon::now()->isoFormat('L') }}
            </div>
            <div class="content-placeholder">
                <div style="font-size:24pt;">📄</div>
                <strong>Nenhum conteúdo ativo</strong>
                <small>Adicione seções ao modelo para visualizá-las aqui.</small>
            </div>
        </div>

        @if($s->show_signature)
        <div class="signature-area">
            <div class="signature-location">{{ $mockCity }}, {{ $mockDate }}.</div>
            <div class="signature-space"></div>
            <div class="signature-line"></div>
            @if($s->signature_show_name)<div>{{ $mockDoctor }}</div>@endif
            @if($s->signature_show_crm)<div>CRM {{ $mockCrm }}</div>@endif
            @if($s->signature_show_rqe)<div>RQE {{ $mockRqe }}</div>@endif
        </div>
        @endif

        @if($s->show_footer)
        <div class="footer-area">
            {{ $s->footer_text ?: $mockClinic }}
            @if($s->footer_show_address)&nbsp;·&nbsp; {{ $mockAddress }}@endif
            @if($s->footer_show_phone)&nbsp;·&nbsp; {{ $mockPhone }}@endif
        </div>
        @endif
    </div>
@endif

</body>
</html>
