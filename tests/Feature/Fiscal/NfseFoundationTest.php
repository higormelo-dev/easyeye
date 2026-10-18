<?php

use App\Enums\Fiscal\{NfseEnvironment, NfseStandard, NfseStatus};
use App\Models\AuditLog;
use App\Models\Fiscal\{NfseCredential, NfseDocument};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Support/NfseFixtures.php';

/**
 * No PostgreSQL um INSERT que viola índice aborta a transação inteira (25P02), e o RefreshDatabase mantém UMA transação
 * em volta do teste: rodar a violação dentro de um savepoint (DB::transaction aninhada) deixa o teste seguir.
 */
function nfseExpectViolation(Closure $callback): void
{
    expect(fn () => DB::transaction($callback))->toThrow(QueryException::class);
}

describe('credenciais', function () {
    it('cifra os quatro segredos em repouso e os devolve ao model', function () {
        $credential = nfseCredential();

        $row = DB::table('nfse_credentials')->where('id', $credential->id)->first();

        foreach (['token', 'master_token', 'webhook_token', 'webhook_secret'] as $column) {
            expect($row->{$column})->not->toBe($credential->{$column})
                ->and($row->{$column})->not->toContain('SEGREDO');
        }

        $fresh = NfseCredential::query()->findOrFail($credential->id);

        expect($fresh->token)->toBe('tok-empresa-homologacao-SEGREDO')
            ->and($fresh->master_token)->toBe('tok-master-homologacao-SEGREDO')
            ->and($fresh->hasToken())->toBeTrue()
            ->and($fresh->hasMasterToken())->toBeTrue()
            ->and($fresh->environment)->toBe(NfseEnvironment::Homologation);
    });

    it('nunca serializa um segredo (toArray / toJson)', function () {
        $credential = nfseCredential();

        foreach ([json_encode($credential->toArray()), $credential->toJson()] as $serialized) {
            expect($serialized)->not->toContain('SEGREDO')
                ->and($serialized)->not->toContain($credential->webhook_token)
                ->and($serialized)->not->toContain($credential->webhook_secret);
        }
    });

    it('não grava segredo na auditoria (criação nem atualização)', function () {
        $credential = nfseCredential();
        $credential->update(['token' => 'tok-novo-SEGREDO-2', 'hook_id' => 'hook-1']);

        $audits = AuditLog::query()->where('auditable_type', NfseCredential::class)->where('auditable_id', $credential->id)->get();

        expect($audits)->toHaveCount(2);

        foreach ($audits as $audit) {
            $dump = json_encode([$audit->old_values, $audit->new_values]);

            expect($dump)->not->toContain('SEGREDO')
                ->and($dump)->not->toContain($credential->webhook_token)
                ->and($dump)->not->toContain($credential->webhook_secret)
                ->and($dump)->not->toContain('"token"')
                ->and($dump)->not->toContain('webhook_secret');
        }

        // O que não é segredo continua auditado.
        expect(json_encode($audits->last()->new_values))->toContain('hook-1');
    });

    it('guarda no máximo uma linha por ambiente', function () {
        nfseCredential(NfseEnvironment::Production);

        nfseCredential(NfseEnvironment::Homologation);

        nfseExpectViolation(fn () => nfseCredential(NfseEnvironment::Production));

        expect(NfseCredential::query()->count())->toBe(2);
    });

    it('gera token e segredo do webhook longos e aleatórios', function () {
        expect(strlen(NfseCredential::generateWebhookToken()))->toBe(48)
            ->and(strlen(NfseCredential::generateWebhookSecret()))->toBe(64)
            ->and(NfseCredential::generateWebhookToken())->not->toBe(NfseCredential::generateWebhookToken());
    });
});

