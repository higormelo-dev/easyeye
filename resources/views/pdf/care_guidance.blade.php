<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<title>{{ $issue->title }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
        font-family: {{ $setting?->font_family ?? 'Arial' }}, Helvetica, sans-serif;
        font-size: {{ $setting?->font_size ?? 11 }}pt;
        color: #1a1a1a;
        line-height: 1.55;
    }
    .header { display: table; width: 100%; border-bottom: 2px solid #1976d2; padding-bottom: 10px; margin-bottom: 18px; }
    .header-logo { display: table-cell; width: 70px; vertical-align: middle; }
    .header-logo img { max-height: 55px; }
    .header-info { display: table-cell; vertical-align: middle; padding-left: 12px; }
    .header-info h1 { font-size: 13pt; color: #1976d2; }
    .header-info p { font-size: 9pt; color: #666; margin-top: 3px; }
    .patient-block { background: #f5f9ff; border: 1px solid #d0e4f7; border-radius: 4px; padding: 8px 12px; margin-bottom: 18px; font-size: 10pt; }
    .patient-block strong { color: #1976d2; }
    .doc-title { text-align: center; font-size: 15pt; font-weight: bold; color: #1565c0; margin-bottom: 14px; }
    .meta { font-size: 10pt; margin-bottom: 14px; }
    .meta strong { color: #333; }
    .body { white-space: normal; margin-bottom: 18px; }
    h2 { font-size: 11.5pt; color: #1565c0; margin: 16px 0 6px; }
    table.drops { width: 100%; border-collapse: collapse; font-size: 10pt; }
    table.drops th, table.drops td { border: 1px solid #cfd8dc; padding: 5px 8px; text-align: left; vertical-align: top; }
    table.drops th { background: #eef4fb; }
    ul.files { margin-left: 18px; font-size: 10pt; }
</style>
</head>
<body>
@php
    $patient->loadMissing('person');
@endphp

@if($entity)
<div class="header">
    @if($setting?->show_logo && $entity->logo_path)
    <div class="header-logo"><img src="{{ public_path('storage/' . $entity->logo_path) }}" alt=""></div>
    @endif
    <div class="header-info">
        <h1>{{ $entity->name }}</h1>
        <p>{{ $entity->address_full ?? '' }}</p>
    </div>
</div>
@endif

<div class="doc-title">{{ $issue->title }}</div>

<div class="patient-block">
    <strong>{{ __('pdf.patient') }}:</strong> {{ $patient->person->full_name }}
    @if($patient->person->birth_date)
     &nbsp;|&nbsp; <strong>{{ __('pdf.birth') }}:</strong> {{ $patient->person->birth_date->isoFormat('L') }}
    @endif
    &nbsp;|&nbsp; {{ __('care_guidance.pdf.issued_on', ['date' => $issue->issued_at->isoFormat('L')]) }}
</div>

<div class="meta">
    @if($issue->eye)
    <div><strong>{{ __('care_guidance.pdf.eye') }}:</strong> {{ __('care_guidance.pdf.eyes.' . $issue->eye) }}</div>
    @endif
    @if($issue->location_label)
    <div><strong>{{ __('care_guidance.pdf.location') }}:</strong> {{ $issue->location_label }}</div>
    @endif
</div>

<div class="body">{!! nl2br(e($issue->body)) !!}</div>

@if(! empty($issue->eye_drops))
<h2>{{ __('care_guidance.pdf.eye_drops') }}</h2>
<table class="drops">
    <thead>
        <tr>
            <th>{{ __('care_guidance.pdf.drop_name') }}</th>
            <th>{{ __('care_guidance.pdf.drop_how') }}</th>
            <th>{{ __('care_guidance.pdf.drop_times') }}</th>
            <th>{{ __('care_guidance.pdf.drop_days') }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach($issue->eye_drops as $drop)
        <tr>
            <td>{{ $drop['name'] }}</td>
            <td>{{ $drop['how'] ?? '—' }}</td>
            <td>{{ $drop['times_per_day'] ?? '—' }}</td>
            <td>{{ $drop['days'] ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

@if(! empty($issue->attachments))
<h2>{{ __('care_guidance.pdf.attachments') }}</h2>
<ul class="files">
    @foreach($issue->attachments as $file)
    <li>{{ $file['name'] }}</li>
    @endforeach
</ul>
@endif

@include('pdf._signature', ['fixedPosition' => false, 'documentDate' => $issue->issued_at])

</body>
</html>
