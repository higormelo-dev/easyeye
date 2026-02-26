<?php

namespace App\Models;

use App\Presenters\SurgeryTypePresenter;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Laracasts\Presenter\PresentableTrait;

class SurgeryType extends Model
{
    use HasUuids;
    use PresentableTrait;
    use SoftDeletes;

    protected $primaryKey = 'id';

    protected $presenter = SurgeryTypePresenter::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'code',
        'category',
        'name',
        'active',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $surgeryType) {
            if (blank($surgeryType->code)) {
                $prefix = $surgeryType->entity_id ? 'AT' : 'ATP';

                $lastType = static::withoutGlobalScopes()
                    ->when(
                        $surgeryType->entity_id !== null,
                        fn ($q) => $q->where('entity_id', $surgeryType->entity_id),
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

                $surgeryType->code = sprintf('%s-%010d', $prefix, $newNumber);
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

    /**
     * Categories of surgery types
     *
     * @var array<int, string>
     */
    public static array $categories = [
        0  => 'CIRURGIAS DE OUTROS',
        1  => 'CIRURGIAS DE CATARATA',
        2  => 'CIRURGIAS REFRATIVAS',
        3  => 'CIRURGIAS DE RETINA E VÍTREO',
        4  => 'CIRURGIAS DE GLAUCOMA',
        5  => 'CIRURGIAS DA CÓRNEA',
        6  => 'CIRURGIA DE PTERÍGIO',
        7  => 'CIRURGIAS PALPEBRAIS (OCULOPLASTIA)',
        8  => 'CIRURGIAS DO SISTEMA LACRIMAL',
        9  => 'CIRURGIAS DE ESTRABISMO',
        10 => 'CIRURGIAS ORBITÁRIAS',
        11 => 'CIRURGIAS DE TRAUMA OCULAR',
        12 => 'CIRURGIAS DE REMOÇÃO OCULAR',
        13 => 'PROCEDIMENTOS A LASER',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
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
