<?php

namespace App\Models;

use App\Enums\MedicineSource;
use App\Services\Medicines\CmedPresentationParser;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Medicine extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'medicine_presentation_id',
        'name',
        'dosage',
        'frequency',
        'duration',
        'instructions',
        'active',
        'active_ingredient',
        'concentration',
        'pharmaceutical_form',
        'presentation_detail',
        'laboratory',
        'anvisa_registration',
        'ean',
        'regulatory_category',
        'therapeutic_class',
        'is_ophthalmic',
        'is_marketed',
        'source',
        'source_code',
        'source_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'active'           => 'boolean',
            'is_ophthalmic'    => 'boolean',
            'is_marketed'      => 'boolean',
            'source'           => MedicineSource::class,
            'source_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // search_text sempre derivado dos campos (nunca editado à mão) — a
        // importação em massa (upsert) calcula com o mesmo searchTextFor().
        static::saving(function (self $medicine): void {
            // Itens curados guardam a apresentação ("Colírio") na relação —
            // entra na busca ("colirio latano" acha o curado também).
            $presentation = $medicine->medicine_presentation_id
                ? MedicinePresentation::withoutGlobalScopes()->whereKey($medicine->medicine_presentation_id)->value('name')
                : null;

            $medicine->search_text = self::searchTextFor($medicine->getAttributes(), $presentation);
        });
    }

    /**
     * Texto de busca do receituário: nome, princípio ativo (genérico),
     * concentração, forma (+ "colírio" pras formas em gotas), apresentação e
     * laboratório — minúsculo e sem acento (ver normalizeSearch()).
     *
     * @param array<string, mixed> $attributes
     */
    public static function searchTextFor(array $attributes, ?string $presentationName = null): string
    {
        $form = $attributes['pharmaceutical_form'] ?? null;

        $parts = [
            $attributes['name'] ?? '',
            $attributes['active_ingredient'] ?? '',
            $attributes['concentration'] ?? '',
            $form ? __('medicine_forms.' . $form) : '',
            $presentationName ?? '',
            in_array($form, CmedPresentationParser::EYE_DROP_FORMS, true) ? __('medicine_forms.eye_drop') : '',
            $attributes['presentation_detail'] ?? '',
            $attributes['laboratory'] ?? '',
        ];

        return self::normalizeSearch(implode(' ', array_filter(array_map('strval', $parts))));
    }

    public static function normalizeSearch(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($text), 'UTF-8')));
    }

    /** Forma farmacêutica legível (código CMED traduzido). */
    public function formLabel(): ?string
    {
        return $this->pharmaceutical_form ? __('medicine_forms.' . $this->pharmaceutical_form) : null;
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(MedicinePresentation::class, 'medicine_presentation_id');
    }
}
