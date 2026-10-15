<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{AnesthesiaType, SurgeryDescriptionStatus, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\{SurgicalCase, SurgicalDescription, SurgicalDescriptionAddendum};
use App\Support\Surgery\{CanonicalHash, LocalTime};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;

/**
 * Descrição cirúrgica (ADR 0003, M4): rascunho → FINALIZADA com hash; depois,
 * só adendo (que encadeia o hash do anterior). Ato do cirurgião do caso (ou
 * cirurgião da equipe): nunca da secretária nem de outro médico da clínica.
 *
 * Decisão documentada: a cirurgia pode ser marcada como REALIZADA sem a
 * descrição finalizada (o cirurgião escreve depois do ato), mas a ALTA exige a
 * descrição FINALIZADA — `isFinalized($case)` é o ponto de consulta da etapa
 * de alta.
 *
 * Ordem de locks (ADR §5): linha do caso → descrição → (adendos só inserem).
 */
class SurgeryDescriptionService
{
    public function __construct(
        private readonly SurgicalCaseService $cases,
        private readonly SurgeryParticipants $participants,
    ) {
    }

    /** A descrição do caso existe e está finalizada? (a alta exige que sim) */
    public function isFinalized(SurgicalCase $case): bool
    {
        return SurgicalDescription::query()
            ->withoutGlobalScopes()
            ->where('surgical_case_id', $case->getKey())
            ->where('status', SurgeryDescriptionStatus::Finalized->value)
            ->exists();
    }

    /**
     * Grava o rascunho (cria na primeira vez). `$input`: performed_start_at,
     * performed_end_at, anesthesia_used, findings, technique_performed,
     * no_complications, complications.
     *
     * @param array<string, mixed> $input
     */
    public function saveDraft(SurgicalCase $case, array $input, Doctor $doctor, ?int $expectedRevision = null): SurgicalDescription
    {
        return DB::transaction(function () use ($case, $input, $doctor, $expectedRevision): SurgicalDescription {
            $locked = $this->cases->lock((string) $case->getKey());

            if (! in_array($locked->status, [SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed], true)) {
                throw SurgeryRuleException::state('surgery_operation.errors.description_case_state', ['status' => $locked->status->label()]);
            }

            $this->participants->assertSurgeon($locked, $doctor);

            $description = $this->lockedOrCreate($locked, $doctor);

            if ($description->isFinalized()) {
                throw SurgeryRuleException::state('surgery_operation.errors.description_finalized');
            }

            if ($expectedRevision !== null && (int) $description->revision !== $expectedRevision) {
                throw SurgeryRuleException::state('surgery_operation.errors.description_stale');
            }

            $start = $this->moment($input['performed_start_at'] ?? null, 'performed_start_at');
            $end   = $this->moment($input['performed_end_at'] ?? null, 'performed_end_at');

            if ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
                throw ValidationException::withMessages(['performed_end_at' => (string) __('surgery_operation.validation.end_after_start')]);
            }

            $noComplications = (bool) ($input['no_complications'] ?? false);

            $description->fill([
                'performed_start_at'  => $start,
                'performed_end_at'    => $end,
                'anesthesia_used'     => filled($input['anesthesia_used'] ?? null) ? AnesthesiaType::from((string) $input['anesthesia_used']) : null,
                'findings'            => $this->text($input['findings'] ?? null),
                'technique_performed' => $this->text($input['technique_performed'] ?? null),
                'no_complications'    => $noComplications,
                'complications'       => $noComplications ? null : $this->text($input['complications'] ?? null),
            ]);
            $description->revision = $description->revision + 1;
            $description->save();

            return $description;
        });
    }

    /** Finaliza: valida o conteúdo, congela o cabeçalho e grava o hash. Só com a cirurgia já realizada. */
    public function finalize(SurgicalCase $case, Doctor $doctor, ?int $expectedRevision = null): SurgicalDescription
    {
        return DB::transaction(function () use ($case, $doctor, $expectedRevision): SurgicalDescription {
            $locked = $this->cases->lock((string) $case->getKey());

            if ($locked->status !== SurgicalCaseStatus::Performed && $locked->status !== SurgicalCaseStatus::Discharged) {
                throw SurgeryRuleException::state('surgery_operation.errors.finalize_needs_performed');
            }

            $this->participants->assertSurgeon($locked, $doctor);

            /** @var SurgicalDescription|null $description */
            $description = SurgicalDescription::query()->where('surgical_case_id', $locked->getKey())->lockForUpdate()->first();

            if ($description === null) {
                throw SurgeryRuleException::state('surgery_operation.errors.description_missing');
            }

            if ($description->isFinalized()) {
                throw SurgeryRuleException::state('surgery_operation.errors.description_finalized');
            }

            if ($expectedRevision !== null && (int) $description->revision !== $expectedRevision) {
                throw SurgeryRuleException::state('surgery_operation.errors.description_stale');
            }

            $problems = $this->problems($description);

            if ($problems !== []) {
                throw ValidationException::withMessages(['description' => $problems]);
            }

            $description->header_snapshot = OperationHeader::build($locked);

            $hash = CanonicalHash::of(['v' => CanonicalHash::VERSION, ...$description->hashableContent()]);

            $description->forceFill([
                'status'                 => SurgeryDescriptionStatus::Finalized,
                'finalized_at'           => now(),
                'finalized_by_doctor_id' => $doctor->getKey(),
                'content_hash'           => $hash,
            ])->save();

            return $description;
        });
    }

    /** O que falta para finalizar (a tela mostra antes de tentar). @return list<string> */
    public function problems(SurgicalDescription $description): array
    {
        $problems = [];

        if ($description->performed_start_at === null || $description->performed_end_at === null) {
            $problems[] = (string) __('surgery_operation.validation.times_required');
        }

        if ($description->anesthesia_used === null) {
            $problems[] = (string) __('surgery_operation.validation.anesthesia_required');
        }

        if (blank($description->findings)) {
            $problems[] = (string) __('surgery_operation.validation.findings_required');
        }

        if (blank($description->technique_performed)) {
            $problems[] = (string) __('surgery_operation.validation.technique_required');
        }

        if (! $description->no_complications && blank($description->complications)) {
            $problems[] = (string) __('surgery_operation.validation.complications_required');
        }

        return $problems;
    }

    /** Adendo a uma descrição finalizada (correção/complemento). Imutável, encadeado por hash. */
    public function addAddendum(SurgicalCase $case, string $text, string $reason, Doctor $doctor): SurgicalDescriptionAddendum
    {
        $text   = trim($text);
        $reason = trim($reason);

        if (mb_strlen($text) < 10) {
            throw ValidationException::withMessages(['text' => (string) __('surgery_operation.validation.addendum_text_short')]);
        }

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => (string) __('surgery_operation.validation.addendum_reason_required')]);
        }

        return DB::transaction(function () use ($case, $text, $reason, $doctor): SurgicalDescriptionAddendum {
            $locked = $this->cases->lock((string) $case->getKey());

            if (! in_array($locked->status, [SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true)) {
                throw SurgeryRuleException::state('surgery_operation.errors.addendum_case_state');
            }

            $this->participants->assertSurgeon($locked, $doctor);

            /** @var SurgicalDescription|null $description */
            $description = SurgicalDescription::query()->where('surgical_case_id', $locked->getKey())->lockForUpdate()->first();

            if ($description === null || ! $description->isFinalized()) {
                throw SurgeryRuleException::state('surgery_operation.errors.addendum_needs_finalized');
            }

            $doctor->loadMissing('person');
            $previous = SurgicalDescriptionAddendum::query()
                ->where('surgical_description_id', $description->getKey())
                ->orderByDesc('created_at')->orderByDesc('id')
                ->value('content_hash');

            $createdAt = now();

            return SurgicalDescriptionAddendum::query()->create([
                'entity_id'               => $locked->entity_id,
                'surgical_description_id' => $description->getKey(),
                'surgical_case_id'        => $locked->getKey(),
                'patient_id'              => $locked->patient_id,
                'text'                    => $text,
                'reason'                  => $reason,
                'doctor_id'               => $doctor->getKey(),
                'doctor_name'             => $doctor->person?->full_name,
                'doctor_registry'         => $doctor->record,
                'content_hash'            => CanonicalHash::of([
                    'v'           => CanonicalHash::VERSION,
                    'description' => (string) $description->content_hash,
                    'previous'    => $previous,
                    'text'        => $text,
                    'reason'      => $reason,
                    'doctor'      => (string) $doctor->getKey(),
                    'at'          => $createdAt->toIso8601String(),
                ]),
                'created_by' => Auth::id(),
                'created_at' => $createdAt,
            ]);
        });
    }

    /** PDF da descrição: finalizada sai do retrato gravado; rascunho, do cabeçalho de agora (marcado como rascunho). */
    public function pdf(SurgicalCase $case): Response
    {
        $description = SurgicalDescription::query()->where('surgical_case_id', $case->getKey())->with('addenda')->firstOrFail();

        $header = $description->isFinalized() ? (array) $description->header_snapshot : OperationHeader::build($case);

        $filename = preg_replace('/[^A-Za-z0-9\-_.]/', '_', 'descricao-cirurgica-' . $case->code . '.pdf');

        return SnappyPdf::loadView('pdf.surgery_description', [
            'description' => $description,
            'header'      => $header,
        ])
            ->setPaper('a4')
            ->setOption('margin-top', '1.8cm')
            ->setOption('margin-right', '1.8cm')
            ->setOption('margin-bottom', '2cm')
            ->setOption('margin-left', '2cm')
            ->setOption('footer-font-size', '7')
            ->setOption('footer-center', ($description->content_hash ? substr($description->content_hash, 0, 16) . ' · ' : '') . __('pdf.page_footer_native'))
            ->setOption('encoding', 'UTF-8')
            ->inline($filename);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function lockedOrCreate(SurgicalCase $locked, Doctor $doctor): SurgicalDescription
    {
        $existing = SurgicalDescription::query()->where('surgical_case_id', $locked->getKey())->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // Savepoint: o índice único (caso) aborta só esta tentativa em corrida.
            $created = DB::transaction(fn () => SurgicalDescription::query()->create([
                'entity_id'         => $locked->entity_id,
                'surgical_case_id'  => $locked->getKey(),
                'patient_id'        => $locked->patient_id,
                'surgeon_doctor_id' => $doctor->getKey(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return SurgicalDescription::query()->where('surgical_case_id', $locked->getKey())->lockForUpdate()->firstOrFail();
        }

        return SurgicalDescription::query()->whereKey($created->getKey())->lockForUpdate()->firstOrFail();
    }

    private function moment(mixed $value, string $field): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $at = LocalTime::parse((string) $value);

        if ($at->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages([$field => (string) __('surgery_operation.validation.moment_future')]);
        }

        return $at;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 20000);
    }
}
