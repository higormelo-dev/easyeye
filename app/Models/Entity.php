<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function entityUsers(): HasMany
    {
        return $this->hasMany(EntityUser::class, 'entity_id', 'id');
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
