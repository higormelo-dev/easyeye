<?php

namespace App\Models;

use App\Services\EntityRoleService;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Entity extends Model
{
    use HasAuditColumns;
    use Auditable;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /** Set to true to prevent EntityObserver from auto-starting a trial on create. */
    public bool $skipAutoTrial = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'subdomain',
        'zipcode',
        'address',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'country',
        'national_registration',
        'state_registration',
        'municipal_registration',
        'telephone',
        'cellphone',
        'email',
        'website',
        'logo',
        'is_client',
        'active',
        'locale',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected array $uppercaseFields = [
        'name',
        'address',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'country',
    ];

    /**
     * The attributes that should contain only numbers.
     *
     * @var list<string>
     */
    protected array $numericOnlyFields = [
        'national_registration',
        'telephone',
        'cellphone',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $entity) {
            if (blank($entity->code)) {
                $prefix = 'ENT';

                $lastEntity = static::withoutGlobalScopes()
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastEntity) {
                    $lastNumber = (int) substr($lastEntity->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $entity->code = sprintf('%s-%010d', $prefix, $newNumber);
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
            'is_client'  => 'boolean',
            'active'     => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Entity-type helpers
    // -------------------------------------------------------------------------

    /**
     * Return true when this entity is the SaaS owner (not a client).
     * Determined by the is_client flag; ENT-0000000001 is always the SaaS entity.
     */
    public function isSaas(): bool
    {
        return ! $this->is_client;
    }

    /**
     * Return true when this entity is a client (tenant) of the SaaS.
     */
    public function isClient(): bool
    {
        return (bool) $this->is_client;
    }

    /**
     * Return the valid rule values for this entity's context.
     * Delegates to EntityRoleService so the logic stays in one place.
     *
     * @return string[]
     */
    public function validRules(): array
    {
        return app(EntityRoleService::class)->validRuleValues($this);
    }

    /**
     * Return key-value options (value => label) for this entity's valid rules,
     * ready to be used in select inputs.
     *
     * @return array<string, string>
     */
    public function validRuleOptions(): array
    {
        return app(EntityRoleService::class)->validRuleOptions($this);
    }

    /**
     * Check whether the given rule string is valid for this entity's context.
     */
    public function isValidRule(string $rule): bool
    {
        return app(EntityRoleService::class)->isValidRule($this, $rule);
    }

    /**
     * Alias for isSaas() — true when this entity is the SaaS owner.
     */
    public function isManagerEntity(): bool
    {
        return $this->isSaas();
    }

    /**
     * Alias for validRules() — returns the allowed rule string values for this entity.
     *
     * @return string[]
     */
    public function allowedRoles(): array
    {
        return $this->validRules();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'entity_users', 'entity_id', 'user_id')
            ->withPivot(['id', 'code', 'rule', 'is_owner', 'active', 'invited_by', 'joined_at'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    public function entityUsers(): HasMany
    {
        return $this->hasMany(EntityUser::class, 'entity_id', 'id');
    }

    /** Assinatura ativa da empresa (trial ou paga, sem soft-delete). */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'entity_id')
            ->whereIn('status', [\App\Enums\SubscriptionStatus::Trial->value, \App\Enums\SubscriptionStatus::Active->value])
            ->latest();
    }

    /** Histórico completo de assinaturas. */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'entity_id');
    }

    /**
     * Override setAttribute to automatically uppercase specified fields.
     */
    public function setAttribute($key, $value): mixed
    {
        if (is_string($value) && in_array($key, $this->uppercaseFields, true)) {
            $value = mb_convert_case($value, MB_CASE_UPPER, 'UTF-8');
        }

        if (is_string($value) && in_array($key, $this->numericOnlyFields, true)) {
            $value = $this->onlyNumbers($value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Remove all non-numeric characters from a string.
     */
    private function onlyNumbers(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
