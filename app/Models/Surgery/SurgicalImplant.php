<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\{ImplantKind, ImplantStatus, ImplantStockSource, SurgeryEye};
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Models\{Doctor, EntityProduct, Patient, StockLot, StockMovement};
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Implante CONGELADO (RDC 556/2021): produto, fabricante, ANVISA, modelo,
 * potência, lote, série e validade copiados na gravação. O cadastro vivo muda
 * (`ProductLotsController::update` edita lote/validade retroativamente); este
 * registro não. Nunca é apagado: erro de registro vira ANULAÇÃO (status
 * `voided` + motivo), e a anulação libera a série.
 *
 * Só o status e os dados da anulação se atualizam. Escrito por
 * SurgeryImplantService (na MESMA transação da baixa de estoque).
 *
 * @property ImplantKind        $kind
 * @property ImplantStatus      $status
 * @property ImplantStockSource $stock_source
 * @property SurgeryEye         $eye
 */
class SurgicalImplant extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasUuids;

    /** Colunas que podem mudar depois do registro (anulação). */
    private const MUTABLE = [
        'status',
        'voided_at',
        'void_reason',
        'void_stock_action',
        'voided_by',
        'reversal_movement_id',
        'loss_movement_id',
        'updated_at',
    ];

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'patient_id',
        'kind',
        'stock_source',
        'entity_product_id',
        'stock_lot_id',
        'eye',
        'product_name',
        'manufacturer',
        'anvisa_registration',
        'model_reference',
        'power',
        'lot_number',
        'serial_number',
        'expiry_date',
        'quantity',
        'serial_scope',
        'stock_movement_id',
        'status',
        'registered_by',
        'registered_by_doctor_id',
        'implanted_at',
        'content_hash',
        'plan_deviation_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $implant): void {
            $frozen = array_diff(array_keys($implant->getDirty()), self::MUTABLE);

            if ($frozen !== []) {
                throw new LogicException('Implante registrado é imutável (' . implode(', ', $frozen) . '): anule e registre de novo.');
            }

            // Anulado é definitivo: nem volta a valer, nem reescreve motivo/data/movimentos.
            if ($implant->getRawOriginal('status') === ImplantStatus::Voided->value
                && array_diff(array_keys($implant->getDirty()), ['updated_at']) !== []) {
                throw new LogicException('Implante anulado é imutável.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Implante registrado não pode ser apagado: anule.');
        });
    }

    protected function casts(): array
    {
        return [
            'kind'         => ImplantKind::class,
            'status'       => ImplantStatus::class,
            'stock_source' => ImplantStockSource::class,
            'eye'          => SurgeryEye::class,
            'power'        => 'decimal:2',
            'expiry_date'  => 'date',
            'implanted_at' => 'datetime',
            'voided_at'    => 'datetime',
            'quantity'     => 'integer',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EntityProduct::class, 'entity_product_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function registeredByDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'registered_by_doctor_id');
    }

    public function isActive(): bool
    {
        return $this->status === ImplantStatus::Active;
    }

    /** Conteúdo que entra no hash e nos documentos (valores já congelados). @return array<string, mixed> */
    public function frozenContent(): array
    {
        return [
            'kind'                => $this->kind->value,
            'stock_source'        => $this->stock_source->value,
            'eye'                 => $this->eye->value,
            'product_name'        => $this->product_name,
            'manufacturer'        => $this->manufacturer,
            'anvisa_registration' => $this->anvisa_registration,
            'model_reference'     => $this->model_reference,
            'power'               => $this->power !== null ? (string) $this->power : null,
            'lot_number'          => $this->lot_number,
            'serial_number'       => $this->serial_number,
            'expiry_date'         => $this->expiry_date?->toDateString(),
            'quantity'            => (int) $this->quantity,
            'implanted_at'        => $this->implanted_at?->toIso8601String(),
        ];
    }
}
