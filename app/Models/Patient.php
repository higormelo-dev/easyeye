<?php

namespace App\Models;

use App\Presenters\PatientPresenter;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Laracasts\Presenter\PresentableTrait;

class Patient extends Model
{
    use HasUuids;
    use SoftDeletes;
    use PresentableTrait;

    protected $presenter = PatientPresenter::class;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'person_id',
        'covenant_id',
        'skin_id',
        'iris_id',
        'code',
        'card_number',
        'active',
    ];

    /**
     * Generated code for the entity_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $patient) {
            if (blank($patient->code)) {
                $prefix = 'PAC';

                $lastPatient = static::withoutGlobalScopes()
                    ->where('entity_id', $patient->entity_id)
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastPatient) {
                    $lastNumber = (int) substr($lastPatient->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $patient->code = sprintf('%s-%010d', $prefix, $newNumber);
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
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'person_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function skinType(): BelongsTo
    {
        return $this->belongsTo(SkinType::class, 'skin_id');
    }

    public function irisType(): BelongsTo
    {
        return $this->belongsTo(IrisType::class, 'iris_id');
    }
}
