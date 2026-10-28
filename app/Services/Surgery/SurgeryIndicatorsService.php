<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{AuthorizationEventType, PostOpEvaluationStatus, PostOpMilestone, SurgicalCaseStatus};
use App\Models\Surgery\{SurgicalCase, SurgicalCaseIolPlan, SurgicalPostopEvaluation};
use App\Services\Surgery\PostOp\PostOpEvaluationService;
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Indicadores cirúrgicos (ADR 0003, D13) — SÓ agregados, nunca dado do paciente.
 *
 * Escopo: a clínica inteira (admin/financeiro) ou o próprio médico (cirurgião
 * do caso). Cache curto por clínica + escopo + período; a chave inclui o médico,
 * então um médico nunca lê o cache da clínica (nem o de outro médico).
 *
 * Definições (documentadas aqui porque o número só vale com a regra):
 *  - Taxa de autorização = (autorizadas + parciais + dispensadas) / (essas + negadas),
 *    pelos eventos do log registrados no período.
 *  - Tempos em DIAS: pedido→autorização (casos autorizados no período), autorização→
 *    cirurgia e pedido→cirurgia (casos realizados no período). Média e mediana.
 *  - Erro refracional = refração obtida − refração prevista, por olho com LIO planejada
 *    (previsão informada) em cirurgia realizada no período. "Obtida" = equivalente esférico
 *    da avaliação finalizada MAIS TARDIA do olho que tenha refração (normalmente D30; o
 *    adendo com refração corrigida vale no lugar da original). ME = erro médio (viés),
 *    MAE = erro absoluto médio, % dentro de ±0,50 D e ±1,00 D (inclusive). n < 10 = amostra pequena.
 */
class SurgeryIndicatorsService
{
    public const CACHE_TTL_SECONDS = 120;

    /** Motivo de texto livre só aparece com este n mínimo; o resto vira "Outros". */
    public const MIN_REASON_COUNT = 3;

