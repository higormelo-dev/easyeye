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
        // Cabeçalho
        'show_header',
        'header_show_logo',
        'header_show_name',
        'header_show_address',
        'header_show_phone',
        // Assinatura
        'show_signature',
        'signature_show_name',
        'signature_show_crm',
        'signature_show_rqe',
        'signature_bottom',
        'signature_alignment',
        // Rodapé
        'show_footer',
        'footer_text',
        'footer_show_address',
        'footer_show_phone',
        // Legado
        'show_logo',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'show_header'         => 'boolean',
            'header_show_logo'    => 'boolean',
            'header_show_name'    => 'boolean',
            'header_show_address' => 'boolean',
            'header_show_phone'   => 'boolean',
            'show_signature'      => 'boolean',
            'signature_show_name' => 'boolean',
            'signature_show_crm'  => 'boolean',
            'signature_show_rqe'  => 'boolean',
            'show_logo'           => 'boolean',
            'show_footer'         => 'boolean',
            'footer_show_address' => 'boolean',
            'footer_show_phone'   => 'boolean',
            'active'              => 'boolean',
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
