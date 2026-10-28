<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Engine\RecallState;
use App\Domains\Recall\Enums\{RecallBlockReason, RecallContactOutcome, RecallLifecycle};
use App\Domains\Recall\Models\RecallProtocol;
use App\Domains\Recall\Support\RecallTaskLabel;
use App\Support\BrazilianFormat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A lista de retornos da clínica (tela "Retornos"): os números do topo (sempre da clínica inteira) e as linhas
 * da visão escolhida, paginadas. É SÓ leitura — nada aqui grava. Todas as consultas são da clínica (`entity_id`
 * explícito em `r` e em `p`) e ignoram paciente removido.
 *
 * O ESTADO do prazo (a vencer, vencido, em atraso, perdido) nunca é gravado: sai das datas persistidas
 * (`invite_from`, `due_at`, `overdue_from`, `lost_from`) comparadas ao dia de hoje, no próprio SQL — a lista é um
 * SELECT indexado, sem job que "vire" o estado.
 */
final class RecallListService
{
    public const PER_PAGE = 25;

    /** Dispensados/encerrados só aparecem como histórico recente. */
    public const HISTORY_DAYS = 90;

    public const VIEW_TO_CONTACT = 'to_contact';

    public const VIEW_SCHEDULED = 'scheduled';

    public const VIEW_LOST = 'lost';

    public const VIEW_SNOOZED = 'snoozed';

    public const VIEW_HISTORY = 'history';

    public const VIEW_NEEDS_INFO = 'needs_info';

    public const VIEW_SUSPENDED = 'suspended';

    public const VIEWS = [
        self::VIEW_TO_CONTACT, self::VIEW_SCHEDULED, self::VIEW_LOST, self::VIEW_SNOOZED,
        self::VIEW_HISTORY, self::VIEW_NEEDS_INFO, self::VIEW_SUSPENDED,
    ];

    public const SOURCES = ['protocol', 'doctor_order', 'course'];

    /**
     * Os números do topo: a clínica inteira, sem filtro de busca.
     *
     * @return array{due_soon: int, overdue: int, lost: int, scheduled: int, snoozed: int, needs_info: int, suspended: int, to_contact: int}
     */
    public function counts(string $entityId, ?CarbonImmutable $today = null): array
    {
        $day = ($today ?? CarbonImmutable::now())->toDateString();

        $row = $this->base($entityId)
            ->selectRaw(
                "SUM(CASE WHEN r.lifecycle = 'open' AND NOT r.rule_suspended AND r.scheduled_schedule_id IS NULL AND r.invite_from <= ? AND r.due_at > ? THEN 1 ELSE 0 END) AS due_soon, "
                . "SUM(CASE WHEN r.lifecycle = 'open' AND NOT r.rule_suspended AND r.scheduled_schedule_id IS NULL AND r.due_at <= ? AND r.lost_from > ? THEN 1 ELSE 0 END) AS overdue, "
                . "SUM(CASE WHEN r.lifecycle = 'open' AND NOT r.rule_suspended AND r.scheduled_schedule_id IS NULL AND r.lost_from <= ? THEN 1 ELSE 0 END) AS lost, "
                . "SUM(CASE WHEN r.lifecycle = 'open' AND NOT r.rule_suspended AND r.scheduled_schedule_id IS NOT NULL AND r.invite_from <= ? THEN 1 ELSE 0 END) AS scheduled, "
                . "SUM(CASE WHEN r.lifecycle = 'snoozed' AND NOT r.rule_suspended THEN 1 ELSE 0 END) AS snoozed, "
                . "SUM(CASE WHEN r.lifecycle = 'needs_info' THEN 1 ELSE 0 END) AS needs_info, "
                . "SUM(CASE WHEN r.lifecycle IN ('open', 'snoozed') AND r.rule_suspended THEN 1 ELSE 0 END) AS suspended",
                [$day, $day, $day, $day, $day, $day],
            )
            ->first();

        $counts = [
            'due_soon'   => (int) ($row->due_soon ?? 0),
            'overdue'    => (int) ($row->overdue ?? 0),
            'lost'       => (int) ($row->lost ?? 0),
            'scheduled'  => (int) ($row->scheduled ?? 0),
            'snoozed'    => (int) ($row->snoozed ?? 0),
            'needs_info' => (int) ($row->needs_info ?? 0),
            'suspended'  => (int) ($row->suspended ?? 0),
        ];

        return $counts + ['to_contact' => $counts['due_soon'] + $counts['overdue']];
    }

