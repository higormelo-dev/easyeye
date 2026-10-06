<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\{EntityUserIntegrator, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Senhas dos seeders (Clínica Teste Integrador, admins do SaaS) — decisão do
 * dono: senha fixa do repositório SÓ em ambiente local e nos testes
 * automatizados; homologação (que roda APP_ENV=testing) e produção recebem
 * senha aleatória, mostrada uma vez no terminal.
 *
 * Modo fixo = APP_ENV=local OU SEED_FIXED_CREDENTIALS=true (config
 * app.seed_fixed_credentials; o phpunit.xml liga). Em produção, nunca — nem
 * com a chave.
 */
final class SeedCredentials
{
    /** Tamanho da senha gerada (só letras e números: nada some no terminal). */
    public const LENGTH = 24;

    public static function fixed(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return app()->environment('local') || (bool) config('app.seed_fixed_credentials', false);
    }

    /**
     * Senha forte e "imprimível": sem símbolos — o OutputFormatter do console
     * trata "<...>" e "\" como marcação e engoliria caracteres (a senha
     * mostrada não seria a que funciona). 24 caracteres de [A-Za-z0-9] ≈
     * 142 bits.
     */
    public static function generate(): string
    {
        return Str::password(self::LENGTH, symbols: false);
    }

    /**
     * Derruba o acesso já aberto de quem teve a senha trocada pelo seeder:
     * remember_token novo, sessões no banco (driver database) e tokens de
     * API (Sanctum — integrador).
     */
    public static function invalidateSessions(User|EntityUserIntegrator $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        if ($user instanceof User && config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }
}
