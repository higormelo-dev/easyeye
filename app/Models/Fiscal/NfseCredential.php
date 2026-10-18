<?php

declare(strict_types=1);

namespace App\Models\Fiscal;

use App\Enums\Fiscal\NfseEnvironment;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Credencial da Focus NFe por ambiente (linha GLOBAL: só a dona do SaaS emite).
 *
 * Os quatro segredos são cifrados em repouso (casts `encrypted`), ficam FORA do
 * JSON ($hidden) e da auditoria ($auditExclude). Nenhum endpoint os devolve: a
 * tela só recebe `has_token` / `has_master_token`.
 *
 *  - token: token da empresa (emite, consulta, cancela, reenvia e-mail, hooks);
 *  - master_token: só as consultas acessórias (GET /v2/municipios*);
 *  - webhook_token: compõe a URL do callback (roteia, não autentica);
 *  - webhook_secret: valor esperado no cabeçalho do callback (autentica).
 */
class NfseCredential extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;

    protected $table = 'nfse_credentials';

    protected $fillable = [
        'environment',
        'token',
        'master_token',
        'webhook_token',
        'webhook_secret',
        'hook_id',
        'hook_event',
        'last_tested_at',
        'last_test_ok',
        'last_test_message',
    ];

    protected $hidden = ['token', 'master_token', 'webhook_token', 'webhook_secret'];

    /** @var list<string> segredos nunca vão para audit_logs */
    protected array $auditExclude = ['token', 'master_token', 'webhook_token', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'environment'    => NfseEnvironment::class,
            'token'          => 'encrypted',
            'master_token'   => 'encrypted',
            'webhook_token'  => 'encrypted',
            'webhook_secret' => 'encrypted',
            'last_tested_at' => 'datetime',
            'last_test_ok'   => 'boolean',
        ];
    }

    public static function generateWebhookToken(): string
    {
        return Str::random(48);
    }

    public static function generateWebhookSecret(): string
    {
        return Str::random(64);
    }

    /**
     * URL pública do callback desta credencial (o que se cadastra na Focus). Por padrão a do app (APP_URL / host da
     * requisição); `fiscal.webhook.public_url` fixa outro endereço público (ex.: túnel) sem mexer no APP_URL.
     */
    public function webhookUrl(): string
    {
        $base = rtrim(trim((string) config('fiscal.webhook.public_url')), '/');

        return $base === ''
            ? route('fiscal.focusnfe.webhook', ['token' => $this->webhook_token])
            : $base . route('fiscal.focusnfe.webhook', ['token' => $this->webhook_token], false);
    }

    public function hasToken(): bool
    {
        return filled($this->token);
    }

    public function hasMasterToken(): bool
    {
        return filled($this->master_token);
    }

    /** Há hook registrado na Focus para este ambiente. */
    public function hasHook(): bool
    {
        return filled($this->hook_id);
    }
}
