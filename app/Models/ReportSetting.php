<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ReportSetting extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'title',
        'paper_size',
        'font_family',
        'font_size',
        'margin_top',
        'margin_right',
        'margin_bottom',
        'margin_left',
        'patient_font_size',
        'patient_alignment',
        'patient_color',
        'show_signature',
        'signature_bottom',
        'signature_alignment',
        'show_logo',
        'show_footer',
        'footer_text',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'show_signature' => 'boolean',
            'show_logo'      => 'boolean',
            'show_footer'    => 'boolean',
            'active'         => 'boolean',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(ReportSettingContent::class);
    }
}
