<?php

namespace App\Models;

use App\Enums\ExamCategory;
use Illuminate\Database\Eloquent\{Casts\Attribute, Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExamType extends Model
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

    /**
     * Categories of surgery types
     *
     * @var array<int, string>
     */
    public static array $categories = [
        1  => 'EXAMES BÁSICOS / AVALIAÇÃO INICIAL',
        2  => 'REFRAÇÃO E CORREÇÃO VISUAL',
        3  => 'RETINA E VÍTREO',
        4  => 'GLAUCOMA',
        5  => 'CÓRNEA E SUPERFÍCIE OCULAR',
        6  => 'CATARATA E CRISTALINO',
        7  => 'ESTRABISMO E VISÃO BINOCULAR',
        8  => 'OFTALMOPEDIATRIA',
        9  => 'NEURO-OFTALMOLOGIA',
        10 => 'TESTES FUNCIONAIS E COMPLEMENTARES',
        11 => 'EXAMES PRÉ E PÓS-OPERATÓRIOS',
        12 => 'OUTROS',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value !== null
                ? mb_convert_case($value, MB_CASE_UPPER, 'UTF-8')
                : null,
        );
    }
}
