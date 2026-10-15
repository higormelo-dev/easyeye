<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{AnesthesiaType, ChecklistAnswer, ChecklistPhase, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\{SurgicalCase, SurgicalChecklistEntry, SurgicalChecklistPhase};
use App\Support\CurrentDoctor;
use App\Support\Surgery\CanonicalHash;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Checklist de cirurgia segura (OMS) do caso — ADR 0003, D10.
 *
 * Três fases (Entrada, Pausa Cirúrgica, Saída); cada uma é aberta na primeira
 * resposta e FECHADA uma vez só: depois de fechada ela e as respostas são
 * imutáveis (os models recusam) e o conteúdo entra no `content_hash`. O texto
 * de cada item é gravado no idioma de quem respondeu, com a versão do catálogo
 * (config/surgery_checklist.php).
 *
 * Toda escrita trava a linha do caso (FOR UPDATE) e relê o estado: duas pessoas
 * respondendo/fechando a mesma fase serializam, e a segunda enxerga a fase
 * fechada pela primeira (409), nunca grava por cima.
 */
class SurgeryChecklistService
{
    /** Estados do caso que aceitam trabalho no checklist. */
    private const OPEN_STATUSES = [SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed];

    public function __construct(private readonly SurgicalCaseService $cases)
    {
    }

    // ── Catálogo e leitura ──────────────────────────────────────────────────

    public function catalogVersion(): string
    {
        return (string) config('surgery_checklist.version');
    }

    /**
     * Itens do catálogo da fase, com o texto no idioma atual.
     *
     * @return list<array{key: string, text: string, hint: string|null, critical: bool, allow_na: bool, suggested_na: bool}>
     */
    public function items(ChecklistPhase $phase, ?SurgicalCase $case = null): array
    {
        return array_map(function (array $item) use ($case): array {
            $key  = (string) $item['key'];
            $hint = trans("surgery_schedule.checklist.items.{$key}.hint");

            return [
                'key'          => $key,
                'text'         => (string) __("surgery_schedule.checklist.items.{$key}.text"),
                'hint'         => is_string($hint) && $hint !== '' && $hint !== "surgery_schedule.checklist.items.{$key}.hint" ? $hint : null,
                'critical'     => (bool) ($item['critical'] ?? false),
                'allow_na'     => (bool) ($item['allow_na'] ?? false),
                'suggested_na' => $case !== null && $this->suggestsNotApplicable($item['na_when'] ?? null, $case),
            ];
        }, (array) config("surgery_checklist.phases.{$phase->value}", []));
    }

    /**
     * Situação das três fases para a tela: itens (texto ao vivo na fase aberta,
     * texto CONGELADO na fechada), respostas, fechamento e exceção.
     *
     * @return array<string, mixed>
     */
    public function overview(SurgicalCase $case): array
    {
        $phases = SurgicalChecklistPhase::query()
            ->where('surgical_case_id', $case->getKey())
            ->with(['entries', 'closer:id,name', 'overrideDoctor.person:id,full_name'])
            ->get()
            ->keyBy(fn (SurgicalChecklistPhase $p) => $p->phase->value);

        $out = [];

        foreach (ChecklistPhase::cases() as $phase) {
            /** @var SurgicalChecklistPhase|null $row */
            $row     = $phases->get($phase->value);
            $answers = $row?->entries->keyBy('item_key') ?? collect();
            $items   = [];

            foreach ($this->items($phase, $case) as $item) {
                /** @var SurgicalChecklistEntry|null $entry */
                $entry   = $answers->get($item['key']);
                $items[] = [
                    ...$item,
                    // Fase fechada: o texto e a criticidade que foram conferidos.
                    'text'     => $row?->isClosed() && $entry ? $entry->item_text : $item['text'],
                    'critical' => $row?->isClosed() && $entry ? (bool) $entry->critical : $item['critical'],
                    'answer'   => $entry?->answer->value,
                    'note'     => $entry?->note,
                ];
            }

            $out[] = [
                'phase'     => $phase->value,
                'label'     => $phase->label(),
                'when'      => $phase->when(),
                'state'     => $row === null ? 'not_started' : ($row->isClosed() ? 'closed' : 'open'),
                'items'     => $items,
                'answered'  => count(array_filter($items, fn (array $i) => $i['answer'] !== null)),
                'total'     => count($items),
                'closed_at' => $row?->closed_at?->toIso8601String(),
                'closed_by' => $row?->closer?->name,
                'hash'      => $row?->content_hash,
                'catalog'   => $row?->catalog_version,
                'override'  => $row?->override_reason === null ? null : [
                    'reason' => $row->override_reason,
                    'doctor' => $row->overrideDoctor?->person?->full_name,
                    'at'     => $row->override_at?->toIso8601String(),
                ],
            ];
        }

        return [
            'catalog_version'      => $this->catalogVersion(),
            'phases'               => $out,
            'time_out_satisfied'   => $this->timeOutSatisfied($case),
            'time_out_stale'       => $this->isTimeOutStale($case),
            'time_out_critical_no' => $this->isTimeOutCriticalNo($case),
            'accepts_work'         => in_array($case->status, self::OPEN_STATUSES, true),
            'override_min_length'  => (int) config('surgery_checklist.override_min_length', 10),
        ];
    }

    /** A Pausa Cirúrgica está fechada? */
    public function isTimeOutClosed(SurgicalCase $case): bool
    {
        return SurgicalChecklistPhase::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('phase', ChecklistPhase::TimeOut->value)
            ->whereNotNull('closed_at')
            ->exists();
    }

    /**
     * Pausa Cirúrgica fechada e ainda válida para o plano/janela de agora, OU
     * exceção do cirurgião registrada para este mesmo plano/janela.
     */
    public function timeOutSatisfied(SurgicalCase $case): bool
    {
        if ($this->isTimeOutCurrent($case)) {
            return true;
        }

        $row = $this->timeOutRow($case);

        return $row !== null
            && $row->override_reason !== null
            && ($row->override_fingerprint === null || $row->override_fingerprint === $this->planFingerprint($case));
    }

    /**
     * Retrato do que a Pausa Cirúrgica confere: olho, técnica, anestesia, LIO
     * planejada, equipe, cirurgião, janela e sala. Mudou qualquer coisa → outro hash.
     */
    public function planFingerprint(SurgicalCase $case): string
    {
        $iol = $case->iolPlans()->get()->map(fn ($p) => [
            'eye'        => $p->eye->value,
            'product'    => $p->entity_product_id,
            'model'      => $p->model_label,
            'power'      => (string) $p->power,
            'cylinder'   => $p->cylinder === null ? null : (string) $p->cylinder,
            'axis'       => $p->axis,
            'formula'    => $p->formula,
            'a_constant' => $p->a_constant === null ? null : (string) $p->a_constant,
            'target'     => $p->target_refraction === null ? null : (string) $p->target_refraction,
            'predicted'  => $p->predicted_refraction === null ? null : (string) $p->predicted_refraction,
        ])->sortBy('eye')->values()->all();

        $team = $case->team()->get()->map(fn ($m) => [
            'role'   => $m->role->value,
            'doctor' => $m->doctor_id,
            'name'   => $m->name,
        ])->sortBy(fn ($m) => $m['role'] . '|' . $m['doctor'] . '|' . $m['name'])->values()->all();

        return CanonicalHash::of([
            'v'          => CanonicalHash::VERSION,
            'eye'        => $case->eye->value,
            'technique'  => trim((string) $case->technique),
            'anesthesia' => $case->anesthesia?->value,
            'surgeon'    => (string) $case->surgeon_doctor_id,
            'start'      => $case->scheduled_start?->toIso8601String(),
            'end'        => $case->scheduled_end?->toIso8601String(),
            'facility'   => $case->surgery_facility_id,
            'room'       => $case->surgery_room_id,
            'iol'        => $iol,
            'team'       => $team,
        ]);
    }

    /**
     * Pausa fechada, válida para o plano/janela de agora (fase antiga sem retrato vale) e SEM item
     * crítico respondido "não" — com crítico "não" só a exceção do cirurgião libera.
     */
    public function isTimeOutCurrent(SurgicalCase $case): bool
    {
        $row = $this->timeOutRow($case);

        return $row !== null && $row->isClosed()
            && ($row->plan_fingerprint === null || $row->plan_fingerprint === $this->planFingerprint($case))
            && ! $this->hasCriticalNo($row);
    }

    /** A fase foi fechada com item crítico respondido "não". */
    public function hasCriticalNo(SurgicalChecklistPhase $row): bool
    {
        return $row->entries()->where('critical', true)->where('answer', ChecklistAnswer::No->value)->exists();
    }

    /** Pausa fechada com item crítico "não" (a cirurgia só segue com a exceção do cirurgião). */
    public function isTimeOutCriticalNo(SurgicalCase $case): bool
    {
        $row = $this->timeOutRow($case);

        return $row !== null && $row->isClosed() && $this->hasCriticalNo($row);
    }

    /** Pausa fechada, mas o plano ou a janela mudaram depois do fechamento. */
    public function isTimeOutStale(SurgicalCase $case): bool
    {
        $row = $this->timeOutRow($case);

        return $row !== null && $row->isClosed() && ! $this->hasCriticalNo($row) && ! $this->isTimeOutCurrent($case);
    }

    private function timeOutRow(SurgicalCase $case): ?SurgicalChecklistPhase
    {
        return SurgicalChecklistPhase::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('phase', ChecklistPhase::TimeOut->value)
            ->first();
    }

    // ── Escrita ─────────────────────────────────────────────────────────────

    /**
     * Grava as respostas (parcial ou completa) numa fase ABERTA.
     *
     * @param list<array{key: string, answer: string, note?: string|null}> $answers
     */
    public function save(SurgicalCase $case, ChecklistPhase $phase, array $answers): SurgicalChecklistPhase
    {
        return DB::transaction(function () use ($case, $phase, $answers): SurgicalChecklistPhase {
            [$row] = $this->openPhase($case, $phase);

            $this->applyAnswers($row, $phase, $answers);

            return $row->refresh();
        });
    }

    /**
     * Fecha a fase: todos os itens respondidos, "não se aplica" só onde o
     * catálogo permite e item CRÍTICO respondido "não" com nota. Aceita as
     * respostas junto (salva e fecha na mesma transação).
     *
     * @param list<array{key: string, answer: string, note?: string|null}> $answers
     */
    public function close(SurgicalCase $case, ChecklistPhase $phase, array $answers = []): SurgicalChecklistPhase
    {
        return DB::transaction(function () use ($case, $phase, $answers): SurgicalChecklistPhase {
            [$row, $lockedCase] = $this->openPhase($case, $phase);

            if ($answers !== []) {
                $this->applyAnswers($row, $phase, $answers);
            }

            $locked = $row->refresh()->load('entries');
            $items  = $this->items($phase);
            $byKey  = $locked->entries->keyBy('item_key');
            $minLen = (int) config('surgery_checklist.critical_note_min_length', 3);
            $errors = [];

            foreach ($items as $item) {
                /** @var SurgicalChecklistEntry|null $entry */
                $entry = $byKey->get($item['key']);

                if ($entry === null) {
                    $errors[] = (string) __('surgery_schedule.checklist.errors.item_unanswered', ['item' => $item['text']]);

                    continue;
                }

                if ($item['critical'] && $entry->answer === ChecklistAnswer::No && mb_strlen(trim((string) $entry->note)) < $minLen) {
                    $errors[] = (string) __('surgery_schedule.checklist.errors.critical_needs_note', ['item' => $item['text']]);
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['checklist' => $errors]);
            }

            $content = array_map(function (array $item) use ($byKey): array {
                /** @var SurgicalChecklistEntry $entry */
                $entry = $byKey->get($item['key']);

                return [
                    'key'      => $item['key'],
                    'text'     => $entry->item_text,
                    'critical' => (bool) $entry->critical,
                    'answer'   => $entry->answer->value,
                    'note'     => $entry->note,
                ];
            }, $items);

            $doctor = CurrentDoctor::issuer();

            $locked->update([
                'closed_at'           => now(),
                'closed_by'           => Auth::id(),
                'closed_by_doctor_id' => $doctor?->getKey(),
                // A Pausa fica amarrada ao plano e à janela que conferiu.
                'plan_fingerprint' => $phase === ChecklistPhase::TimeOut ? $this->planFingerprint($lockedCase) : null,
                'content_hash'     => CanonicalHash::of([
                    'v'       => CanonicalHash::VERSION,
                    'catalog' => $locked->catalog_version,
                    'case'    => (string) $case->getKey(),
                    'phase'   => $phase->value,
                    'locale'  => $locked->locale,
                    'entries' => $content,
                ]),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Exceção da Pausa Cirúrgica: o CIRURGIÃO do caso realiza sem fechá-la, com
     * justificativa. Registrada na linha da fase (criada se faltar). Chamada
     * de dentro da transação de quem registra a cirurgia, com o caso já
     * travado — ver Guards\ChecklistTimeOutGuard. Fase já fechada não precisa
     * de exceção.
     */
    public function overrideTimeOut(SurgicalCase $locked, Doctor $performer, string $reason): SurgicalChecklistPhase
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('overrideTimeOut exige a transação do caso (linha travada).');
        }

        if ((string) $performer->getKey() !== (string) $locked->surgeon_doctor_id) {
            throw SurgeryRuleException::state('surgery_schedule.checklist.errors.only_surgeon_overrides');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < (int) config('surgery_checklist.override_min_length', 10)) {
            throw SurgeryRuleException::invalid('surgery_schedule.checklist.errors.override_reason_short', [
                'min' => (int) config('surgery_checklist.override_min_length', 10),
            ]);
        }

        $row = SurgicalChecklistPhase::query()
            ->where('surgical_case_id', $locked->getKey())
            ->where('phase', ChecklistPhase::TimeOut->value)
            ->lockForUpdate()
            ->first();

        $row ??= $this->createPhase($locked, ChecklistPhase::TimeOut);
        $now = $this->planFingerprint($locked);

        // Fechada e ainda válida para este plano: não precisa de exceção.
        if ($row->isClosed() && ($row->plan_fingerprint === null || $row->plan_fingerprint === $now) && ! $this->hasCriticalNo($row)) {
            return $row;
        }

        // Aberta, ou fechada antes de o plano/janela mudar: a exceção cobre o plano de AGORA.
        $row->update([
            'override_reason'      => $reason,
            'override_doctor_id'   => $performer->getKey(),
            'override_at'          => now(),
            'override_fingerprint' => $now,
        ]);

        return $row->refresh();
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Trava o caso, confere o estado, devolve a fase ABERTA (cria na 1ª resposta)
     * e o caso já travado.
     *
     * @return array{0: SurgicalChecklistPhase, 1: SurgicalCase}
     */
    private function openPhase(SurgicalCase $case, ChecklistPhase $phase): array
    {
        $locked = $this->cases->lock((string) $case->getKey());

        if (! in_array($locked->status, self::OPEN_STATUSES, true)) {
            throw SurgeryRuleException::state('surgery_schedule.checklist.errors.case_state', ['status' => $locked->status->label()]);
        }

        $row = SurgicalChecklistPhase::query()
            ->where('surgical_case_id', $locked->getKey())
            ->where('phase', $phase->value)
            ->lockForUpdate()
            ->first();

        $row ??= $this->createPhase($locked, $phase);

        if ($row->isClosed()) {
            throw SurgeryRuleException::state('surgery_schedule.checklist.errors.phase_closed', ['phase' => $phase->label()]);
        }

        return [$row, $locked];
    }

    private function createPhase(SurgicalCase $locked, ChecklistPhase $phase): SurgicalChecklistPhase
    {
        // Abrir a Entrada renova o retrato de segurança: alergia/anticoagulante/IFIS de agora.
        if ($phase === ChecklistPhase::SignIn) {
            $this->cases->captureSafetySnapshot($locked);
        }

        return SurgicalChecklistPhase::query()->create([
            'entity_id'        => $locked->entity_id,
            'surgical_case_id' => $locked->getKey(),
            'phase'            => $phase,
            'catalog_version'  => $this->catalogVersion(),
            'locale'           => app()->getLocale(),
            'opened_at'        => now(),
            'opened_by'        => Auth::id(),
        ]);
    }

    /** @param list<array{key: string, answer: string, note?: string|null}> $answers */
    private function applyAnswers(SurgicalChecklistPhase $row, ChecklistPhase $phase, array $answers): void
    {
        $catalog = collect($this->items($phase))->keyBy('key');

        foreach ($answers as $given) {
            $key  = (string) ($given['key'] ?? '');
            $item = $catalog->get($key);

            if ($item === null) {
                throw ValidationException::withMessages(['answers' => (string) __('surgery_schedule.checklist.errors.unknown_item')]);
            }

            $answer = ChecklistAnswer::tryFrom((string) ($given['answer'] ?? ''));

            if ($answer === null || ($answer === ChecklistAnswer::NotApplicable && ! $item['allow_na'])) {
                throw ValidationException::withMessages(['answers' => (string) __('surgery_schedule.checklist.errors.invalid_answer', ['item' => $item['text']])]);
            }

            $note = filled($given['note'] ?? null) ? mb_substr(trim((string) $given['note']), 0, 1000) : null;

            SurgicalChecklistEntry::query()->updateOrCreate(
                ['surgical_checklist_phase_id' => $row->getKey(), 'item_key' => $key],
                [
                    'entity_id'        => $row->entity_id,
                    'surgical_case_id' => $row->surgical_case_id,
                    'phase'            => $phase,
                    'item_text'        => $item['text'],
                    'critical'         => $item['critical'],
                    'answer'           => $answer,
                    'note'             => $note,
                    'answered_by'      => Auth::id(),
                    'answered_at'      => now(),
                ],
            );
        }
    }

    private function suggestsNotApplicable(?string $rule, SurgicalCase $case): bool
    {
        return match ($rule) {
            'topical_anesthesia' => $case->anesthesia === AnesthesiaType::Topical,
            'no_iol_plan'        => $case->iolPlans()->count() === 0,
            default              => false,
        };
    }
}
