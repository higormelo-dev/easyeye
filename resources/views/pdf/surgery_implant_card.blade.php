<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('surgery_operation.pdf.card_title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111827; }
        .card { border: 2px solid #0d6efd; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; page-break-inside: avoid; }
        .head { border-bottom: 1px solid #d1d5db; padding-bottom: 6px; margin-bottom: 8px; }
        .clinic { font-weight: bold; color: #0d6efd; font-size: 13px; }
        h1 { font-size: 15px; margin: 4px 0 0; text-transform: uppercase; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv th { width: 34%; text-align: left; color: #6b7280; font-weight: normal; padding: 2px 0; vertical-align: top; }
        table.kv td { padding: 2px 0; font-weight: 600; }
        .note { margin-top: 8px; font-size: 9.5px; color: #374151; }
    </style>
</head>
<body>
@php
    $clinic  = (array) ($header['clinic'] ?? []);
    $patient = (array) ($header['patient'] ?? []);
    $surgeon = (array) ($header['surgeon'] ?? []);
    $birth   = ! empty($patient['birth_date']) ? \Illuminate\Support\Carbon::parse($patient['birth_date'])->format((string) __('surgery_operation.date_format')) : null;
@endphp
@foreach($cards as $card)
    <div class="card">
        <div class="head">
            <div class="clinic">{{ $clinic['name'] ?? '' }}</div>
            <div>{{ $clinic['address'] ?? '' }} @if(! empty($clinic['phone'])) · {{ $clinic['phone'] }} @endif</div>
            <h1>{{ __('surgery_operation.pdf.card_title') }}</h1>
        </div>
        <table class="kv">
            <tr><th>{{ __('surgery_operation.pdf.patient') }}</th><td>{{ $patient['name'] ?? '' }}@if($birth) · {{ $birth }}@endif</td></tr>
            @foreach($card['rows'] as $row)
                <tr><th>{{ $row['label'] }}</th><td>{{ $row['value'] }}</td></tr>
            @endforeach
            <tr><th>{{ __('surgery_operation.pdf.surgeon') }}</th><td>{{ $surgeon['name'] ?? '' }} {{ $surgeon['registry'] ?? '' }}</td></tr>
        </table>
        <div class="note">{{ __('surgery_operation.pdf.card_note') }}</div>
    </div>
@endforeach
</body>
</html>
