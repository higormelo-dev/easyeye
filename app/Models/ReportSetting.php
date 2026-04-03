<?php

namespace App\Models;

use App\Enums\{PaperSize, ReportSettingStatus};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ReportSetting extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'report_category_id',
        'source_setting_id',
        'source_version',
        'version',
        'status',
        'published_at',
        'title',
        'description',
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
            'paper_size'          => PaperSize::class,
            'status'              => ReportSettingStatus::class,
            'published_at'        => 'datetime',
            'version'             => 'integer',
            'source_version'      => 'integer',
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

    // ─── Relationships ──────────────────────────────────────────────────────

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReportCategory::class, 'report_category_id');
    }

    public function sourceSetting(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_setting_id');
    }

    public function adoptedCopies(): HasMany
    {
        return $this->hasMany(self::class, 'source_setting_id');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(ReportSettingContent::class);
    }

    public function activeContents(): HasMany
    {
        return $this->hasMany(ReportSettingContent::class)->where('active', true);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('entity_id');
    }

    public function scopeForEntity(Builder $query, string $entityId): Builder
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ReportSettingStatus::Published);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    public function isGlobal(): bool
    {
        return $this->entity_id === null;
    }

    public function isAdopted(): bool
    {
        return $this->source_setting_id !== null;
    }

    public function hasUpdateAvailable(): bool
    {
        if (! $this->isAdopted()) {
            return false;
        }

        $source = $this->sourceSetting;

        return $source
            && $source->version > $this->source_version;
    }
}
