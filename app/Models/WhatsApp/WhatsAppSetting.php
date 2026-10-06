<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Str;

/**
 * Configuração do WhatsApp oficial (Gupshup) — linha GLOBAL (entity_id
 * nulo: app/número do EasyEye, padrão de todas as clínicas) ou da clínica
 * (toggles de confirmação/pesquisa e, opcionalmente, app próprio).
 *
 * - app_id: id do app na Gupshup, em claro (não é segredo; roteia o
 *   webhook). Os tokens do parceiro ficam só no .env; os do app, só em cache.
 * - webhook_token: compõe a URL do webhook (identifica a configuração).
 * - webhook_secret: segredo cifrado que a Gupshup manda como header em cada
 *   chamada (meta da subscription).
 * Os dois ficam FORA da auditoria ($auditExclude).
 */
class WhatsAppSetting extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;

    /** Header com o segredo do webhook (meta da subscription Gupshup). */
    public const WEBHOOK_SECRET_HEADER = 'X-EasyEye-Webhook-Secret';

    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'entity_id',
        'provider',
        'app_id',
        'webhook_token',
        'webhook_secret',
        'subscription_id',
        'webhook_subscribed_at',
        'active',
        'confirmation_enabled',
        'confirmation_hours_before',
        'survey_enabled',
        'survey_delay_hours',
    ];

    protected $hidden = ['webhook_token', 'webhook_secret'];

    /** @var list<string> segredos nunca vão para audit_logs */
    protected array $auditExclude = ['webhook_token', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'webhook_secret'            => 'encrypted',
            'webhook_subscribed_at'     => 'datetime',
            'active'                    => 'boolean',
            'confirmation_enabled'      => 'boolean',
            'survey_enabled'            => 'boolean',
            'confirmation_hours_before' => 'integer',
            'survey_delay_hours'        => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function optOuts(): HasMany
    {
        return $this->hasMany(WhatsAppOptOut::class, 'whatsapp_setting_id');
    }

    public static function generateWebhookToken(): string
    {
        return Str::random(48);
    }

    public static function generateWebhookSecret(): string
    {
        return Str::random(64);
    }

    /** App Gupshup cadastrado (próprio da clínica ou o global). */
    public function hasApp(): bool
    {
        return filled($this->app_id);
    }

    public function isOperational(): bool
    {
        return $this->active && $this->hasApp();
    }

    // ──────────────────────────────────────────────────────────────────────
    // App GLOBAL do EasyEye (entity_id NULL — singleton via unique parcial)
    // ──────────────────────────────────────────────────────────────────────

    public function isGlobal(): bool
    {
        return $this->entity_id === null;
    }

    /** A linha global do SaaS, se existir. */
    public static function globalSetting(): ?self
    {
        return self::query()->whereNull('entity_id')->first();
    }

    /**
     * Configuração pela qual esta clínica ENVIA: o app próprio, quando
     * cadastrado; senão o app global do EasyEye (se operacional). Null = não
     * há como enviar.
     */
    public function sendingSetting(): ?self
    {
        if ($this->hasApp()) {
            return $this;
        }

        $global = self::globalSetting();

        return $global && $global->isOperational() ? $global : null;
    }

    /**
     * A clínica consegue enviar mensagens? (integração ativa + algum app
     * disponível — próprio ou global).
     */
    public function canSend(): bool
    {
        return $this->active && $this->sendingSetting() !== null;
    }
}
