<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\AI\Support\AiLegacyCreditConverter;
use Illuminate\Console\Command;

/**
 * Converte (ou confere) os saldos de IA de "créditos" para centavos de BRL. A migration 2026_10_23_100200 já faz a conversão no
 * deploy com os fatores de config/ai.php; este comando serve para ENSAIAR (`--dry-run`, nada é gravado) e CONFERIR (`--verify`)
 * com fatores diferentes, antes de o dono decidir. Idempotente: carteira já convertida é pulada.
 */
class ConvertLegacyAiCreditsCommand extends Command
{
    protected $signature = 'ai:convert-legacy-credits
        {--dry-run : Mostra o que seria convertido e desfaz tudo}
        {--verify : Só confere a conciliação (sem converter)}
        {--default-credit-cents= : Centavos por crédito comprado quando a clínica não tem compra paga (padrão: config)}
        {--quota-credit-cents= : Centavos por crédito da cota mensal (padrão: config)}';

    protected $description = 'Converte os saldos de IA de créditos para centavos de BRL (ensaio, conciliação e execução)';

    public function handle(): int
    {
        $converter = AiLegacyCreditConverter::fromConfig(
            $this->option('default-credit-cents') !== null ? (int) $this->option('default-credit-cents') : null,
            $this->option('quota-credit-cents') !== null ? (int) $this->option('quota-credit-cents') : null,
        );

        if ($this->option('verify')) {
            $issues = $converter->reconcile();

            foreach ($issues as $issue) {
                $this->warn($issue);
            }

            $issues === [] ? $this->info('Conciliado: nenhuma divergência.') : $this->error(count($issues) . ' divergência(s).');

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        }

        $report = $converter->convertAll((bool) $this->option('dry-run'));

        $this->table(['carteiras', 'ledger', 'compras', 'execuções', 'chamadas', 'franquias de plano', 'ensaio'], [[
            $report['wallets'], $report['ledger_entries'], $report['purchases'], $report['runs'], $report['provider_calls'], $report['plan_features'], $report['dry_run'] ? 'sim (desfeito)' : 'não',
        ]]);

        return self::SUCCESS;
    }
}