describe('notas', function () {
    it('deriva a ref do id: 32 alfanuméricos maiúsculos, uma vez só', function () {
        $document = nfseDocument(nfsePaidInvoice());

        expect($document->ref)->toMatch('/^[0-9A-F]{32}$/')
            ->and($document->ref)->toBe(strtoupper(str_replace('-', '', $document->id)))
            ->and(NfseDocument::refFor($document->id))->toBe($document->ref);

        $document->update(['status' => NfseStatus::Processing->value]);

        expect($document->fresh()->ref)->toBe($document->ref);
    });

    it('não repete a ref entre notas', function () {
        $invoice = nfsePaidInvoice();

        $first  = nfseDocument($invoice, ['status' => NfseStatus::AuthorizationError->value]);
        $second = nfseDocument($invoice);

        expect($first->ref)->not->toBe($second->ref);

        nfseExpectViolation(fn () => NfseDocument::query()->whereKey($second->id)->update(['ref' => $first->ref]));
    });

    it('converte os campos (enums, decimal, json) e congela o snapshot', function () {
        $invoice  = nfsePaidInvoice();
        $document = nfseDocument($invoice, [
            'request_payload'  => ['servico' => ['valor_servicos' => 199.9]],
            'response_payload' => ['status' => 'processando_autorizacao'],
            'errors'           => [['codigo' => 'E1', 'mensagem' => 'm', 'correcao' => 'c']],
            'dps_series'       => 1,
            'dps_number'       => 7,
        ])->fresh();

        expect($document->status)->toBe(NfseStatus::PendingSend)
            ->and($document->standard)->toBe(NfseStandard::Municipal)
            ->and($document->environment)->toBe(NfseEnvironment::Homologation)
            ->and($document->amount)->toBe('199.90')
            ->and($document->currency)->toBe('BRL')
            ->and($document->payer_snapshot['document'])->toBe('11444777000161')
            ->and($document->request_payload['servico']['valor_servicos'])->toBe(199.9)
            ->and($document->errors[0]['codigo'])->toBe('E1')
            ->and($document->dps_number)->toBe(7)
            ->and($document->cancelled_externally)->toBeFalse()
            ->and($document->cancel_required)->toBeFalse()
            ->and($document->invoice->id)->toBe($invoice->id)
            ->and($document->entity->id)->toBe($invoice->entity_id);
    });

    it('permite só uma nota ocupando a fatura (índice único parcial)', function () {
        $invoice = nfsePaidInvoice();
        $first   = nfseDocument($invoice);

        // Qualquer outra "ocupando" é barrada, qualquer que seja o status dela.
        foreach ([NfseStatus::PendingSend, NfseStatus::Processing, NfseStatus::Authorized] as $status) {
            nfseExpectViolation(fn () => nfseDocument($invoice, ['status' => $status->value]));
        }

        // As que não ocupam convivem com a primeira.
        foreach ([NfseStatus::SendFailed, NfseStatus::AuthorizationError, NfseStatus::Cancelled] as $status) {
            nfseDocument($invoice, ['status' => $status->value]);
        }

        expect(NfseDocument::query()->where('invoice_id', $invoice->id)->count())->toBe(4);

        // Libera a fatura (erro) e a reemissão entra; a antiga, agora livre, não impede.
        $first->update(['status' => NfseStatus::AuthorizationError->value]);
        $reissued = nfseDocument($invoice, ['status' => NfseStatus::Authorized->value]);

        expect($reissued->isAuthorized())->toBeTrue();

        nfseExpectViolation(fn () => $first->update(['status' => NfseStatus::PendingSend->value]));
    });

    it('permite uma nota ocupando por fatura, em faturas diferentes', function () {
        nfseDocument(nfsePaidInvoice());
        nfseDocument(nfsePaidInvoice());

        expect(NfseDocument::query()->count())->toBe(2);
    });

    it('mantém a lista do índice igual à do enum NfseStatus::isOccupying', function () {
        $definition = DB::table('pg_indexes')->where('indexname', 'nfse_documents_invoice_occupying_unique')->value('indexdef');

        expect($definition)->not->toBeNull();

        preg_match_all("/'([a-z_]+)'/", (string) $definition, $matches);

        expect($matches[1])->toEqualCanonicalizing(NfseStatus::occupyingValues())
            ->and(NfseStatus::occupyingValues())->toEqualCanonicalizing(['pending_send', 'processando_autorizacao', 'autorizado']);
    });

    it('barra a mesma série/número de DPS no mesmo ambiente, mas não notas sem DPS', function () {
        $dps = ['dps_series' => 1, 'dps_number' => 10, 'standard' => NfseStandard::National->value];

        nfseDocument(nfsePaidInvoice(), $dps);

        nfseExpectViolation(fn () => nfseDocument(nfsePaidInvoice(), $dps));

        // Outro ambiente, outra série e notas municipais (sem número) convivem.
        nfseDocument(nfsePaidInvoice(), $dps + ['environment' => NfseEnvironment::Production->value]);
        nfseDocument(nfsePaidInvoice(), ['dps_series' => 2, 'dps_number' => 10, 'standard' => NfseStandard::National->value]);
        nfseDocument(nfsePaidInvoice());
        nfseDocument(nfsePaidInvoice());

        expect(NfseDocument::query()->count())->toBe(5);
    });

    it('audita a mudança de status mas nunca o corpo da nota', function () {
        $document = nfseDocument(nfsePaidInvoice(), ['request_payload' => ['tomador' => ['razao_social' => 'PII DA CLINICA']]]);

        $document->update([
            'status'           => NfseStatus::Authorized->value,
            'numero'           => '123',
            'response_payload' => ['codigo_verificacao' => 'ABC'],
            'errors'           => [['mensagem' => 'segredo-no-erro']],
        ]);

        $audits = AuditLog::query()->where('auditable_type', NfseDocument::class)->where('auditable_id', $document->id)->orderBy('created_at')->get();

        expect($audits)->toHaveCount(2);

        foreach ($audits as $audit) {
            $dump = json_encode([$audit->old_values, $audit->new_values]);

            expect($dump)->not->toContain('PII DA CLINICA')
                ->and($dump)->not->toContain('request_payload')
                ->and($dump)->not->toContain('payer_snapshot')
                ->and($dump)->not->toContain('segredo-no-erro');
        }

        expect($audits->last()->new_values)->toMatchArray(['status' => 'autorizado', 'numero' => '123']);
    });

    it('não deixa apagar a fatura nem a clínica que têm nota (guarda fiscal)', function () {
        $invoice = nfsePaidInvoice();
        nfseDocument($invoice);

        nfseExpectViolation(fn () => $invoice->forceDelete());
        nfseExpectViolation(fn () => $invoice->entity->forceDelete());

        expect(NfseDocument::query()->count())->toBe(1);
    });
});

