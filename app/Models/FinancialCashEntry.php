<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PaymentMethodCast;
use App\Concerns\HasEntityCode;
use App\Enums\{CashEntryNature, CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

class FinancialCashEntry extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'FLC';

    protected string $codePrefixGlobal = 'FLP';

    protected $fillable = [
        'entity_id',
        'category_id',
        'covenant_id',
        'billing_claim_id',
        'patient_id',
        'doctor_id',
        'procedure_id',
        'code',
        'entry_date',
        'description',
        'type',
        'status',
        'amount',
        'amount_cash',
        'amount_credit',
        'amount_debit',
        'installments',
        'payment_method',
        'nature',
        'reference_type',
        'reference_id',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'entry_date'     => 'date',
            'type'           => FinancialEntryType::class,
            'status'         => FinancialEntryStatus::class,
            'payment_method' => PaymentMethodCast::class,
            'nature'         => CashEntryNature::class,
            'amount'         => 'decimal:2',
            'amount_cash'    => 'decimal:2',
            'amount_credit'  => 'decimal:2',
            'amount_debit'   => 'decimal:2',
            'installments'   => 'integer',
            'active'         => 'boolean',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
            'deleted_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Invariante de sistema (defesa em profundidade além da request): a
        // referência só aceita os tipos da whitelist. Antes o reference_type
        // vinha livre do cliente e alimentava um morphTo (qualquer classe).
        // Só checa quando muda — linhas legadas continuam editáveis e são
        // listadas por `php artisan financial:audit-cash-references`.
        static::saving(function (self $entry): void {
            $type = $entry->getAttribute('reference_type');

            if ($entry->isDirty('reference_type') && $type !== null
                && CashEntryReferenceType::tryFrom((string) $type) === null) {
                throw new InvalidArgumentException('Tipo de referência de lançamento de caixa não permitido.');
            }
        });
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        // Sessão sem entity_id (ex.: vínculo desativado em sessão já aberta) não
        // pode virar "sem filtro" — isso resolveria qualquer registro do sistema
        // pelo ID, quebrando isolamento entre clínicas.
        $entityId = session('selected_entity_id');

        abort_unless($entityId, 403);

        return static::where($field ?? $this->getRouteKeyName(), $value)
            ->where('entity_id', $entityId)
            ->firstOrFail();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function billingClaim(): BelongsTo
    {
        return $this->belongsTo(BillingClaim::class, 'billing_claim_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class, 'procedure_id');
    }

    /**
     * Lançamentos vinculados a um registro do tipo informado que pertence à
     * MESMA clínica do lançamento. É assim que toda leitura da referência deve
     * ser feita: uma linha legada/forjada apontando para agendamento de outra
     * clínica não pode marcá-lo como pago nem bloquear o recebimento dele.
     *
     * Funciona em lazy e eager load (subquery correlacionada, sem depender do
     * model pai) e independe do EntityScope global, que fica inerte em
     * job/CLI/webhook.
     */
    public function scopeReferencingSameEntity(Builder $query, CashEntryReferenceType $type): Builder
    {
        $table = $this->getTable();

        return $query
            ->where("{$table}.reference_type", $type->value)
            ->whereExists(fn (QueryBuilder $target) => $target
                ->selectRaw('1')
                ->from($type->table() . ' as cash_reference_target')
                ->whereColumn('cash_reference_target.id', "{$table}.reference_id")
                ->whereColumn('cash_reference_target.entity_id', "{$table}.entity_id"));
    }
}
