<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class EntityIntegrator extends Model
{
    use Auditable;
    use HasApiTokens;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_user_integrator_id',
        'code',
        'name',
        'ip',
        'mac',
        'active',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected array $uppercaseFields = [
        'name',
        'mac',
    ];

    /**
     * Generated code for the entity_user_integrator_id field.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $entityIntegrator) {
            if (blank($entityIntegrator->code)) {
                $entityId = $entityIntegrator->user->entity_id;

                DB::transaction(static function () use ($entityIntegrator, $entityId) {
                    $prefix   = 'EI';
                    $lastCode = static::withoutGlobalScopes()
                        ->whereHas('user', fn ($q) => $q->where('entity_id', $entityId))
                        ->where('code', 'like', $prefix . '-%')
                        ->lockForUpdate() // trava as linhas
                        ->orderByDesc('code')
                        ->value('code');

                    $lastNumber = $lastCode
                        ? (int) substr($lastCode, strlen($prefix) + 1)
                        : 0;

                    $entityIntegrator->code = sprintf('%s-%010d', $prefix, $lastNumber + 1);
                });
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_session_expires_at' => 'datetime',
            'active'                   => 'boolean',
            'created_at'               => 'datetime',
            'updated_at'               => 'datetime',
            'deleted_at'               => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(EntityUserIntegrator::class, 'entity_user_integrator_id', 'id');
    }

    /**
     * Normaliza o código para o formato canônico armazenado no banco (`PREFIX-0000000001`).
     * Aceita entrada abreviada (`EI-1`), zero-padded (`EI-0000000001`) ou lower-case.
     * Entradas que não seguem o padrão PREFIX-DÍGITOS são retornadas sem alteração
     * (a query vai simplesmente não encontrar o registro).
     */
    public static function normalizeCode(string $code): string
    {
        $code = mb_strtoupper(trim($code));

        if (preg_match('/^([A-Z]+)-(\d+)$/', $code, $m)) {
            return sprintf('%s-%010d', $m[1], (int) $m[2]);
        }

        return $code;
    }

    /**
     * Extrai o id do integrador das abilities de um token Sanctum
     * (formato `integrator_id:<uuid>`). Fonte única — antes duplicado em
     * 2 middlewares e no controller de auth.
     */
    public static function idFromTokenAbilities(array $abilities): ?string
    {
        foreach ($abilities as $key => $value) {
            $ability = is_int($key) ? $value : $key;

            if (is_string($ability) && str_starts_with($ability, 'integrator_id:')) {
                return substr($ability, strlen('integrator_id:')) ?: null;
            }
        }

        return null;
    }

    /**
     * Motivo de bloqueio de acesso à API, na ordem de precedência usada
     * pelos middlewares; null quando integrador/usuário/entidade estão ativos.
     *
     * @return string|null chave de tradução `auth.*`
     */
    public function accessBlockReason(): ?string
    {
        return match (true) {
            ! $this->active                                        => 'auth.integrator_inactive',
            ! $this->user?->active                                 => 'auth.user_integrator_inactive',
            ! ($this->user->entity && $this->user->entity->active) => 'auth.entity_inactive',
            default                                                => null,
        };
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(EntityIntegratorEquipment::class, 'integrator_id', 'id');
    }

    /**
     * Override setAttribute to automatically uppercase specified fields.
     */
    public function setAttribute($key, $value): mixed
    {
        if (is_string($value) && in_array($key, $this->uppercaseFields, true)) {
            $value = mb_convert_case($value, MB_CASE_UPPER, 'UTF-8');
        }

        return parent::setAttribute($key, $value);
    }
}
