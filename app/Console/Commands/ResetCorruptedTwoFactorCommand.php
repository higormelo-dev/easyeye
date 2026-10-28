<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Limpa registros de 2FA cujo secret/recovery está corrompido
 * (geralmente por troca de APP_KEY entre ambientes).
 *
 * Uso:
 *   php artisan two-factor:reset-corrupted           # dry-run, lista o que faria
 *   php artisan two-factor:reset-corrupted --force   # aplica
 *   php artisan two-factor:reset-corrupted --email=foo@bar.com
 *
 * Comportamento:
 *  - Tenta decifrar two_factor_secret de cada usuário.
 *  - Em caso de DecryptException, NULL nos campos two_factor_*.
 *  - O usuário precisará refazer setup no próximo acesso (não é punição,
 *    é a única forma — o secret está inutilizável de qualquer forma).
 */
class ResetCorruptedTwoFactorCommand extends Command
{
    protected $signature = 'two-factor:reset-corrupted
                            {--force : aplica as alterações (sem isso é dry-run)}
                            {--email= : limita a um e-mail específico}';

    protected $description = 'Limpa 2FA de usuários cujos secrets/recovery codes não decifram (APP_KEY mudou).';

    public function handle(): int
    {
        $query = User::query()
            ->whereNotNull('two_factor_secret');

        if ($email = $this->option('email')) {
            $query->where('email', $email);
        }

        $force      = (bool) $this->option('force');
        $candidates = $query->get(['id', 'email', 'two_factor_secret', 'two_factor_recovery_codes']);

        if ($candidates->isEmpty()) {
            $this->info('Nenhum usuário com two_factor_secret preenchido.');

            return self::SUCCESS;
        }

        $this->info("Checando {$candidates->count()} usuário(s)...");

        $corrupted = [];

        foreach ($candidates as $user) {
            $isCorrupted = false;

            try {
                $secret = $user->two_factor_secret;  // dispara cast 'encrypted'

                if ($secret === null) {
                    continue;
                }
            } catch (DecryptException) {
                $isCorrupted = true;
            }

            // Recovery codes também — pode estar OK mesmo com secret corrompido
            // ou vice-versa.
            if (! $isCorrupted) {
                try {
                    $user->two_factor_recovery_codes;
                } catch (DecryptException) {
                    $isCorrupted = true;
                }
            }

            if ($isCorrupted) {
                $corrupted[] = $user;
                $this->line("  • CORROMPIDO: {$user->email} ({$user->id})");
            }
        }

        if (empty($corrupted)) {
            $this->info('Nenhum registro corrompido. Nada a fazer.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(sprintf('%d registro(s) corrompido(s) encontrado(s).', count($corrupted)));

        if (! $force) {
            $this->comment('Dry-run. Rode novamente com --force para limpar.');

            return self::SUCCESS;
        }

        // --force PULA o prompt de confirmação (convenção Laravel).
        // O operador já confirmou o intent ao passar --force.

        foreach ($corrupted as $user) {
            // Update direto via query — evita o setter passar pelo cast
            // 'encrypted' e re-criptografar valores que nem foram lidos.
            User::query()->where('id', $user->id)->update([
                'two_factor_secret'         => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at'   => null,
            ]);
            $this->line("  ✓ Limpo: {$user->email}");
        }

        $this->info(sprintf('Concluído. %d registro(s) limpo(s).', count($corrupted)));

        return self::SUCCESS;
    }
}
