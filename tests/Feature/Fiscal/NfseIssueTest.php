<?php

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Fiscal\{NfseEnvironment, NfseStandard, NfseStatus};
use App\Events\Billing\InvoicePaid;
use App\Exceptions\Fiscal\NfseIssueRejectedException as Rejected;
use App\Jobs\Fiscal\SendNfseJob;
use App\Listeners\Fiscal\IssueNfseOnInvoicePaid;
use App\Models\Billing\{BillingLog, Invoice};
use App\Models\Fiscal\{NfseCredential, NfseDocument};
use App\Models\User;
use App\Services\Billing\CorrelationIdService;
use App\Services\Fiscal\{NfseCredentialResolver, NfseIssueService, NfseMunicipalityService};
use App\Support\Fiscal\NfseSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\{DB, Event, Exceptions, Http, Queue};

require_once __DIR__ . '/Support/NfseFixtures.php';

beforeEach(function () {
    Queue::fake([SendNfseJob::class]);
    Http::preventStrayRequests();
    $this->issuer = app(NfseIssueService::class);
});

function nfsePay(): InvoicePaid
{
    return InvoicePaid::fromInvoice(nfsePaidInvoice());
}

function nfseReject(Closure $callback, string $reason): Rejected
{
    try {
        $callback();
    } catch (Rejected $e) {
        expect($e->reason)->toBe($reason);

        return $e;
    }

    test()->fail("esperava recusa por {$reason}");
}

describe('emissão', function () {
    it('cria a nota pending_send com valor, tomador e destino congelados e enfileira o envio', function () {
        nfseReady();

        $admin   = User::factory()->create();
        $invoice = nfsePaidInvoice();

        $document = $this->issuer->issue($invoice, 'manual', $admin);

        expect($document->wasRecentlyCreated)->toBeTrue()
            ->and($document->status)->toBe(NfseStatus::PendingSend)
            ->and($document->standard)->toBe(NfseStandard::Municipal)
            ->and($document->environment)->toBe(NfseEnvironment::Homologation)
            ->and($document->amount)->toBe('199.90')
            ->and($document->currency)->toBe('BRL')
            ->and($document->invoice_id)->toBe($invoice->id)
            ->and($document->entity_id)->toBe($invoice->entity_id)
            ->and($document->source)->toBe('manual')
            ->and($document->created_by)->toBe($admin->id)
            ->and($document->ref)->toMatch('/^[0-9A-F]{32}$/')
            ->and($document->correlation_id)->not->toBeEmpty()
            ->and($document->dps_series)->toBeNull()
            ->and($document->dps_number)->toBeNull()
            ->and($document->payer_snapshot)->toMatchArray([
                'name'          => 'CLINICA OFTALMO TESTE',
                'document'      => $invoice->entity->national_registration,
                'document_type' => 'cnpj',
            ]);

        Queue::assertPushed(SendNfseJob::class, 1);
        Queue::assertPushed(SendNfseJob::class, fn (SendNfseJob $job) => $job->documentId === $document->id && $job->queue === 'default');

        // Criar a nota nunca fala com a Focus: o envio é do job.
        Http::assertNothingSent();
    });

    it('grava o ambiente e o padrão da configuração NA nota (mudar depois não troca o destino)', function () {
        nfseReady(['standard' => 'nacional', 'environment' => 'producao'], NfseEnvironment::Production);

        $document = $this->issuer->issue(nfsePaidInvoice(), 'auto');

        expect($document->standard)->toBe(NfseStandard::National)
            ->and($document->environment)->toBe(NfseEnvironment::Production);

        (new NfseSettings())->save(['standard' => 'municipal', 'environment' => 'homologacao']);

        expect($document->fresh()->standard)->toBe(NfseStandard::National)
            ->and($document->fresh()->environment)->toBe(NfseEnvironment::Production);
    });

    it('congela o valor e o tomador: mudar o cadastro ou a fatura depois não altera a nota', function () {
        nfseReady();

        $invoice  = nfsePaidInvoice();
        $document = $this->issuer->issue($invoice, 'auto');

        $invoice->entity->update(['name' => 'OUTRO NOME', 'address' => 'Rua Nova']);
        $invoice->update(['amount' => 1.00, 'refunded_amount' => 50.00]);

        $fresh = $document->fresh();

        expect($fresh->amount)->toBe('199.90')
            ->and($fresh->payer_snapshot['name'])->toBe('CLINICA OFTALMO TESTE')
            ->and($fresh->payer_snapshot['address']['logradouro'])->toBe('AVENIDA PAULISTA');
    });

    it('trava a fatura (FOR UPDATE) na criação da nota', function () {
        nfseReady();

        $invoice = nfsePaidInvoice();

        DB::enableQueryLog();
        $this->issuer->issue($invoice, 'auto');
        $log = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        expect($log)->toMatch('/from "invoices" where "invoices"."id" = \? .*for update/is');
    });

    it('a nota de pacote de IA nasce igual à de assinatura', function () {
        nfseReady();

        $invoice = nfsePaidInvoice(null, ['billing_reason' => 'ai_credit_pack', 'subscription_id' => null, 'plan_id' => null, 'amount' => 49.90]);

        expect($this->issuer->issue($invoice, 'auto'))->amount->toBe('49.90');
    });

    it('padrão nacional: reserva série e número da DPS em sequência (um por nota)', function () {
        nfseReady(['standard' => 'nacional', 'prestador' => ['serie_dps' => 3, 'proximo_numero_dps' => 40]]);

        $first  = $this->issuer->issue(nfsePaidInvoice(), 'auto');
        $second = $this->issuer->issue(nfsePaidInvoice(), 'auto');

        expect([$first->dps_series, $first->dps_number])->toBe([3, 40])
            ->and([$second->dps_series, $second->dps_number])->toBe([3, 41])
            ->and((new NfseSettings())->get(NfseSettings::COUNTER_KEY))->toBe(42);
    });

    it('padrão nacional: guarda barrada não consome número', function () {
        nfseReady(['standard' => 'nacional', 'prestador' => ['proximo_numero_dps' => 10]]);

        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(null, ['amount' => 0]), 'auto'), Rejected::ZERO_AMOUNT);

        expect($this->issuer->issue(nfsePaidInvoice(), 'auto')->dps_number)->toBe(10);
    });

    it('o município é só consultado no cache: emitir nunca chama a Focus', function () {
        nfseReady();
        nfseCacheMunicipality();

        $this->issuer->issue(nfsePaidInvoice(), 'auto');

        Http::assertSentCount(1); // só a consulta do cache feita pelo fixture
    });
});

