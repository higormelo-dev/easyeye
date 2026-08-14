<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferências pessoais do usuário (item MELHORIA "deixar o EasyEye mais
 * humano" — não confundir com `subscription_settings`/`report_settings`,
 * que são por ENTITY/tenant). Uma linha por usuário, bag `data` flexível
 * pra não precisar de migration nova a cada preferência adicionada.
 *
 * Chaves conhecidas hoje (ver DashboardPreferencesController):
 *   - dashboard_widget_order: string[] — ordem das seções do Dashboard
 *   - favorite_shortcuts: {key: string, hidden: bool}[] — ordem/visibilidade
 *     dos atalhos em ModuleShortcuts
 */
class UserPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lê uma chave do bag com fallback — uso comum em props Inertia
     * (`UserPreference::valueFor($user, 'dashboard_widget_order', [])`).
     */
    public static function valueFor(User $user, string $key, mixed $default = null): mixed
    {
        return $user->preference?->data[$key] ?? $default;
    }

    /**
     * Merge parcial no bag (só sobrescreve as chaves enviadas — outras
     * preferências do usuário ficam intactas). Cria a linha se ainda não
     * existir (todo usuário é lazy-provisionado no primeiro PATCH).
     */
    public static function mergeFor(User $user, array $partial): self
    {
        $pref       = static::firstOrNew(['user_id' => $user->id]);
        $pref->data = array_merge($pref->data ?? [], $partial);
        $pref->save();

        return $pref;
    }
}
