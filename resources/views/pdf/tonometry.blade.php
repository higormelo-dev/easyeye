<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<title>{{ __('pdf.title.tonometry') }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
        font-family: {{ $setting?->font_family ?? 'Arial' }}, Helvetica, sans-serif;
        font-size: {{ $setting?->font_size ?? 11 }}pt;
        color: #1a1a1a;
        line-height: 1.6;
        min-height: 180mm;
        display: flex;
        flex-direction: column;
    }
    .page-content { flex: 1; }
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

</style>
</head>
<body>
@php
    $patient = $patient ?? ($record->patient ?? null);
    $doctor  = $doctor  ?? ($record->doctor  ?? null);
    $entity  = $entity  ?? ($record->schedule?->entity
                         ?? \App\Models\Entity::find(session('selected_entity_id')));
@endphp

<div class="page-content">

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
<div class="doc-title">{{ __('pdf.title.tonometry') }}</div>

<!-- ─── DADOS DO PACIENTE ─────────────────────────────────────────────────── -->
<div class="patient-block">
    <strong>{{ __('pdf.patient') }}:</strong> {{ $patient->person->full_name }}
    @if($patient->person->gender_label)
     &nbsp;|&nbsp; <strong>{{ __('pdf.gender') }}:</strong> {{ $patient->person->gender_label }}
    @endif
    @if($patient->person->age !== null)
     &nbsp;|&nbsp; <strong>{{ __('pdf.age') }}:</strong> {{ __('pdf.age_years', ['years' => $patient->person->age]) }}
    @endif
    @if($patient->person->birth_date)
     &nbsp;|&nbsp; <strong>{{ __('pdf.birth') }}:</strong> {{ $patient->person->birth_date->isoFormat('L') }}
    @endif
    &nbsp;|&nbsp; <strong>{{ __('pdf.date') }}:</strong> {{ \Illuminate\Support\Carbon::now()->isoFormat('L') }}
</div>

<!-- ─── RESULTADOS ─────────────────────────────────────────────────────────── -->
<table class="data-table">
    <tr>
        <td>{{ __('pdf.right_eye') }}</td>
        <td>{{ $od ? $od . ' mmHg' : '—' }}</td>
    </tr>
    <tr>
        <td>{{ __('pdf.left_eye') }}</td>
        <td>{{ $oe ? $oe . ' mmHg' : '—' }}</td>
    </tr>
    <tr>
        <td>{{ __('pdf.time_label') }}</td>
        <td>{{ $time }}</td>
    </tr>
</table>

</div>{{-- .page-content --}}

<!-- ─── ASSINATURA + RODAPÉ (empurrados ao final da página pelo flex) ─────── -->
@include('pdf._signature', ['fixedPosition' => false])

</body>
</html>
