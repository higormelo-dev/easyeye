<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Casts\Attribute, Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Covenant extends Model
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
        'entity_id',
        'code',
        'name',
        'color',
        'table',
        'active',
    ];

    /**
     * Fields that should be uppercased.
     *
     * @var list<string>
     */
    protected array $uppercaseFields = [
        'name',
        'color',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $covenant) {
            if (blank($covenant->code)) {
                $prefix = $covenant->entity_id ? 'CV' : 'CVP';

                $lastType = static::withoutGlobalScopes()
                    ->when(
                        $covenant->entity_id !== null,
                        fn ($q) => $q->where('entity_id', $covenant->entity_id),
                        fn ($q) => $q->whereNull('entity_id')
                    )
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastType) {
                    $lastNumber = (int) substr($lastType->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $covenant->code = sprintf('%s-%010d', $prefix, $newNumber);
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

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
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
