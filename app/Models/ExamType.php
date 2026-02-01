<?php

namespace App\Models;

use App\Enums\ExamCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

class ExamType extends Model
{
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
        'category',
        'active',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $examType) {
            if (blank($examType->code)) {
                $prefix = $examType->entity_id ? 'ET' : 'ETP';

                $lastExamType = static::withoutGlobalScopes()
                    ->when(
                        $examType->entity_id !== null,
                        fn ($q) => $q->where('entity_id', $examType->entity_id),
                        fn ($q) => $q->whereNull('entity_id')
                    )
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastExamType) {
                    $lastNumber = (int) substr($lastExamType->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $examType->code = sprintf('%s-%010d', $prefix, $newNumber);
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
            'category'   => ExamCategory::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
