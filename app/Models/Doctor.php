<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class Doctor extends Model
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
        'entity_user_id',
        'person_id',
        'code',
        'record',
        'record_specialty',
        'color',
        'partner',
        'active',
        'observation',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $doctor) {
            if (blank($doctor->code)) {
                $prefix = 'DOC';

                $lastType = static::withoutGlobalScopes()
                    ->whereIn('entity_user_id', $doctor->entityUser->pluck('id'))
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastType) {
                    $lastNumber = (int) substr($lastType->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $doctor->code = sprintf('%s-%010d', $prefix, $newNumber);
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

    public function entityUser(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'entity_user_id', 'id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'person_id', 'id');
    }
}
