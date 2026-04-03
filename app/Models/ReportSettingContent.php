<?php

namespace App\Models;

use App\Enums\DocumentationType;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ReportSettingContent extends Model
{
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'report_setting_id',
        'type',
        'slug',
        'label',
        'content',
        'is_system',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type'       => DocumentationType::class,
            'is_system'  => 'boolean',
            'active'     => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Retorna o label traduzido para templates do sistema,
     * ou o label customizado do tenant.
     */
    public function getDisplayLabelAttribute(): string
    {
        if ($this->is_system && $this->slug) {
            $key        = 'report_content_labels.' . $this->slug;
            $translated = __($key);

            if ($translated !== $key) {
                return $translated;
            }
        }

        return $this->label ?? $this->slug ?? '';
    }

    public function reportSetting(): BelongsTo
    {
        return $this->belongsTo(ReportSetting::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(ReportSettingVariable::class);
    }

    public function activeVariables(): HasMany
    {
        return $this->hasMany(ReportSettingVariable::class)->where('active', true);
    }
}
