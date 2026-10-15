<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('surgery_operation.pdf.labels_title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9px; color: #111827; margin: 0; }
        table.sheet { width: 100%; border-collapse: separate; border-spacing: 6px; table-layout: fixed; }
        td.label { border: 1px solid #6b7280; border-radius: 3px; padding: 5px 7px; vertical-align: top; height: 3.4cm; page-break-inside: avoid; }
        .title { font-weight: bold; font-size: 10px; margin-bottom: 2px; }
        .kv { margin: 0; line-height: 1.35; }
        .kv b { font-weight: 700; }
        .foot { margin-top: 3px; color: #4b5563; font-size: 8px; }
    </style>
</head>
<body>
@php
    $implant = $implants[0];
    $patient = (array) ($header['patient'] ?? []);
    $surgeon = (array) ($header['surgeon'] ?? []);
    $caseHeader = (array) ($header['case'] ?? []);
    $perRow  = 2;
    $cells   = array_fill(0, $count, true);
    $chunks  = array_chunk($cells, $perRow);
@endphp
<table class="sheet">
    @foreach($chunks as $chunk)
        <tr>
            @foreach($chunk as $_)
                <td class="label">
                    <div class="title">{{ $implant->product_name }}</div>
                    @foreach($rows as $row)
                        @if(in_array($row['label'], [__('surgery_operation.fields.manufacturer'), __('surgery_operation.fields.model_reference'), __('surgery_operation.fields.power'), __('surgery_operation.fields.lot'), __('surgery_operation.fields.serial'), __('surgery_operation.fields.expiry'), __('surgery_operation.fields.anvisa')], true))
                            <p class="kv"><b>{{ $row['label'] }}:</b> {{ $row['value'] }}</p>
                        @endif
                    @endforeach
                    <div class="foot">
                        {{ $patient['name'] ?? '' }} · {{ $caseHeader['code'] ?? '' }} · {{ $implant->implanted_at?->format((string) __('surgery_operation.date_format')) }}
                        · {{ $surgeon['name'] ?? '' }} {{ $surgeon['registry'] ?? '' }}
                    </div>
                </td>
            @endforeach
            @for($i = count($chunk); $i < $perRow; $i++)
                <td></td>
            @endfor
        </tr>
    @endforeach
</table>
</body>
</html>