    /**
     * @return array<string, mixed>
     */
    public function summary(string $entityId, CarbonInterface $from, CarbonInterface $to, ?string $doctorId = null): array
    {
        $from = $from->copy()->startOfDay();
        $to   = $to->copy()->endOfDay();

        $key = sprintf('surgery.indicators.v1.%s.%s.%s.%s', $entityId, $doctorId ?? 'all', $from->format('Ymd'), $to->format('Ymd'));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->compute($entityId, $from, $to, $doctorId));
    }

    /** @return array<string, mixed> */
    private function compute(string $entityId, CarbonInterface $from, CarbonInterface $to, ?string $doctorId): array
    {
        $cases = fn () => SurgicalCase::query()->withoutEntityScope()
            ->where('surgical_cases.entity_id', $entityId)
            ->when($doctorId, fn ($q) => $q->where('surgical_cases.surgeon_doctor_id', $doctorId));

        $performed = $cases()->whereNotNull('performed_at')->whereBetween('performed_at', [$from, $to])
            ->with(['surgeon.person', 'facility'])->get();

        return [
            'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'authorization' => $this->authorization($cases, $from, $to),
            'times'         => $this->times($cases, $performed, $from, $to),
            'cancellations' => $this->cancellations($cases, $from, $to),
            'surgeries'     => $this->surgeries($performed),
            'complications' => $this->complications($entityId, $from, $to, $doctorId),
            'refractive'    => $this->refractive($entityId, $performed),
            'small_sample'  => (int) config('surgery_postop.small_sample', 10),
        ];
    }

    /** @return array<string, mixed> */
    private function authorization(callable $cases, CarbonInterface $from, CarbonInterface $to): array
    {
        $ids    = $cases()->select('surgical_cases.id');
        $counts = DB::table('surgical_authorization_events')
            ->whereIn('surgical_case_id', $ids)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $authorized = (int) ($counts[AuthorizationEventType::Authorized->value] ?? 0);
        $partial    = (int) ($counts[AuthorizationEventType::PartiallyAuthorized->value] ?? 0);
        $waived     = (int) ($counts[AuthorizationEventType::Waived->value] ?? 0);
        $denied     = (int) ($counts[AuthorizationEventType::Denied->value] ?? 0);
        $responded  = $authorized + $partial + $waived + $denied;

        $reasons = DB::table('surgical_authorization_events')
            ->whereIn('surgical_case_id', $ids)
            ->whereBetween('occurred_at', [$from, $to])
            ->where('type', AuthorizationEventType::Denied->value)
            ->pluck('denial_reason');

        return [
            'requested'      => (int) ($counts[AuthorizationEventType::Requested->value] ?? 0) + (int) ($counts[AuthorizationEventType::Appealed->value] ?? 0),
            'authorized'     => $authorized,
            'partial'        => $partial,
            'waived'         => $waived,
            'denied'         => $denied,
            'responded'      => $responded,
            'rate'           => $responded > 0 ? round((($authorized + $partial + $waived) / $responded) * 100, 1) : null,
            'denial_reasons' => $this->topReasons($reasons),
        ];
    }

    /**
     * @param Collection<int, SurgicalCase> $performed
     *
     * @return array<string, array<string, float|int|null>>
     */
    private function times(callable $cases, Collection $performed, CarbonInterface $from, CarbonInterface $to): array
    {
        $authorized = $cases()->whereNotNull('authorized_at')->whereNotNull('requested_at')
            ->whereBetween('authorized_at', [$from, $to])->get(['requested_at', 'authorized_at']);

        return [
            'request_to_authorization' => $this->stats($authorized->map(fn ($c) => $c->requested_at->diffInSeconds($c->authorized_at, false) / 86400)),
            'authorization_to_surgery' => $this->stats($performed->filter(fn ($c) => $c->authorized_at !== null)
                ->map(fn ($c) => $c->authorized_at->diffInSeconds($c->performed_at, false) / 86400)),
            'request_to_surgery' => $this->stats($performed->filter(fn ($c) => $c->requested_at !== null)
                ->map(fn ($c) => $c->requested_at->diffInSeconds($c->performed_at, false) / 86400)),
        ];
    }

    /** @return array<string, mixed> */
    private function cancellations(callable $cases, CarbonInterface $from, CarbonInterface $to): array
    {
        $cancelled = $cases()->whereNotNull('cancelled_at')->whereBetween('cancelled_at', [$from, $to])->pluck('cancellation_reason');
        // Suspensões pelo HISTÓRICO do caso (to = suspended): `suspended_at` é zerado no resume e
        // subcontaria os casos retomados. Cada entrada do histórico dentro do período é uma suspensão.
        $suspended = collect();

        $cases()->whereRaw('status_history @> ?::jsonb', [json_encode([['to' => SurgicalCaseStatus::Suspended->value]])])
            ->pluck('status_history')
            ->each(function ($history) use (&$suspended, $from, $to): void {
                foreach ((array) (is_string($history) ? json_decode($history, true) : $history) as $entry) {
                    if (($entry['to'] ?? null) !== SurgicalCaseStatus::Suspended->value || empty($entry['at'])) {
                        continue;
                    }

                    $at = Carbon::parse($entry['at']);

                    if ($at->between($from, $to)) {
                        $suspended->push($entry['reason'] ?? null);
                    }
                }
            });

        return [
            'cancelled' => $cancelled->count(),
            'suspended' => $suspended->count(),
        ];
    }

    /**
     * @param Collection<int, SurgicalCase> $performed
     *
     * @return array<string, mixed>
     */
    private function surgeries(Collection $performed): array
    {
        $group = fn (callable $label) => $performed->groupBy($label)
            ->map(fn (Collection $rows, string $name) => ['label' => $name, 'count' => $rows->count()])
            ->sortByDesc('count')->values()->all();

        return [
            'total'       => $performed->count(),
            'by_surgeon'  => $group(fn ($c) => (string) ($c->surgeon?->person?->full_name ?? '—')),
            'by_facility' => $group(fn ($c) => (string) ($c->facility?->name ?? '—')),
            'by_month'    => $performed->groupBy(fn ($c) => $c->performed_at->format('Y-m'))
                ->map(fn (Collection $rows, string $month) => ['label' => $month, 'count' => $rows->count()])
                ->sortKeys()->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function complications(string $entityId, CarbonInterface $from, CarbonInterface $to, ?string $doctorId): array
    {
        $evaluations = SurgicalPostopEvaluation::query()->withoutEntityScope()
            ->where('surgical_postop_evaluations.entity_id', $entityId)
            ->where('status', PostOpEvaluationStatus::Finalized->value)
            ->whereBetween('finalized_at', [$from, $to])
            ->when($doctorId, fn ($q) => $q->whereIn('surgical_case_id', SurgicalCase::query()->withoutEntityScope()->where('surgeon_doctor_id', $doctorId)->select('id')))
            ->get(['milestone', 'findings', 'severe']);

        return [
            'evaluations'  => $evaluations->count(),
            'by_milestone' => array_map(function (PostOpMilestone $m) use ($evaluations): array {
                $rows = $evaluations->filter(fn ($e) => $e->milestone === $m);

                return [
                    'milestone'     => $m->value,
                    'label'         => $m->short(),
                    'evaluations'   => $rows->count(),
                    'with_findings' => $rows->filter(fn ($e) => (array) $e->findings !== [])->count(),
                    'severe'        => $rows->filter(fn ($e) => $e->severe)->count(),
                ];
            }, PostOpMilestone::evaluable()),
        ];
    }

    /**
     * @param Collection<int, SurgicalCase> $performed
     *
     * @return array<string, mixed>
     */
    private function refractive(string $entityId, Collection $performed): array
    {
        if ($performed->isEmpty()) {
            return ['overall' => null, 'by_surgeon' => []];
        }

        $plans = SurgicalCaseIolPlan::query()->withoutEntityScope()
            ->whereIn('surgical_case_id', $performed->pluck('id'))
            ->whereNotNull('predicted_refraction')
            ->get();

        $evaluations = SurgicalPostopEvaluation::query()->withoutEntityScope()
            ->where('surgical_postop_evaluations.entity_id', $entityId)
            ->whereIn('surgical_case_id', $plans->pluck('surgical_case_id')->unique())
            ->where('status', PostOpEvaluationStatus::Finalized->value)
            ->with('addenda')
            ->orderBy('evaluated_at')
            ->get()
            ->groupBy(fn ($e) => $e->surgical_case_id . '|' . $e->eye->value);

        $bySurgeon = [];

        foreach ($plans as $plan) {
            $obtained = $evaluations->get($plan->surgical_case_id . '|' . $plan->eye->value, collect())
                ->map(fn ($e) => PostOpEvaluationService::effectiveEquivalent($e))
                ->filter(fn ($se) => $se !== null)
                ->last();

            if ($obtained === null) {
                continue;
            }

            $case = $performed->firstWhere('id', $plan->surgical_case_id);
            $name = (string) ($case?->surgeon?->person?->full_name ?? '—');

            $bySurgeon[$name][] = round($obtained - (float) $plan->predicted_refraction, 2);
        }

        $rows = [];
        $all  = [];

        foreach ($bySurgeon as $name => $errors) {
            $rows[] = ['surgeon' => $name, ...$this->errorStats($errors)];
            $all    = array_merge($all, $errors);
        }

        usort($rows, fn ($a, $b) => $b['n'] <=> $a['n']);

        return ['overall' => $all === [] ? null : $this->errorStats($all), 'by_surgeon' => $rows];
    }

    /**
     * @param list<float> $errors
     *
     * @return array<string, mixed>
     */
    private function errorStats(array $errors): array
    {
        $n   = count($errors);
        $abs = array_map(fn (float $e) => abs($e), $errors);

        return [
            'n'            => $n,
            'me'           => round(array_sum($errors) / $n, 2),
            'mae'          => round(array_sum($abs) / $n, 2),
            'within_050'   => round(count(array_filter($abs, fn ($e) => round($e, 2) <= 0.50)) / $n * 100, 1),
            'within_100'   => round(count(array_filter($abs, fn ($e) => round($e, 2) <= 1.00)) / $n * 100, 1),
            'small_sample' => $n < (int) config('surgery_postop.small_sample', 10),
        ];
    }

    /** @param Collection<int, float> $values @return array{n: int, mean: float|null, median: float|null} */
    private function stats(Collection $values): array
    {
        $sorted = $values->filter(fn ($v) => $v >= 0)->sort()->values();
        $n      = $sorted->count();

        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'median' => null];
        }

        $middle = intdiv($n, 2);
        $median = $n % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

        return ['n' => $n, 'mean' => round($sorted->avg(), 1), 'median' => round((float) $median, 1)];
    }

    /**
     * Motivos de negativa: texto normalizado (minúsculas, espaços únicos) e com
     * sequências de 5+ dígitos mascaradas. Só entra na lista o motivo repetido
     * MIN_REASON_COUNT vezes ou mais (n mínimo contra reidentificação); o resto
     * é somado numa linha "Outros" (`other = true`, sem texto).
     *
     * @param Collection<int, mixed> $reasons
     *
     * @return list<array{reason: string|null, other: bool, count: int}>
     */
    private function topReasons(Collection $reasons): array
    {
        $counts = $reasons
            ->filter(fn ($r) => filled($r))
            ->map(function ($r): string {
                $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $r)));

                return mb_substr((string) preg_replace('/\d{5,}/', '…', $text), 0, 80);
            })
            ->countBy()
            ->sortDesc();

        $kept = $counts->filter(fn (int $count) => $count >= self::MIN_REASON_COUNT)->take(5);
        $rows = $kept->map(fn (int $count, string $reason) => ['reason' => $reason, 'other' => false, 'count' => $count])->values()->all();
        $rest = $counts->sum() - $kept->sum();

        if ($rest > 0) {
            $rows[] = ['reason' => null, 'other' => true, 'count' => (int) $rest];
        }

        return $rows;
    }
}
