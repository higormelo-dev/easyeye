<?php

use App\Domains\AI\Support\AiLegacyCreditConverter;
use Illuminate\Database\Migrations\Migration;

/**
 * Converte os saldos, o ledger, as compras e as execuções de IA de "créditos" para CENTAVOS de BRL (AiLegacyCreditConverter).
 * Os fatores vêm de config/ai.php `legacy_conversion` (AI_LEGACY_DEFAULT_CREDIT_CENTS = 200, AI_LEGACY_QUOTA_CREDIT_CENTS = 100):
 * o dono os confirma ANTES do deploy. Roda em transação; sem backup + janela de manutenção, não rode (plano §2.3). Reexecutar
 * não converte de novo (carteira com `legacy_conversion` é pulada); `php artisan ai:convert-legacy-credits --verify` concilia.
 *
 * Não há rollback de dados: o rollback seguro é restaurar o backup junto com o código anterior.
 */
return new class() extends Migration {
    public function up(): void
    {
        AiLegacyCreditConverter::fromConfig()->convertAll();
    }

    public function down(): void
    {
        // Sem volta: as colunas antigas continuam intactas, mas o código antigo não lê as novas. Restaurar o backup.
    }
};
