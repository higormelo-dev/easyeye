<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use App\Domains\Tiss\Services\Credentials\TissCredentialConnectionTester;
use App\Models\Scopes\EntityScope;
use Illuminate\Console\Command;

/**
 * Manutenção técnica das credenciais TISS (complemento de Financeiro > Credenciais TISS): lista o que cada clínica
 * tem configurado e o que falta para o envio real, sem nenhum segredo; `--test` faz o mesmo teste de conexão da tela
 * (auditado). Cadastro e troca de segredo ficam só na tela: no terminal a senha iria para o histórico do shell.
 */
class TissCredentialsCommand extends Command
{
    protected $signature = 'tiss:credentials
        {--entity= : Id da clínica (sem ele, todas)}
        {--test= : Id da credencial para testar a conexão (consulta simples, sem lote nem dado de paciente)}';

    protected $description = 'Lista as credenciais TISS sem segredos (o que falta para o envio real) e testa a conexão de uma';

    public function handle(TissCredentialConnectionTester $tester): int
    {
        $query = TissEntityOperatorCredential::query()->withoutGlobalScope(EntityScope::class)->with(['operator', 'entity:id,name']);

        if (filled($this->option('test'))) {
            $credential = $query->find((string) $this->option('test'));

            if (! $credential) {
                $this->error('Credencial não encontrada.');

                return self::FAILURE;
            }

            $test = $tester->test($credential, request());
            $ok   = in_array($test['result'], ['reachable', 'responded'], true);

            $ok ? $this->info(TissCredentialConnectionTester::describe($test)) : $this->error(TissCredentialConnectionTester::describe($test));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf(
            'Transporte: %s · ambiente do envio: %s',
            (string) (config('tiss.transport.driver') ?: '—'),
            (string) config('tiss.transport.environment', TissEntityOperatorCredential::ENV_PRODUCTION),
        ));

        $credentials = $query
            ->when(filled($this->option('entity')), fn ($q) => $q->where('entity_id', (string) $this->option('entity')))
            ->orderBy('entity_id')
            ->orderBy('operator_id')
            ->orderBy('environment')
            ->get();

        if ($credentials->isEmpty()) {
            $this->info('Nenhuma credencial TISS cadastrada.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Clínica', 'Operadora (ANS)', 'Ambiente', 'Endereço', 'Autenticação', 'Situação', 'Último teste'],
            $credentials->map(fn (TissEntityOperatorCredential $c): array => [
                $c->id,
                $c->entity?->name ?? $c->entity_id,
                sprintf('%s (%s)', $c->operator?->trade_name ?: $c->operator?->name ?: '—', $c->operator?->ans_code ?? '—'),
                $c->environment,
                parse_url((string) $c->submissionEndpoint(), PHP_URL_HOST) ?: '—',
                implode(', ', array_keys(array_filter([
                    'usuário+senha' => filled($c->username) && filled($c->password_encrypted),
                    'token'         => filled($c->token_encrypted),
                    'certificado'   => filled($c->certificate_path),
                ]))) ?: 'nenhuma (confirme se a operadora libera por IP)',
                ! $c->active ? 'inativa' : ($c->missingForSubmission() === [] ? 'pronta' : 'falta: ' . implode(', ', $c->missingForSubmission())),
                isset($c->metadata['connection_test']['result']) ? $c->metadata['connection_test']['result'] . ' em ' . ($c->metadata['connection_test']['at'] ?? '—') : '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
