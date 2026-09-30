<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\Enums\DoctorPayout\{DoctorPayoutCalculation, DoctorPayoutPayerScope, DoctorPayoutServiceType};
use App\Http\Requests\Financial\DoctorPayoutRuleRequest;
use App\Models\{DoctorPayoutRule, DoctorPayoutRuleParticipant};
use App\Services\AuditService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro das regras de repasse. Toda escrita passa aqui, sob um advisory
 * lock por clínica (duas abas salvando ao mesmo tempo não criam vigências
 * sobrepostas): mesmo escopo + vigência sobreposta entre regras ATIVAS é
 * recusado — o resolver nunca precisa "adivinhar" entre duas regras iguais.
 *
 * O mesmo lock (lockConfig) protege a configuração do repasse (regras,
 * participantes e taxas de dedução) contra o fechamento: escritas tomam o
 * lock exclusivo e o fechamento, o compartilhado — fechamentos simultâneos
 * de médicos diferentes do mesmo ato leem a mesma regra/divisão/taxas.
 */
final class DoctorPayoutRuleService
{
    /** Colunas que definem o escopo de uma regra (além de clínica, tipo e pagador). */
    private const SCOPE_COLUMNS = ['doctor_id', 'visit_type_id', 'procedure_id', 'exam_type_id', 'covenant_id'];

    /**
     * Regras que podem valer para os itens do médico: ativas, dele ou de todos
     * os médicos. A vigência é conferida item a item pelo resolver.
     *
     * @return Collection<int, DoctorPayoutRule>
     */
    public function activeRulesFor(string $entityId, string $doctorId): Collection
    {
        return $this->activeRulesForDoctors($entityId, [$doctorId]);
    }

