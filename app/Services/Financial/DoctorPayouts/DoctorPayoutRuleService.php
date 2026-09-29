<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\Enums\DoctorPayout\{DoctorPayoutCalculation, DoctorPayoutPayerScope, DoctorPayoutServiceType};
use App\Http\Requests\Financial\DoctorPayoutRuleRequest;
use App\Models\DoctorPayoutRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro das regras de repasse. Toda escrita passa aqui, sob um advisory
 * lock por clínica (duas abas salvando ao mesmo tempo não criam vigências
 * sobrepostas): mesmo escopo + vigência sobreposta entre regras ATIVAS é
 * recusado — o resolver nunca precisa "adivinhar" entre duas regras iguais.
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
        return DoctorPayoutRule::query()
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $doctorId))
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

                return DoctorPayoutRule::query()->create($attributes);
            });
        });
    }

    /** @param array<string, mixed> $data validado por DoctorPayoutRuleRequest */
    public function update(DoctorPayoutRule $rule, array $data): DoctorPayoutRule
    {
        return DB::transaction(function () use ($rule, $data): DoctorPayoutRule {
            $this->lock((string) $rule->entity_id);

            $attributes = $this->normalize((string) $rule->entity_id, $data);

            $this->assertNoOverlap($attributes, (string) $rule->id);

            $rule->update($attributes);

            return $rule->fresh();
        });
    }

    /** Soft delete: fechamentos antigos continuam apontando para a regra (e guardam o retrato dela). */
    public function delete(DoctorPayoutRule $rule): void
    {
        $rule->delete();
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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $key = (int) hexdec(substr(sha1('doctor_payout_rules|' . $entityId), 0, 15));

        DB::select('select pg_advisory_xact_lock(?)', [$key]);
    }
}
