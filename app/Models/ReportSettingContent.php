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
        // Cópia adotada × modelo padrão: de qual versão do global o texto
        // veio (nulo = personalizado) e qual atualização a clínica dispensou.
        'source_content_hash',
        'dismissed_source_hash',
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
        // Cópia por clínica PERSONALIZADA (rótulo ou texto editados desde que
        // adotou) não pode voltar pro rótulo traduzido do padrão — o médico
        // renomeou de propósito. source_content_hash null (o próprio
        // template global, sem "de onde veio") continua preferindo a
        // tradução, igual sempre foi (11ª revisão Codex, P2).
        $customized = $this->source_content_hash !== null && ! $this->isUntouchedCopy();

        if ($this->is_system && $this->slug && ! $customized) {
            $key        = 'report_content_labels.' . $this->slug;
            $translated = __($key);

            if ($translated !== $key) {
                return $translated;
            }
        }

        return $this->label ?? $this->slug ?? '';
    }

    /**
     * Impressão da versão do texto (rótulo + conteúdo) — compara a cópia da
     * clínica com o modelo padrão sem guardar o texto duas vezes.
     */
    public static function fingerprint(?string $label, ?string $content): string
    {
        return hash('sha256', ($label ?? '') . "\0" . ($content ?? ''));
    }

    public function currentFingerprint(): string
    {
        return self::fingerprint($this->label, $this->content);
    }

    /** Cópia ainda igual à versão do padrão de que veio (ninguém editou). */
    public function isUntouchedCopy(): bool
    {
        return $this->source_content_hash !== null && $this->source_content_hash === $this->currentFingerprint();
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