describe('fatura que já tem nota', function () {
    it('emissão automática e em lote devolvem a nota existente, sem criar outra nem enfileirar de novo', function () {
        nfseReady();

        $invoice = nfsePaidInvoice();
        $first   = $this->issuer->issue($invoice, 'auto');

        foreach (['auto', 'batch'] as $source) {
            $again = $this->issuer->issue($invoice, $source);

            expect($again->id)->toBe($first->id)->and($again->wasRecentlyCreated)->toBeFalse();
        }

        expect(NfseDocument::query()->count())->toBe(1);
        Queue::assertPushed(SendNfseJob::class, 1);
    });

    it('emissão manual recusa quando a fatura já tem nota a caminho ou autorizada', function () {
        nfseReady();

        foreach ([NfseStatus::PendingSend, NfseStatus::Processing, NfseStatus::Authorized] as $status) {
            $invoice = nfsePaidInvoice();
            nfseDocument($invoice, ['status' => $status->value]);

            nfseReject(fn () => $this->issuer->issue($invoice, 'manual'), Rejected::DOCUMENT_EXISTS);
        }
    });

    it('depois de erro, falha de envio ou cancelamento a reemissão cria uma NOVA linha com nova ref', function () {
        nfseReady();

        foreach ([NfseStatus::AuthorizationError, NfseStatus::SendFailed, NfseStatus::Cancelled] as $status) {
            $invoice = nfsePaidInvoice();
            $old     = nfseDocument($invoice, ['status' => $status->value]);

            $new = $this->issuer->issue($invoice, 'manual');

            expect($new->id)->not->toBe($old->id)
                ->and($new->ref)->not->toBe($old->ref)
                ->and($new->status)->toBe(NfseStatus::PendingSend)
                ->and(NfseDocument::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
        }
    });

    it('nota cancelada: automático e lote NÃO reemitem; a emissão manual do Manager pode', function () {
        nfseReady();

        $invoice   = nfsePaidInvoice();
        $cancelled = nfseDocument($invoice, ['status' => NfseStatus::Cancelled->value]);

        foreach (['auto', 'batch'] as $source) {
            $again = $this->issuer->issue($invoice, $source);

            expect($again->id)->toBe($cancelled->id)->and($again->wasRecentlyCreated)->toBeFalse();
        }

        expect(NfseDocument::query()->count())->toBe(1);
        Queue::assertNothingPushed();

        $manual = $this->issuer->issue($invoice, 'manual');

        expect($manual->id)->not->toBe($cancelled->id)
            ->and($manual->status)->toBe(NfseStatus::PendingSend)
            ->and($manual->source)->toBe('manual');

        Queue::assertPushed(SendNfseJob::class, 1);
    });

    it('o evento de pagamento repetido numa fatura de nota cancelada não emite de novo', function () {
        nfseReady(['auto_issue' => true]);

        $invoice = nfsePaidInvoice();
        nfseDocument($invoice, ['status' => NfseStatus::Cancelled->value]);

        event(InvoicePaid::fromInvoice($invoice));

        expect(NfseDocument::query()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('a corrida que escapa da trava vira a linha vencedora (índice único parcial como backstop)', function () {
        nfseReady();

        $invoice = nfsePaidInvoice();
        $winner  = nfseDocument($invoice);

        // O INSERT perde para a nota que outra conexão acabou de gravar: simulado no ponto exato do INSERT.
        $racing = new class(app(NfseSettings::class), app(NfseCredentialResolver::class), app(NfseMunicipalityService::class), app(CorrelationIdService::class)) extends NfseIssueService {
            protected function create(Invoice $invoice, string $source, ?User $by): NfseDocument
            {
                throw new UniqueConstraintViolationException('pgsql', 'insert into nfse_documents', [], new PDOException('duplicate key'));
            }
        };

        expect($racing->issue($invoice, 'auto')->id)->toBe($winner->id);
        nfseReject(fn () => $racing->issue($invoice, 'manual'), Rejected::DOCUMENT_EXISTS);

        Queue::assertNothingPushed();
    });
});

describe('guardas', function () {
    beforeEach(fn () => nfseReady());

    it('só fatura paga gera nota', function (string $status) {
        $invoice = nfsePaidInvoice(null, ['status' => $status]);

        nfseReject(fn () => $this->issuer->issue($invoice, 'manual'), Rejected::INVOICE_NOT_PAID);

        expect(NfseDocument::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        InvoiceStatus::Draft->value, InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value,
        InvoiceStatus::Cancelled->value, InvoiceStatus::Failed->value,
    ]);

    it('fatura estornada não gera nota (status refunded ou valor todo devolvido)', function () {
        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(null, ['status' => InvoiceStatus::Refunded->value, 'refunded_amount' => 199.90]), 'auto'), Rejected::INVOICE_REFUNDED);
        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(null, ['refunded_amount' => 199.90]), 'auto'), Rejected::INVOICE_REFUNDED);
    });

    it('estorno parcial não impede a nota (valor é o pago)', function () {
        $document = $this->issuer->issue(nfsePaidInvoice(null, ['refunded_amount' => 50.00]), 'manual');

        expect($document->amount)->toBe('199.90');
    });

    it('registro de pagamento não aplicado nunca é receita', function () {
        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(null, ['billing_reason' => 'unapplied_payment']), 'manual'), Rejected::INVOICE_NOT_PAID);
    });

    it('valor zero não gera nota', function () {
        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(null, ['amount' => 0]), 'manual'), Rejected::ZERO_AMOUNT);
    });

    it('tomador sem CPF/CNPJ válido não gera nota', function (?string $document) {
        $clinic = nfseClinic(['national_registration' => $document]);

        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice($clinic), 'manual'), Rejected::INVALID_DOCUMENT);
    })->with([null, '', '11222333000182', '12345678901', '00000000000000']);

    it('prestador incompleto lista o que falta', function () {
        (new NfseSettings())->save(['prestador' => ['cnpj' => '', 'inscricao_municipal' => '']]);

        $e = nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(), 'manual'), Rejected::ISSUER_INCOMPLETE);

        expect($e->context['fields'])->toBe(['prestador.cnpj', 'prestador.inscricao_municipal']);
    });

    it('no padrão nacional exige o código de tributação nacional, não a inscrição municipal', function () {
        (new NfseSettings())->save(['standard' => 'nacional', 'prestador' => ['inscricao_municipal' => '', 'codigo_tributacao_nacional_iss' => '']]);

        $e = nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(), 'manual'), Rejected::ISSUER_INCOMPLETE);

        expect($e->context['fields'])->toBe(['prestador.codigo_tributacao_nacional_iss']);
    });

    it('sem token do ambiente ativo não gera nota', function () {
        (new NfseSettings())->save(['environment' => 'producao']); // só há credencial de homologação

        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(), 'manual'), Rejected::CREDENTIAL_MISSING);
    });

    it('credencial só com token master não emite (o token da empresa é que emite)', function () {
        NfseCredential::query()->update(['token' => null]);

        nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(), 'manual'), Rejected::CREDENTIAL_MISSING);
    });

    it('município que exige CNAE e código tributário barra prestador sem eles', function () {
        (new NfseSettings())->save(['prestador' => ['cnae' => '', 'codigo_tributario_municipio' => '']]);
        nfseCacheMunicipality(['codigo_cnae_obrigatorio_nfse' => true, 'codigo_tributario_municipio_obrigatorio_nfse' => true]);

        $e = nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(), 'manual'), Rejected::ISSUER_INCOMPLETE);

        expect($e->context['fields'])->toBe(['prestador.cnae', 'prestador.codigo_tributario_municipio']);
    });

    it('município que NÃO exige CNAE deixa passar prestador sem ele', function () {
        (new NfseSettings())->save(['prestador' => ['cnae' => '']]);
        nfseCacheMunicipality(['codigo_cnae_obrigatorio_nfse' => false]);

        expect($this->issuer->issue(nfsePaidInvoice(), 'manual'))->status->toBe(NfseStatus::PendingSend);
    });

    describe('endereço do tomador', function () {
        it('município que exige endereço barra clínica sem endereço completo, dizendo o que falta', function () {
            nfseCacheMunicipality(['endereco_obrigatorio_nfse' => true]);

            $clinic = nfseClinic(['address' => '', 'zipcode' => null]);

            $e = nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice($clinic), 'manual'), Rejected::ADDRESS_REQUIRED);

            expect($e->context['fields'])->toBe(['logradouro', 'cep']);
        });

        it('quando só falta o IBGE da clínica o motivo é ibge_ausente', function () {
            nfseCacheMunicipality(['endereco_obrigatorio_nfse' => true]);

            nfseReject(fn () => $this->issuer->issue(nfsePaidInvoice(nfseClinic(['ibge_code' => null])), 'manual'), Rejected::IBGE_MISSING);
        });

        it('município que não exige endereço: emite SEM endereço, sem travar o faturamento', function () {
            nfseCacheMunicipality(['endereco_obrigatorio_nfse' => false]);

            $document = $this->issuer->issue(nfsePaidInvoice(nfseClinic(['ibge_code' => null, 'address' => ''])), 'auto');

            expect($document->status)->toBe(NfseStatus::PendingSend)
                ->and($document->payer_snapshot)->not->toHaveKey('address');
        });

        it('exigência indefinida (null) ou município nunca consultado: emite e deixa a Focus validar', function () {
            nfseCacheMunicipality(['endereco_obrigatorio_nfse' => null]);

            expect($this->issuer->issue(nfsePaidInvoice(nfseClinic(['ibge_code' => null])), 'auto'))->wasRecentlyCreated->toBeTrue();

            Cache::flush();

            expect($this->issuer->issue(nfsePaidInvoice(nfseClinic(['ibge_code' => null])), 'auto'))->wasRecentlyCreated->toBeTrue();
        });

        it('endereço completo vai no snapshot mesmo quando o município não o exige', function () {
            nfseCacheMunicipality(['endereco_obrigatorio_nfse' => false]);

            expect($this->issuer->issue(nfsePaidInvoice(), 'auto')->payer_snapshot['address']['codigo_municipio'])->toBe('3550308');
        });
    });

    it('clínica apagada (soft delete) com fatura paga ainda recebe nota: o tomador é o cadastro congelado', function () {
        $clinic  = nfseClinic();
        $invoice = nfsePaidInvoice($clinic);
        $clinic->delete();

        expect($this->issuer->issue($invoice, 'manual')->payer_snapshot['name'])->toBe('CLINICA OFTALMO TESTE');
    });
});