    /**
     * @return LengthAwarePaginator<int, object> linhas cruas (o apresentador monta o que a tela mostra)
     */
    public function paginate(string $entityId, string $view, ?string $source, ?string $search, int $page = 1, ?CarbonImmutable $today = null, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $day   = $today->toDateString();

        $query = $this->base($entityId);

        $this->applyView($query, in_array($view, self::VIEWS, true) ? $view : self::VIEW_TO_CONTACT, $day, $today);

        if (in_array($source, self::SOURCES, true)) {
            $query->where('r.source', $source);
        }

        $term = trim((string) $search);

        if ($term !== '') {
            $query->where(static function (Builder $match) use ($term): void {
                $match->whereLikeUnaccent('pe.full_name', $term)
                    ->orWhereLikeUnaccent('pe.nickname', $term)
                    ->orWhereLikeUnaccent('p.code', $term);
            });
        }

        return $query
            ->select([
                'r.id', 'r.patient_id', 'r.source', 'r.patient_condition_id', 'r.protocol_key', 'r.task_key', 'r.kind', 'r.exam_key', 'r.eye',
                'r.last_done_at', 'r.anchor_at', 'r.due_at', 'r.invite_from', 'r.overdue_from', 'r.lost_from', 'r.lifecycle', 'r.closed_reason',
                'r.snoozed_until', 'r.dismissed_reason', 'r.rule_suspended', 'r.last_contact_at', 'r.last_contact_outcome', 'r.contact_count',
                'r.scheduled_schedule_id', 'r.external_done_at', 'r.meta', 'r.updated_at',
                'pe.full_name as patient_name', 'pe.nickname as patient_nickname', 'pe.birth_date', 'pe.cellphone', 'pe.telephone', 'pe.whatsapp', 'p.code as patient_code',
                's.date_time as schedule_at',
            ])
            // Quem tocou "Quero agendar" no convite vem primeiro: já pediu, a recepção só precisa marcar.
            ->orderByRaw(
                "EXISTS (SELECT 1 FROM recall_invite_items it JOIN recall_invites ri ON ri.id = it.recall_invite_id WHERE it.patient_recall_id = r.id AND ri.response = 'wants_to_schedule' AND ri.responded_at >= ?) DESC",
                [$today->subDays((int) config('recall.invites.reply_valid_days', 30))->toDateTimeString()],
            )
            ->orderBy('r.due_at')
            ->orderBy('pe.full_name')
            ->orderBy('r.id')
            ->paginate($perPage, ['*'], 'page', max(1, $page));
    }