describe('IBGE da clínica', function () {
    it('guarda só os 7 dígitos do código IBGE', function () {
        $clinic = nfseClinic(['ibge_code' => '3550-308']);

        expect($clinic->fresh()->ibge_code)->toBe('3550308');
    });

    it('aceita clínica sem IBGE (preenchido depois, no Manager)', function () {
        expect(nfseClinic(['ibge_code' => null])->fresh()->ibge_code)->toBeNull();
    });
});

describe('textos dos enums', function () {
    it('rotula status, padrão e ambiente pelo arquivo fiscal.php', function () {
        foreach (NfseStatus::cases() as $status) {
            expect($status->label())->toBe(__("fiscal.status.{$status->value}"))
                ->and($status->badge())->toStartWith('badge-soft-');
        }

        foreach (NfseStandard::cases() as $standard) {
            expect($standard->label())->toBe(__("fiscal.standard.{$standard->value}"));
        }

        foreach (NfseEnvironment::cases() as $environment) {
            expect($environment->label())->toBe(__("fiscal.environment.{$environment->value}"));
        }
    });

    it('resolve o host da Focus pela configuração, sem env', function () {
        expect(NfseEnvironment::Production->baseUrl())->toBe('https://api.focusnfe.com.br')
            ->and(NfseEnvironment::Homologation->baseUrl())->toBe('https://homologacao.focusnfe.com.br')
            ->and(NfseStandard::Municipal->endpoint())->toBe('nfse')
            ->and(NfseStandard::National->endpoint())->toBe('nfsen')
            ->and(NfseStandard::National->hookEvent())->toBe('nfsen');
    });
});