describe('emissão automática (InvoicePaid)', function () {
    it('com a emissão automática ligada, a fatura paga gera a nota e o envio', function () {
        nfseReady(['auto_issue' => true]);

        $event = nfsePay();

        event($event);

        $document = NfseDocument::query()->sole();

        expect($document->invoice_id)->toBe($event->invoiceId)
            ->and($document->source)->toBe('auto')
            ->and($document->status)->toBe(NfseStatus::PendingSend);

        Queue::assertPushed(SendNfseJob::class, 1);
    });

    it('com a emissão automática desligada (padrão) não faz nada', function () {
        nfseReady(['auto_issue' => false]);

        event(nfsePay());

        expect(NfseDocument::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('o mesmo evento entregue duas vezes não duplica a nota', function () {
        nfseReady(['auto_issue' => true]);

        $event = nfsePay();

        event($event);
        event($event);

        expect(NfseDocument::query()->count())->toBe(1);
        Queue::assertPushed(SendNfseJob::class, 1);
    });

    it('cobre pacote de créditos de IA e troca de plano (todos disparam InvoicePaid)', function () {
        nfseReady(['auto_issue' => true]);

        foreach (['ai_credit_pack', 'plan_change'] as $reason) {
            event(InvoicePaid::fromInvoice(nfsePaidInvoice(null, ['billing_reason' => $reason])));
        }

        expect(NfseDocument::query()->count())->toBe(2);
    });

    it('guarda que barra vira aviso no log de cobrança, sem erro nem nota', function () {
        nfseReady(['auto_issue' => true]);

        $event = InvoicePaid::fromInvoice(nfsePaidInvoice(nfseClinic(['national_registration' => '11222333000182'])));

        event($event);

        expect(NfseDocument::query()->count())->toBe(0);

        $log = BillingLog::query()->where('invoice_id', $event->invoiceId)->sole();

        expect($log->level)->toBe('warning')
            ->and($log->context['reason'])->toBe('documento_invalido')
            ->and($log->message)->toContain('NFS-e não emitida automaticamente');
    });

    it('prestador incompleto também vira aviso, não exceção', function () {
        nfseReady(['auto_issue' => true, 'prestador' => ['cnpj' => '']]);

        event(nfsePay());

        expect(NfseDocument::query()->count())->toBe(0)
            ->and(BillingLog::query()->sole()->context['fields'])->toContain('prestador.cnpj');
    });

    it('falha inesperada é reportada e NÃO derruba o fluxo de pagamento', function () {
        nfseReady(['auto_issue' => true]);

        $this->mock(NfseIssueService::class, fn ($mock) => $mock->shouldReceive('issue')->andThrow(new RuntimeException('banco fora')));

        Exceptions::fake();

        event(nfsePay()); // não pode lançar

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'banco fora');
    });

    it('ignora fatura da entidade do próprio SaaS (nunca é tomadora)', function () {
        nfseReady(['auto_issue' => true]);

        event(InvoicePaid::fromInvoice(nfsePaidInvoice(nfseClinic(['is_client' => false]))));

        expect(NfseDocument::query()->count())->toBe(0);
    });

    it('o listener roda na fila configurada e depois do commit', function () {
        config(['fiscal.queue' => 'fiscal']);

        $listener = new IssueNfseOnInvoicePaid();

        expect($listener->viaQueue())->toBe('fiscal')
            ->and($listener->afterCommit)->toBeTrue()
            ->and(Event::getListeners(InvoicePaid::class))->not->toBeEmpty();
    });
});