    /**
     * Linhas cruas → o que a tela mostra. `$phones` é a regra "tem acesso a Pacientes" (decidida no controller);
     * `$conditions` mostra a condição clínica (só para quem a pode ver — médico); os demais perfis veem só o rótulo
     * da tarefa (minimização).
     *
     * @param iterable<object> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function present(iterable $rows, string $entityId, bool $phones, bool $conditions, ?CarbonImmutable $today = null): array
    {
        $today  = ($today ?? CarbonImmutable::now())->startOfDay();
        $rows   = collect($rows);
        $titles = $conditions ? $this->protocolTitles($rows->pluck('protocol_key')->filter()->unique()->all()) : [];
        $blocks = $this->activeBlocks($entityId, $rows->pluck('patient_id')->unique()->all());

        return $rows->map(function (object $row) use ($today, $phones, $conditions, $titles, $blocks): array {
            $state = $row->due_at !== null && $row->invite_from !== null && $row->overdue_from !== null && $row->lost_from !== null
                ? RecallState::at($today, CarbonImmutable::parse($row->invite_from), CarbonImmutable::parse($row->due_at), CarbonImmutable::parse($row->overdue_from), CarbonImmutable::parse($row->lost_from))
                : null;

            $meta      = is_string($row->meta) ? (array) json_decode($row->meta, true) : [];
            $lifecycle = RecallLifecycle::from((string) $row->lifecycle);
            $outcome   = $row->last_contact_outcome !== null ? RecallContactOutcome::tryFrom((string) $row->last_contact_outcome) : null;
            $patientId = (string) $row->patient_id;
            $phone     = $phones ? BrazilianFormat::phone($row->cellphone ?: $row->telephone) : null;

            return [
                'id'      => (string) $row->id,
                'patient' => [
                    'id'       => $patientId,
                    'name'     => (string) $row->patient_name,
                    'nickname' => $row->patient_nickname,
                    'code'     => $row->patient_code,
                    'age'      => $row->birth_date !== null ? (int) floor(CarbonImmutable::parse($row->birth_date)->diffInYears($today)) : null,
                    'phone'    => $phone !== '' ? $phone : null,
                    'whatsapp' => $phones && (bool) $row->whatsapp,
                ],
                'source'     => (string) $row->source,
                'task_label' => $this->taskLabel($row),
                // Quem pode ver a condição vê também a droga do tratamento; os demais perfis veem só "aplicação".
                'condition'           => $row->protocol_key !== null ? ($titles[(string) $row->protocol_key] ?? null) : ($conditions && $row->source === 'course' ? ($meta['drug'] ?? null) : null),
                'eye'                 => $row->eye,
                'due_at'              => $row->due_at !== null ? CarbonImmutable::parse($row->due_at)->toDateString() : null,
                'days_to_due'         => $row->due_at !== null ? (int) $today->diffInDays(CarbonImmutable::parse($row->due_at)->startOfDay(), false) : null,
                'last_done_at'        => $row->last_done_at !== null ? CarbonImmutable::parse($row->last_done_at)->toDateString() : null,
                'state'               => $state?->value,
                'state_label'         => $state?->label(),
                'lifecycle'           => $lifecycle->value,
                'lifecycle_label'     => $lifecycle->label(),
                'snoozed_until'       => $row->snoozed_until !== null ? CarbonImmutable::parse($row->snoozed_until)->toDateString() : null,
                'dismissed_reason'    => $row->dismissed_reason,
                'closed_reason'       => $row->closed_reason,
                'closed_reason_label' => $row->closed_reason !== null ? __('recall.closed_reason.' . $row->closed_reason) : null,
                'rule_suspended'      => (bool) $row->rule_suspended,
                'missing'             => $meta['missing'] ?? null,
                'capped'              => (bool) ($meta['capped'] ?? false),
                'contact'             => [
                    'count'         => (int) $row->contact_count,
                    'last_at'       => $row->last_contact_at !== null ? CarbonImmutable::parse($row->last_contact_at)->toIso8601String() : null,
                    'last_outcome'  => $outcome?->value,
                    'outcome_label' => $outcome?->label(),
                ],
                'schedule' => $row->scheduled_schedule_id !== null ? [
                    'id'      => (string) $row->scheduled_schedule_id,
                    'date_at' => $row->schedule_at !== null ? CarbonImmutable::parse($row->schedule_at)->toIso8601String() : null,
                ] : null,
                'blocks' => $blocks[$patientId] ?? [],
            ];
        })->values()->all();
    }

    // ── consultas ───────────────────────────────────────────────────────────

    private function base(string $entityId): Builder
    {
        return DB::table('patient_recalls as r')
            ->join('patients as p', 'p.id', '=', 'r.patient_id')
            ->join('people as pe', 'pe.id', '=', 'p.person_id')
            ->leftJoin('schedules as s', static function ($join): void {
                $join->on('s.id', '=', 'r.scheduled_schedule_id')->whereNull('s.deleted_at');
            })
            ->where('r.entity_id', $entityId)
            ->where('p.entity_id', $entityId)
            ->whereNull('p.deleted_at')
            ->whereNull('pe.deleted_at');
    }

    private function applyView(Builder $query, string $view, string $day, CarbonImmutable $today): void
    {
        match ($view) {
            self::VIEW_TO_CONTACT => $query->where('r.lifecycle', 'open')->where('r.rule_suspended', false)->whereNull('r.scheduled_schedule_id')
                ->where('r.invite_from', '<=', $day)->where('r.lost_from', '>', $day),
            self::VIEW_SCHEDULED => $query->where('r.lifecycle', 'open')->where('r.rule_suspended', false)->whereNotNull('r.scheduled_schedule_id')
                ->where('r.invite_from', '<=', $day),
            self::VIEW_LOST => $query->where('r.lifecycle', 'open')->where('r.rule_suspended', false)->whereNull('r.scheduled_schedule_id')
                ->where('r.lost_from', '<=', $day),
            self::VIEW_SNOOZED    => $query->where('r.lifecycle', 'snoozed')->where('r.rule_suspended', false),
            self::VIEW_HISTORY    => $query->whereIn('r.lifecycle', ['dismissed', 'closed'])->where('r.updated_at', '>=', $today->subDays(self::HISTORY_DAYS)),
            self::VIEW_NEEDS_INFO => $query->where('r.lifecycle', 'needs_info'),
            self::VIEW_SUSPENDED  => $query->whereIn('r.lifecycle', ['open', 'snoozed'])->where('r.rule_suspended', true),
        };
    }

    private function taskLabel(object $row): string
    {
        return RecallTaskLabel::of((string) $row->source, (string) $row->kind, $row->exam_key !== null ? (string) $row->exam_key : null);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function protocolTitles(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $locale = app()->getLocale();

        return RecallProtocol::query()->whereIn('key', $keys)->get()->mapWithKeys(static function (RecallProtocol $protocol) use ($locale): array {
            $translations = (array) $protocol->translations;

            return [(string) $protocol->key => (string) (($translations[$locale]['title'] ?? $translations['pt_BR']['title'] ?? $protocol->key))];
        })->all();
    }

    /**
     * Bloqueios ativos por paciente (rótulos), para a tela avisar "não contatar", "sem telefone válido"...
     *
     * @param list<string> $patientIds
     *
     * @return array<string, list<array{reason: string, label: string}>>
     */
    private function activeBlocks(string $entityId, array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        $blocks = [];

        foreach (DB::table('patient_recall_blocks')->where('entity_id', $entityId)->whereIn('patient_id', $patientIds)->whereNull('released_at')->get(['patient_id', 'reason']) as $block) {
            $reason = RecallBlockReason::tryFrom((string) $block->reason);

            $blocks[(string) $block->patient_id][] = ['reason' => (string) $block->reason, 'label' => $reason?->label() ?? (string) $block->reason];
        }

        return $blocks;
    }
}
