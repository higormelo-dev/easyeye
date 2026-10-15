<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{ImplantKind, SurgicalCaseStatus};
use App\Events\Surgery\SurgeryPerformed;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\{SurgicalCase, SurgicalImplant, SurgicalOperation};
use App\Services\Surgery\Guards\{AuthorizationStillValidGuard, ConsentSignedGuard, SurgeryPerformGuard};
use App\Support\Surgery\LocalTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Auth, DB};

/**
 * Registrar a cirurgia como REALIZADA (ADR 0003, M4).
 *
 * Numa transação: trava o caso, relê, e só então confere o estado ATUAL
 * (Agendada) — duas chamadas simultâneas têm um efeito só; a segunda recebe
 * 409 sem gravar nada. Depois trava os implantes (id crescente), roda as
 * condições (autorização ainda válida + as registradas em
 * `config('surgery.perform_guards')`, ex.: Pausa Cirúrgica fechada), confere o
 * implante da LIO planejada e grava o ato.
 *
 * `SurgeryPerformed` sai DEPOIS do commit (DB::afterCommit), fora de qualquer
 * trava do caso: o ouvinte do pós-operatório cria os retornos na Agenda, que
 * tem a própria ordem de locks.
 */
class SurgeryPerformService
{
    public function __construct(
        private readonly SurgicalCaseService $cases,
        private readonly SurgeryParticipants $participants,
        private readonly AuthorizationStillValidGuard $authorizationGuard,
    ) {
    }

