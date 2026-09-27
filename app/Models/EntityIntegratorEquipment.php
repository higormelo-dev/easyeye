<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class EntityIntegratorEquipment extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'entity_integrator_equipments';

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'integrator_id',
        'code',
        'name',
        'ip',
        'mac',
        'serial_number',
        'active',
        'clinic_resource_id',
    ];

    /**
     * Fields that should be converted to UPPERCASE before saving.
     */
    private const UPPERCASE_FIELDS = ['name', 'mac', 'serial_number'];

    /** Prefixo do código sequencial por integrador (EIQ-0000000001). */
    public const CODE_PREFIX = 'EIQ';

    /** Namespace da chave do advisory lock da numeração EIQ (PostgreSQL). */
    private const CODE_LOCK_NAMESPACE = 'integrator_equipment_code';

    /**
     * Get the mac attribute (uppercase) - PostgreSQL macaddr type stores in lowercase.
     */
    protected function mac(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value) => $value !== null ? mb_strtoupper($value, 'UTF-8') : null,
        );
    }

    /**
     * Boot the model and register events.
     */
    protected static function booted(): void
    {
        // Convert fields to uppercase before saving
        static::saving(static function (self $model) {
            foreach (self::UPPERCASE_FIELDS as $field) {
                $value = $model->getAttribute($field);

                if (is_string($value)) {
                    $model->{$field} = mb_strtoupper($value, 'UTF-8');
                }
            }
        });

        // Generate code on creating
        static::creating(static function (self $entityIntegratorEquipment) {
            if (blank($entityIntegratorEquipment->code)) {
                $entityIntegratorEquipment->assignNextCode();
            }
        });
    }

    /**
     * INSERT sempre dentro de uma transação: o lock da numeração
     * (pg_advisory_xact_lock, tomado no `creating`) só é liberado no COMMIT,
     * então o próximo equipamento do mesmo integrador já enxerga o código
     * gravado. Dentro de transação do chamador (EntityIntegratorEquipmentService
     * ::create) vira SAVEPOINT e o lock vale até o commit externo.
     *
     * @param array<string, mixed> $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    /**
     * Próximo EIQ do INTEGRADOR (a numeração é por integrator_id, por desenho:
     * cada PC integrador da clínica tem seu EIQ-0000000001), serializado por
     * integrador. Antes: último + 1 sem lock — dois POST /equipments
     * simultâneos do mesmo integrador gravavam o mesmo código.
     *
     * PostgreSQL: advisory lock de transação por integrador; demais drivers:
     * lock na linha do integrador.
     */
    private function assignNextCode(): void
    {
        $integratorId = (string) $this->integrator_id;
        $connection   = $this->getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(?)', [static::codeLockKey($integratorId)]);
        } else {
            EntityIntegrator::query()->withoutGlobalScopes()->whereKey($integratorId)->lockForUpdate()->first();
        }

        // withoutGlobalScopes: conta também os excluídos por soft delete (o código deles não é reaproveitado).
        $lastCode = static::withoutGlobalScopes()
            ->where('integrator_id', $integratorId)
            ->where('code', 'like', self::CODE_PREFIX . '-%')
            ->orderBy('code', 'desc')
            ->value('code');

        $newNumber = $lastCode !== null
            ? ((int) substr($lastCode, strlen(self::CODE_PREFIX) + 1)) + 1
            : 1;

        $this->code = static::formatCode($newNumber);
    }

    /**
     * Chave bigint estável por integrador para o advisory lock da numeração
     * (60 bits do sha1, sempre positiva). Pública para os testes de
     * concorrência disputarem o mesmo lock.
     */
    public static function codeLockKey(string $integratorId): int
    {
        return (int) hexdec(substr(sha1(self::CODE_LOCK_NAMESPACE . '|' . $integratorId), 0, 15));
    }

    public static function formatCode(int $number): string
    {
        return sprintf('%s-%010d', self::CODE_PREFIX, $number);
    }

    /**
     * Filtra pelo identificador externo do equipamento: UUID, código
     * (EIQ-0000000002) ou só o número (2). NÃO escopa o integrador — o código
     * só é único dentro do integrador, então o chamador SEMPRE combina com
     * where('integrator_id', ...).
     */
    public function scopeWhereIdentifier(Builder $query, string $identifier): Builder
    {
        $identifier = trim($identifier);

        return match (true) {
            Str::isUuid($identifier) => $query->whereKey($identifier),
            ctype_digit($identifier) => $query->where('code', static::formatCode((int) $identifier)),
            default                  => $query->where('code', $identifier),
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function integrator(): BelongsTo
    {
        return $this->belongsTo(EntityIntegrator::class, 'integrator_id', 'id');
    }

    public function patientExams(): HasMany
    {
        return $this->hasMany(PatientExam::class, 'entity_integrator_equipment_id');
    }

    /**
     * Recurso de agenda (`clinic_resources.type = 'equipment'`) vinculado a
     * este equipamento — opcional, usado para escopar a Modality Worklist a
     * só os agendamentos reservados para este aparelho.
     */
    public function clinicResource(): BelongsTo
    {
        return $this->belongsTo(ClinicResource::class);
    }
}
