{{--
    Partial: bloco de assinatura médica para todos os PDFs.

    Variáveis esperadas (todas opcionais):
      $doctor  — App\Models\Doctor (com 'person' carregado)
      $setting — App\Models\ReportSetting
--}}
@php
    $doctor  = $doctor  ?? null;
    $setting = $setting ?? null;

    if (!($setting?->show_signature ?? true)) return;

    // O que mostrar, respeitando os toggles do setting (default: tudo visível)
    $showName = $setting?->signature_show_name ?? true;
    $showCrm  = $setting?->signature_show_crm  ?? true;
    $showRqe  = $setting?->signature_show_rqe  ?? true;

    $name = $showName ? ($doctor?->person?->full_name ?? '') : '';
    $crm  = ($showCrm && $doctor?->record)           ? $doctor->record           : null;
    $rqe  = ($showRqe && $doctor?->record_specialty) ? $doctor->record_specialty : null;

    if (!$name && !$crm && !$rqe) return;
@endphp

<div class="signature-block">
    <div class="signature-line"></div>
    @if($name)<br>{{ $name }}@endif
    @if($crm)<br>CRM {{ $crm }}@endif
    @if($rqe)<br>RQE {{ $rqe }}@endif
</div>
