<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Configuração Z-API (WhatsApp) por clínica — confirmação de consulta +
 * pesquisa de satisfação.
 *
 * `credentials` = {instance_id, instance_token, client_token}, criptografado
 * at rest via cast encrypted:array (padrão GatewayCredential). O instance_id
 * é DUPLICADO em coluna própria em claro para o webhook indexar — não é
 * segredo; os tokens são e nunca saem do cast.
 */
class WhatsAppSetting extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;

    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'entity_id',
        'credentials',
        'instance_id',
        'webhook_token',
        'active',
        'confirmation_enabled',
        'confirmation_hours_before',
        'survey_enabled',
        'survey_delay_hours',
    ];

    protected function casts(): array
    {
        return [
            'credentials'               => 'encrypted:array',
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

    public static function generateWebhookToken(): string
    {
        return Str::random(48);
    }

    public function hasCredentials(): bool
    {
        $c = $this->credentials ?? [];

        return ! empty($c['instance_id']) && ! empty($c['instance_token']) && ! empty($c['client_token']);
    }

    public function isOperational(): bool
    {
        return $this->active && $this->hasCredentials();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Instância GLOBAL do SaaS (entity_id NULL — singleton via unique parcial)
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
     * Setting cujas CREDENCIAIS devem ser usadas para enviar por esta clínica:
     * as próprias quando plugou número próprio; senão a instância global do
     * SaaS (se operacional). Null = não há como enviar.
     */
    public function sendingCredentials(): ?self
    {
        if ($this->hasCredentials()) {
            return $this;
        }

        $global = self::globalSetting();

        return $global && $global->isOperational() ? $global : null;
    }

    /**
     * A clínica consegue enviar mensagens? (toggles próprios ativos + alguma
     * credencial disponível — própria ou global).
     */
    public function canSend(): bool
    {
        return $this->active && $this->sendingCredentials() !== null;
    }
}
