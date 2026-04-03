<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Laudo de Tonômetria</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: {{ $setting?->font_family ?? 'Arial' }}, Helvetica, sans-serif;
        font-size: {{ $setting?->font_size ?? 11 }}pt;
        color: #1a1a1a;
        line-height: 1.6;
    }
    .header {
        display: table; width: 100%;
        border-bottom: 2px solid #1976d2;
        padding-bottom: 10px; margin-bottom: 24px;
    }
    .header-logo { display: table-cell; width: 70px; vertical-align: middle; }
    .header-logo img { max-height: 55px; }
    .header-info { display: table-cell; vertical-align: middle; padding-left: 12px; }
    .header-info h1 { font-size: 13pt; color: #1976d2; }
    .header-info p  { font-size: 9pt; color: #666; margin-top: 3px; }
    .patient-block {
        background: #f5f9ff; border: 1px solid #d0e4f7;
        border-radius: 4px; padding: 8px 12px;
        margin-bottom: 24px; font-size: 10pt;
    }
    .patient-block strong { color: #1976d2; }
    .doc-title {
        text-align: center; font-size: 15pt; font-weight: bold;
        color: #c62828; text-transform: uppercase;
        letter-spacing: 0.05em; margin-bottom: 28px;
    }
    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 32px; }
    .data-table td { padding: 10px 16px; font-size: 12pt; border-bottom: 1px solid #e0e0e0; }
    .data-table td:first-child { color: #555; width: 50%; }
    .data-table td:last-child { font-weight: bold; }
    .timestamp { text-align: right; font-size: 9pt; color: #888; margin-bottom: 32px; }
    .signature-block { margin-top: 48px; text-align: center; font-size: 9.5pt; }
    .signature-line  { border-top: 1px solid #333; display: inline-block; width: 200px; margin-bottom: 4px; }
    .footer {
        position: fixed; bottom: 0; left: 0; right: 0;
        font-size: 8pt; color: #aaa; text-align: center;
        padding-top: 4px; border-top: 1px solid #e0e0e0;
    }
</style>
</head>
<body>

<!-- ─── CABEÇALHO ─────────────────────────────────────────────────────────── -->
@if($setting?->show_logo && $entity?->logo_path)
<div class="header">
    <div class="header-logo">
        <img src="{{ public_path('storage/' . $entity->logo_path) }}" alt="">
    </div>
    <div class="header-info">
        <h1>{{ $entity->name }}</h1>
        <p>{{ $entity->address_full ?? '' }}</p>
    </div>
</div>
@elseif($entity)
<div class="header">
    <div class="header-info">
        <h1>{{ $entity->name }}</h1>
        <p>{{ $entity->address_full ?? '' }}</p>
    </div>
</div>
@endif

<!-- ─── TÍTULO ─────────────────────────────────────────────────────────────── -->
<div class="doc-title">Laudo de Tonômetria</div>

<!-- ─── DADOS DO PACIENTE ─────────────────────────────────────────────────── -->
<div class="patient-block">
    <strong>Paciente:</strong> {{ $patient->person->full_name }}
    @if($patient->person->birth_date)
     &nbsp;|&nbsp; <strong>Nascimento:</strong> {{ $patient->person->birth_date->format('d/m/Y') }}
    @endif
    &nbsp;|&nbsp; <strong>Data:</strong> {{ now()->format('d/m/Y') }}
</div>

<!-- ─── RESULTADOS ─────────────────────────────────────────────────────────── -->
<table class="data-table">
    <tr>
        <td>Olho Direito</td>
        <td>{{ $od ? $od . ' mmHg' : '—' }}</td>
    </tr>
    <tr>
        <td>Olho Esquerdo</td>
        <td>{{ $oe ? $oe . ' mmHg' : '—' }}</td>
    </tr>
</table>

<!-- ─── CIDADE + HORÁRIO ───────────────────────────────────────────────────── -->
<div class="timestamp">
    @php
        $loc = collect([$entity?->city, $entity?->state])->filter()->implode('/');
    @endphp
    {{ $loc ? $loc . ', ' : '' }}{{ now()->format('d/m/Y') }} {{ $time }}.
</div>

<!-- ─── ASSINATURA ─────────────────────────────────────────────────────────── -->
@include('pdf._signature')

<!-- ─── RODAPÉ ─────────────────────────────────────────────────────────────── -->
@if($setting?->show_footer)
<div class="footer">{{ $setting->footer_text ?? $entity?->name }}</div>
@endif

</body>
</html>
