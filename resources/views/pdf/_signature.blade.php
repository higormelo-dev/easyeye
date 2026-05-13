{{--
    Partial: bloco de assinatura médica para todos os PDFs.

    Variáveis esperadas (todas opcionais):
      $doctor        — App\Models\Doctor (com 'person' carregado)
      $entity        — App\Models\Entity (p/ localização — opcional)
      $setting       — App\Models\ReportSetting
      $fixedPosition — bool (default: true). false = fluxo normal (ex: tonometria com layout flex)

    Posicionamento:
      true  → position: fixed; bottom: 30px (ancora ao rodapé da página).
      false → fluxo normal com padding-top para separação (use com body flex-column).
--}}
@php
    $doctor        = $doctor        ?? null;
    $entity        = $entity        ?? null;
    $setting       = $setting       ?? null;
    $fixedPosition = $fixedPosition ?? true;

    if (!($setting?->show_signature ?? true)) return;

    $showName = $setting?->signature_show_name ?? true;
    $showCrm  = $setting?->signature_show_crm  ?? true;
    $showRqe  = $setting?->signature_show_rqe  ?? true;
    $showLoc  = $setting?->signature_show_location ?? true;

    $name = $showName ? ($doctor?->person?->full_name ?? '') : '';
    $crm  = ($showCrm && $doctor?->record)           ? $doctor->record           : null;
    $rqe  = ($showRqe && $doctor?->record_specialty) ? $doctor->record_specialty : null;

    if (!$name && !$crm && !$rqe) return;

    $locLine = '';
    if ($showLoc) {
        /** @var \App\Services\LocationFormatter $formatter */
        $formatter = app(\App\Services\LocationFormatter::class);
        $loc       = $formatter->format($entity);
        $datePart  = \Illuminate\Support\Carbon::now()->isoFormat('LL');
        $locLine   = trim($loc ? "{$loc}, {$datePart}" : $datePart) . '.';
    }
@endphp

@if($fixedPosition)
{{-- Spacer p/ que conteúdos longos não passem por baixo da assinatura ancorada. --}}
<div style="height:130px;"></div>
@endif

<div style="
    @if($fixedPosition)
        position: fixed; left: 0; right: 0; bottom: 30px; background: #fff;
    @else
        padding-top: 48px;
    @endif
    text-align: center; font-size: 9.5pt; padding-left: 10mm; padding-right: 10mm;
">
    @if($locLine)
    <div style="margin-bottom:18px;">{{ $locLine }}</div>
    @endif

    <div style="display:inline-block; min-width:240px; text-align:center;">
        <div style="height:90px;"></div>{{-- espaço para carimbo e assinatura física --}}
        <div style="border-top:1px solid #333; width:240px; margin:0 auto 6px;"></div>
        @if($name)<div>{{ $name }}</div>@endif
        @if($crm)<div>CRM {{ $crm }}</div>@endif
        @if($rqe)<div>RQE {{ $rqe }}</div>@endif
    </div>
</div>