    /**
     * @param array<string, mixed> $input team_confirmed (obrigatório), performed_at, complications,
     *                                    no_implant_reason, authorization_override_reason e as
     *                                    justificativas de exceção das demais condições
     */
    public function perform(SurgicalCase $case, array $input, Doctor $performer, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $input, $performer, $expectedRevision): SurgicalCase {
            $locked = $this->cases->lock((string) $case->getKey());

            // Idempotência: quem chega depois da primeira gravação não repete nada.
            if ($locked->status === SurgicalCaseStatus::Performed || $locked->status === SurgicalCaseStatus::Discharged) {
                throw SurgeryRuleException::state('surgery_operation.errors.already_performed');
            }

            if ($locked->status !== SurgicalCaseStatus::Scheduled) {
                throw SurgeryRuleException::state('surgery_operation.errors.perform_needs_scheduled', ['status' => $locked->status->label()]);
            }

            $this->cases->assertRevision($locked, $expectedRevision);
            $this->participants->assertSurgeon($locked, $performer);

            if (! filter_var($input['team_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw SurgeryRuleException::invalid('surgery_operation.errors.team_confirmation_required');
            }

            $performedAt           = $this->performedAt($locked, $input['performed_at'] ?? null);
            $input['performed_at'] = $performedAt->toIso8601String();

            $implants = SurgicalImplant::query()
                ->where('surgical_case_id', $locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->authorizationGuard->assert($locked, $input, $performer);

            foreach ((array) config('surgery.perform_guards', []) as $class) {
                /** @var SurgeryPerformGuard $guard */
                $guard = app($class);
                $guard->assert($locked, $input, $performer);
            }

            $deviationReason = $this->unjustifiedDeviation($locked, $implants->filter(fn (SurgicalImplant $i) => $i->isActive()), $input);

            $noImplantReason = $this->assertImplants($locked, $implants->filter(fn (SurgicalImplant $i) => $i->isActive()), $input);

            $this->cases->changeStatus($locked, SurgicalCaseStatus::Performed, null, ['performed_at' => $performedAt]);

            $overrides = [];

            if ($deviationReason !== null) {
                $overrides['plan_deviation'] = $deviationReason;
            }

            if ($this->authorizationGuard->overrideUsed()) {
                $overrides['authorization'] = trim((string) $input['authorization_override_reason']);
            }

            // TCLE sem cópia assinada só passou com a justificativa do cirurgião: fica gravada.
            if (in_array(ConsentSignedGuard::class, (array) config('surgery.perform_guards', []), true)
                && ConsentSignedGuard::state($locked) !== 'signed') {
                $overrides['consent'] = trim((string) $input['consent_override_reason']);
            }

            SurgicalOperation::query()->create([
                'entity_id'              => $locked->entity_id,
                'surgical_case_id'       => $locked->getKey(),
                'performed_by'           => Auth::id(),
                'performed_by_doctor_id' => $performer->getKey(),
                'performed_at'           => $performedAt,
                'team_confirmed_at'      => now(),
                'complications'          => filled($input['complications'] ?? null) ? trim((string) $input['complications']) : null,
                'no_implant_reason'      => $noImplantReason,
                'override_reasons'       => $overrides,
            ]);

            // Só o id vai para a fila: o model inteiro carregaria PHI e credenciais da operadora.
            DB::afterCommit(fn () => SurgeryPerformed::dispatch((string) $locked->getKey()));

            return $locked;
        });
    }

    /**
     * LIO planejada exige o implante ATIVO do tipo LIO em cada olho planejado;
     * sem ele, só com a justificativa "sem implante" do cirurgião (mínimo de 10
     * caracteres), que fica gravada. Devolve a justificativa usada (ou null).
     *
     * @param Collection<int, SurgicalImplant> $active
     * @param array<string, mixed>             $input
     */
    private function assertImplants(SurgicalCase $locked, $active, array $input): ?string
    {
        $planned = $locked->iolPlans()->get();

        if ($planned->isEmpty()) {
            return null;
        }

        $implantedEyes = $active
            ->filter(fn (SurgicalImplant $i) => $i->kind === ImplantKind::Iol)
            ->map(fn (SurgicalImplant $i) => $i->eye->value)
            ->unique();

        $missing = $planned->filter(fn ($plan) => ! $implantedEyes->contains($plan->eye->value));

        if ($missing->isEmpty()) {
            return null;
        }

        $reason = trim((string) ($input['no_implant_reason'] ?? ''));

        if (mb_strlen($reason) < AuthorizationStillValidGuard::MIN_REASON) {
            throw SurgeryRuleException::state('surgery_operation.errors.implant_required_for_iol_plan', [
                'eyes' => $missing->map(fn ($p) => $p->eye->label())->implode(', '),
                'min'  => AuthorizationStillValidGuard::MIN_REASON,
            ]);
        }

        return $reason;
    }

    /**
     * LIO vigente que difere da planejada do olho e NÃO tem justificativa própria
     * (o plano foi alterado depois do registro): pede `plan_deviation_reason` no ato.
     *
     * @param Collection<int, SurgicalImplant> $active
     * @param array<string, mixed>             $input
     */
    private function unjustifiedDeviation(SurgicalCase $locked, $active, array $input): ?string
    {
        $plans = $locked->iolPlans()->get()->keyBy(fn ($p) => $p->eye->value);

        $pending = $active->filter(fn (SurgicalImplant $i) => $i->kind === ImplantKind::Iol
            && blank($i->plan_deviation_reason)
            && $plans->has($i->eye->value)
            && SurgeryImplantService::deviates($i->power, $i->entity_product_id, $plans[$i->eye->value]));

        if ($pending->isEmpty()) {
            return null;
        }

        $reason = trim((string) ($input['plan_deviation_reason'] ?? ''));

        if (mb_strlen($reason) < AuthorizationStillValidGuard::MIN_REASON) {
            throw SurgeryRuleException::state('surgery_operation.errors.plan_deviation_required', [
                'eyes' => $pending->map(fn (SurgicalImplant $i) => $i->eye->label())->implode(', '),
                'min'  => AuthorizationStillValidGuard::MIN_REASON,
            ]);
        }

        return $reason;
    }

    private function performedAt(SurgicalCase $locked, mixed $value): Carbon
    {
        $at = LocalTime::parse(filled($value) ? (string) $value : null) ?? now();

        if ($at->greaterThan(now()->addMinutes(5))) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.performed_at_future');
        }

        if ($locked->scheduled_start !== null && $at->lessThan($locked->scheduled_start->copy()->startOfDay())) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.performed_at_before_scheduled');
        }

        return $at;
    }
}