    /**
     * Regras ativas que podem valer para os itens de qualquer um dos médicos
     * (o resolver confere o médico de cada item).
     *
     * @param list<string> $doctorIds
     *
     * @return Collection<int, DoctorPayoutRule>
     */
    public function activeRulesForDoctors(string $entityId, array $doctorIds): Collection
    {
        return DoctorPayoutRule::query()
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('doctor_id')->orWhereIn('doctor_id', $doctorIds))
            ->get();
    }

    /**
     * Cria uma regra — ou uma por tipo de serviço quando vier "todos os tipos".
     *
     * @param array<string, mixed> $data validado por DoctorPayoutRuleRequest
     *
     * @return Collection<int, DoctorPayoutRule>
     */
    public function create(string $entityId, array $data): Collection
    {
        return DB::transaction(function () use ($entityId, $data): Collection {
            $this->lock($entityId);

            $types = $data['service_type'] === DoctorPayoutRuleRequest::ALL_TYPES
                ? DoctorPayoutServiceType::cases()
                : [DoctorPayoutServiceType::from($data['service_type'])];

            return collect($types)->map(function (DoctorPayoutServiceType $type) use ($entityId, $data): DoctorPayoutRule {
                $attributes = $this->normalize($entityId, [...$data, 'service_type' => $type->value]);

                $this->assertNoOverlap($attributes);

                $rule = DoctorPayoutRule::query()->create($attributes);
                $this->syncParticipants($rule, $data['participants'] ?? []);

                return $rule;
            });
        });
    }

    /**
     * Edita a regra. Com `effective_from` ("nova vigência a partir de"): a
     * regra atual termina na véspera e uma nova, com os dados do formulário,
     * começa nessa data — atendimentos anteriores continuam com a atual
     * (regra vale pela data do atendimento). Sem ela, é correção: vale também
     * para o que ainda não foi fechado.
     *
     * @param array<string, mixed> $data validado por DoctorPayoutRuleRequest
     */
    public function update(DoctorPayoutRule $rule, array $data): DoctorPayoutRule
    {
        if (($data['effective_from'] ?? null) !== null) {
            return $this->supersede($rule, $data);
        }

        return DB::transaction(function () use ($rule, $data): DoctorPayoutRule {
            $this->lock((string) $rule->entity_id);
            $rule->refresh(); // o binding da rota foi lido antes do lock

            $attributes = $this->normalize((string) $rule->entity_id, $data);

            $this->assertScopeUnchangedIfUsed($rule, $attributes);
            $this->assertNoOverlap($attributes, (string) $rule->id);

            $rule->update($attributes);
            $this->syncParticipants($rule, $data['participants'] ?? []);

            return $rule->fresh();
        });
    }

    /**
     * Correção de regra já usada em fechamento válido: valores podem mudar
     * (o fechamento guardou o retrato deles), o ESCOPO não — o demonstrativo
     * mostra qual regra (médico · item · pagador) foi aplicada a partir da
     * regra gravada. Para outro escopo: nova vigência ou outra regra.
     *
     * @param array<string, mixed> $attributes
     *
     * @throws ValidationException
     */
    private function assertScopeUnchangedIfUsed(DoctorPayoutRule $rule, array $attributes): void
    {
        $scope   = ['doctor_id', 'service_type', 'visit_type_id', 'procedure_id', 'exam_type_id', 'payer_scope', 'covenant_id'];
        $current = fn (string $column) => $rule->{$column} instanceof BackedEnum ? $rule->{$column}->value : $rule->{$column};
        $changed = array_values(array_filter($scope, fn (string $column) => (string) ($current($column) ?? '') !== (string) ($attributes[$column] ?? '')));

        if ($changed === []) {
            return;
        }

        $used = DB::table('doctor_payout_items')
            ->where('entity_id', $rule->entity_id)
            ->where('doctor_payout_rule_id', $rule->id)
            ->whereNull('voided_at')
            ->exists();

        if ($used) {
            throw ValidationException::withMessages([$changed[0] => __('financial_doctor_payouts.errors.rule_scope_locked')]);
        }
    }

    /**
     * Nova vigência: encerra a regra na véspera de `effective_from` e cria a
     * nova (mesmo lock e checagem de sobreposição do cadastro).
     *
     * @param array<string, mixed> $data
     */
    private function supersede(DoctorPayoutRule $rule, array $data): DoctorPayoutRule
    {
        return DB::transaction(function () use ($rule, $data): DoctorPayoutRule {
            $this->lock((string) $rule->entity_id);
            $rule->refresh(); // outra aba pode ter encerrado/editado a regra antes do lock

            $from = CarbonImmutable::parse((string) $data['effective_from']);

            // A validação do request usou a regra lida antes do lock: confere de novo.
            if (($rule->valid_from !== null && $from->toDateString() <= $rule->valid_from->toDateString())
                || ($rule->valid_until !== null && $from->toDateString() > $rule->valid_until->toDateString())) {
                throw ValidationException::withMessages(['effective_from' => __('financial_doctor_payouts.errors.effective_from_range')]);
            }

            $attributes = $this->normalize((string) $rule->entity_id, [...$data, 'valid_from' => $from->toDateString()]);

            $rule->update(['valid_until' => $from->subDay()->toDateString()]);

            $this->assertNoOverlap($attributes, (string) $rule->id);

            $next = DoctorPayoutRule::query()->create($attributes);
            $this->syncParticipants($next, $data['participants'] ?? []);

            return $next->fresh();
        });
    }

    /**
     * Participantes da divisão (E4): substitui o conjunto (fechamentos antigos
     * guardam o retrato). Regra de valor fixo não divide: fica sem participantes.
     * Mudança no conjunto vai para a trilha de auditoria da regra (os
     * participantes não têm auditoria própria e a regra pode nem mudar).
     *
     * @param list<array{role: string, doctor_id: ?string, percentage: string|float}> $participants validados
     */
    private function syncParticipants(DoctorPayoutRule $rule, array $participants): void
    {
        $before = $this->participantsSnapshot($rule);

        DoctorPayoutRuleParticipant::query()->where('doctor_payout_rule_id', $rule->id)->delete();

        if ($rule->calculation === DoctorPayoutCalculation::Percentage) {
            foreach (array_values($participants) as $order => $participant) {
                DoctorPayoutRuleParticipant::query()->create([
                    'entity_id'             => $rule->entity_id,
                    'doctor_payout_rule_id' => $rule->id,
                    'role'                  => $participant['role'],
                    'doctor_id'             => $participant['role'] === DoctorPayoutRuleParticipant::ROLE_DOCTOR ? $participant['doctor_id'] : null,
                    'percentage'            => $participant['percentage'],
                    'sort_order'            => $order,
                ]);
            }
        }

        $after = $this->participantsSnapshot($rule);

        if ($before !== $after) {
            app(AuditService::class)->log('updated', $rule, ['participants' => $before], ['participants' => $after]);
        }
    }

    /** @return list<array{role: string, doctor_id: ?string, percentage: string}> */
    private function participantsSnapshot(DoctorPayoutRule $rule): array
    {
        return DoctorPayoutRuleParticipant::query()
            ->where('doctor_payout_rule_id', $rule->id)
            ->orderBy('sort_order')
            ->get(['role', 'doctor_id', 'percentage'])
            ->map(fn (DoctorPayoutRuleParticipant $participant) => [
                'role'       => (string) $participant->role,
                'doctor_id'  => $participant->doctor_id === null ? null : (string) $participant->doctor_id,
                'percentage' => number_format((float) $participant->percentage, 2, '.', ''),
            ])
            ->values()
            ->all();
    }

    /** Soft delete: fechamentos antigos continuam apontando para a regra (e guardam o retrato dela). */
    public function delete(DoctorPayoutRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $this->lock((string) $rule->entity_id);

            $rule->delete();
        });
    }

    /**
     * Campos coerentes entre si (o que o tipo de cálculo/pagador não usa vira null).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(string $entityId, array $data): array
    {
        $calculation = DoctorPayoutCalculation::from($data['calculation']);
        $payerScope  = DoctorPayoutPayerScope::from($data['payer_scope']);

        return [
            'entity_id'     => $entityId,
            'doctor_id'     => $data['doctor_id'] ?? null,
            'service_type'  => $data['service_type'],
            'visit_type_id' => $data['visit_type_id'] ?? null,
            'procedure_id'  => $data['procedure_id'] ?? null,
            'exam_type_id'  => $data['exam_type_id'] ?? null,
            'payer_scope'   => $payerScope->value,
            'covenant_id'   => $payerScope === DoctorPayoutPayerScope::Covenant ? ($data['covenant_id'] ?? null) : null,
            'calculation'   => $calculation->value,
            'percentage'    => $calculation === DoctorPayoutCalculation::Percentage ? $data['percentage'] : null,
            'fixed_amount'  => $calculation === DoctorPayoutCalculation::Fixed ? $data['fixed_amount'] : null,
            'valid_from'    => $data['valid_from'] ?? null,
            'valid_until'   => $data['valid_until'] ?? null,
            'active'        => (bool) ($data['active'] ?? true),
            'notes'         => $data['notes'] ?? null,
        ];
    }

    /**
     * Mesmo escopo + vigências que se cruzam = duas regras disputando o mesmo
     * item. Regra inativa não disputa (a checagem volta ao reativar).
     *
     * @param array<string, mixed> $attributes
     *
     * @throws ValidationException
     */
    private function assertNoOverlap(array $attributes, ?string $ignoreId = null): void
    {
        if (! $attributes['active']) {
            return;
        }

        $from  = $attributes['valid_from'];
        $until = $attributes['valid_until'];

        $query = DoctorPayoutRule::query()
            ->where('entity_id', $attributes['entity_id'])
            ->where('active', true)
            ->where('service_type', $attributes['service_type'])
            ->where('payer_scope', $attributes['payer_scope'])
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            // Intervalos [a, b] e [c, d] (nulo = sem limite) se cruzam se a <= d e c <= b.
            ->when($until !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', $until)))
            ->when($from !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $from)));

        foreach (self::SCOPE_COLUMNS as $column) {
            $attributes[$column] === null
                ? $query->whereNull($column)
                : $query->where($column, $attributes[$column]);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'valid_from' => __('financial_doctor_payouts.errors.rule_overlap'),
            ]);
        }
    }

    /** Serializa as escritas de regras da clínica até o COMMIT (PostgreSQL). */
    private function lock(string $entityId): void
    {
        self::lockConfig($entityId);
    }

    /**
     * Lock da configuração do repasse da clínica até o COMMIT (PostgreSQL):
     * exclusivo para escrever regras/participantes/taxas; compartilhado para
     * fechar (fechamentos não se bloqueiam entre si).
     */
    public static function lockConfig(string $entityId, bool $shared = false): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $key = (int) hexdec(substr(sha1('doctor_payout_rules|' . $entityId), 0, 15));

        DB::select($shared ? 'select pg_advisory_xact_lock_shared(?)' : 'select pg_advisory_xact_lock(?)', [$key]);
    }
}
