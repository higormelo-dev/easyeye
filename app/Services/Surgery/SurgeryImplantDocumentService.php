<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{SurgeryDocumentType, SurgeryEye};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase, SurgicalImplant};
use Barryvdh\Snappy\Facades\SnappyPdf;
use DateTimeInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Documentos do implante (RDC 556/2021):
 *  - REGISTRO do implante: documento congelado (SurgeryDocumentIssueService),
 *    um por implante (scope = id do implante), com os dados do implante
 *    copiados — reemitir cria linha nova e mantém a anterior no histórico;
 *  - FOLHA DE ETIQUETAS adesivas (prontuário/ficha do paciente) e CARTÃO DO
 *    IMPLANTE (entregue ao paciente), ambos montados dos dados CONGELADOS do
 *    implante. Paciente e clínica vêm do cadastro no momento da impressão
 *    (são impressos, não documentos legais guardados — o legal é o REGISTRO).
 */
class SurgeryImplantDocumentService
{
    public const MAX_LABELS = 30;

    public function __construct(private readonly SurgeryDocumentIssueService $documents)
    {
    }

    public function issueRecord(SurgicalImplant $implant): SurgeryDocumentIssue
    {
        return DB::transaction(function () use ($implant): SurgeryDocumentIssue {
            // Caso → implante (ordem do ADR), relido SOB a trava: uma anulação concorrente não emite registro.
            $case   = SurgicalCase::query()->whereKey($implant->surgical_case_id)->lockForUpdate()->firstOrFail();
            $locked = SurgicalImplant::query()->where('surgical_case_id', $case->getKey())->whereKey($implant->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw SurgeryRuleException::state('surgery_operation.errors.record_needs_active_implant');
            }

            $content = '<p>' . e((string) __('surgery_operation.documents.implant_intro', [
                'patient' => (string) ($case->patient?->person?->full_name ?? ''),
                'case'    => $case->code,
            ])) . '</p>';

            return $this->documents->issue(
                case: $case,
                type: SurgeryDocumentType::ImplantRecord,
                title: (string) __('surgery_operation.documents.implant_title'),
                content: $content,
                data: ['items' => $this->items($locked), 'implant_id' => (string) $locked->getKey(), 'content_hash' => $locked->content_hash],
                scope: (string) $locked->getKey(),
            );
        });
    }

    /** Emissão vigente do registro do implante (ou null). */
    public function currentRecord(SurgicalImplant $implant): ?SurgeryDocumentIssue
    {
        // Implante anulado não tem registro vigente: o documento fica no histórico, mas não é servido.
        if (! $implant->isActive()) {
            return null;
        }

        return SurgeryDocumentIssue::query()
            ->where('surgical_case_id', $implant->surgical_case_id)
            ->where('type', SurgeryDocumentType::ImplantRecord->value)
            ->whereNull('superseded_by_id')
            ->whereRaw("coalesce(payload->>'scope', 'main') = ?", [(string) $implant->getKey()])
            ->latest('issued_at')
            ->first();
    }

    public function recordPdf(SurgicalImplant $implant): Response
    {
        $issue = $this->currentRecord($implant);

        if ($issue === null) {
            throw SurgeryRuleException::state('surgery_operation.errors.record_not_issued');
        }

        return $this->documents->pdf($issue);
    }

    public function labelsPdf(SurgicalImplant $implant, int $count): Response
    {
        if (! $implant->isActive()) {
            throw SurgeryRuleException::state('surgery_operation.errors.record_needs_active_implant');
        }

        $count = max(1, min(self::MAX_LABELS, $count));
        $case  = SurgicalCase::query()->findOrFail($implant->surgical_case_id);

        return $this->pdf('pdf.surgery_implant_labels', [
            'implants' => [$implant],
            'header'   => OperationHeader::build($case),
            'count'    => $count,
            'rows'     => $this->rows($implant),
        ], 'etiquetas-implante-' . $case->code . '.pdf');
    }

    public function cardPdf(SurgicalCase $case, ?SurgicalImplant $only = null): Response
    {
        $implants = $only !== null
            ? collect([$only])->filter(fn (SurgicalImplant $i) => $i->isActive())
            : SurgicalImplant::query()->where('surgical_case_id', $case->getKey())->where('status', 'active')->orderBy('implanted_at')->orderBy('id')->get();

        if ($implants->isEmpty()) {
            throw SurgeryRuleException::state('surgery_operation.errors.card_needs_implant');
        }

        return $this->pdf('pdf.surgery_implant_card', [
            'implants' => $implants->values()->all(),
            'header'   => OperationHeader::build($case),
            'cards'    => $implants->map(fn (SurgicalImplant $i) => ['implant' => $i, 'rows' => $this->rows($i)])->values()->all(),
        ], 'cartao-implante-' . $case->code . '.pdf');
    }

    /** Linhas rótulo/valor (já localizadas) do implante. @return list<array{label: string, value: string}> */
    public function items(SurgicalImplant $implant): array
    {
        return $this->rows($implant);
    }

    /** @return list<array{label: string, value: string}> */
    private function rows(SurgicalImplant $implant): array
    {
        $date = fn (?DateTimeInterface $d) => $d?->format((string) __('surgery_operation.date_format')) ?? '—';

        $rows = [
            ['label' => __('surgery_operation.fields.product'), 'value' => $implant->product_name],
            ['label' => __('surgery_operation.fields.manufacturer'), 'value' => $implant->manufacturer ?? '—'],
            ['label' => __('surgery_operation.fields.model_reference'), 'value' => $implant->model_reference ?? '—'],
            ['label' => __('surgery_operation.fields.anvisa'), 'value' => $implant->anvisa_registration ?? '—'],
        ];

        if ($implant->power !== null) {
            $rows[] = ['label' => __('surgery_operation.fields.power'), 'value' => number_format((float) $implant->power, 2, (string) __('surgery_operation.decimal_separator'), '') . ' D'];
        }

        $rows[] = ['label' => __('surgery_operation.fields.lot'), 'value' => $implant->lot_number ?? '—'];
        $rows[] = ['label' => __('surgery_operation.fields.serial'), 'value' => $implant->serial_number ?? '—'];
        $rows[] = ['label' => __('surgery_operation.fields.expiry'), 'value' => $date($implant->expiry_date)];
        $rows[] = ['label' => __('surgery_operation.fields.eye'), 'value' => SurgeryEye::from($implant->eye->value)->label()];
        $rows[] = ['label' => __('surgery_operation.fields.implanted_at'), 'value' => $date($implant->implanted_at)];
        $rows[] = ['label' => __('surgery_operation.fields.kind'), 'value' => $implant->kind->label()];
        $rows[] = ['label' => __('surgery_operation.fields.source'), 'value' => $implant->stock_source->label()];

        return array_map(fn (array $r) => ['label' => (string) $r['label'], 'value' => (string) $r['value']], $rows);
    }

    /** @param array<string, mixed> $data */
    private function pdf(string $view, array $data, string $filename): Response
    {
        return SnappyPdf::loadView($view, $data)
            ->setPaper('a4')
            ->setOption('margin-top', '1.2cm')
            ->setOption('margin-right', '1cm')
            ->setOption('margin-bottom', '1.2cm')
            ->setOption('margin-left', '1cm')
            ->setOption('encoding', 'UTF-8')
            ->inline(preg_replace('/[^A-Za-z0-9\-_.]/', '_', $filename));
    }
}
