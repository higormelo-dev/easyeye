<?php

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Fiscal\NfseStatus;
use App\Jobs\Fiscal\SendNfseJob;
use App\Models\Fiscal\NfseDocument;
use App\Support\Fiscal\{NfsePendingInvoices, NfseSettings};
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\{DB, Http, Queue};

require_once __DIR__ . '/Support/NfseFixtures.php';

/** Envelhece a nota (a espera mínima do lote conta pela criação). */
function nfseAge(NfseDocument $document, int $hours): NfseDocument
{
    DB::table('nfse_documents')->where('id', $document->id)->update(['created_at' => now()->subHours($hours)]);

    return $document->fresh();
}

beforeEach(function () {
    Queue::fake([SendNfseJob::class]);
    Http::preventStrayRequests();
    nfseReady(['auto_issue' => true]);
});

describe('chave da emissão automática', function () {
    it('desligada (padrão): não emite nada e diz por quê', function () {
        (new NfseSettings())->save(['auto_issue' => false]);
        nfsePaidInvoice();

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Emissão automática desligada')->assertExitCode(0);

        expect(NfseDocument::query()->count())->toBe(0);
    });

    it('--force roda mesmo desligada', function () {
        (new NfseSettings())->save(['auto_issue' => false]);
        nfsePaidInvoice();

        $this->artisan('fiscal:nfse-reissue --force')->assertExitCode(0);

        expect(NfseDocument::query()->sole()->source)->toBe('batch');
    });

    it('prestador incompleto: falha (exige atenção) e não emite', function () {
        (new NfseSettings())->save(['prestador' => ['cnpj' => '']]);
        nfsePaidInvoice();

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('prestador.cnpj')->assertExitCode(1);

        expect(NfseDocument::query()->count())->toBe(0);
    });
});

describe('emissão', function () {
    it('cria a nota (origem batch) das faturas pagas sem nota e enfileira o envio', function () {
        $first  = nfsePaidInvoice();
        $second = nfsePaidInvoice();

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Criadas: 2. Barradas: 0.')->assertExitCode(0);

        expect(NfseDocument::query()->pluck('invoice_id')->all())->toEqualCanonicalizing([$first->id, $second->id])
            ->and(NfseDocument::query()->where('source', 'batch')->count())->toBe(2);

        Queue::assertPushed(SendNfseJob::class, 2);
    });

    it('--dry-run só lista, sem criar nem enfileirar', function () {
        $invoice = nfsePaidInvoice();

        $this->artisan('fiscal:nfse-reissue --dry-run')
            ->expectsOutputToContain('Emitiria 1 fatura')
            ->expectsOutputToContain($invoice->reference)
            ->assertExitCode(0);

        expect(NfseDocument::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('sem nada para emitir, diz isso', function () {
        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Nenhuma fatura paga sem nota')->assertExitCode(0);
    });

    it('rodar de novo não duplica (a fatura já tem nota a caminho)', function () {
        nfsePaidInvoice();

        $this->artisan('fiscal:nfse-reissue')->assertExitCode(0);
        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Nenhuma fatura paga sem nota')->assertExitCode(0);

        expect(NfseDocument::query()->count())->toBe(1);
        Queue::assertPushed(SendNfseJob::class, 1);
    });

    it('respeita --limit e vai da fatura paga mais antiga para a mais nova', function () {
        $newest = nfsePaidInvoice(null, ['paid_at' => now()->subDays(1)]);
        $oldest = nfsePaidInvoice(null, ['paid_at' => now()->subDays(10)]);
        $middle = nfsePaidInvoice(null, ['paid_at' => now()->subDays(5)]);

        $this->artisan('fiscal:nfse-reissue --limit=2')->assertExitCode(0);

        expect(NfseDocument::query()->pluck('invoice_id')->all())->toEqualCanonicalizing([$oldest->id, $middle->id]);
    });

    it('conta as barradas por motivo e segue com as demais', function () {
        $ok  = nfsePaidInvoice();
        $bad = nfsePaidInvoice(nfseClinic(['national_registration' => '11222333000182']));

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Criadas: 1. Barradas: 1 (documento_invalido: 1)')->assertExitCode(0);

        expect(NfseDocument::query()->sole()->invoice_id)->toBe($ok->id);
    });
});

describe('quem entra no lote', function () {
    it('só fatura paga, de clínica cliente, com valor e sem estorno total', function () {
        $eligible = nfsePaidInvoice();

        nfsePaidInvoice(null, ['status' => InvoiceStatus::Pending->value]);
        nfsePaidInvoice(null, ['status' => InvoiceStatus::Refunded->value, 'refunded_amount' => 199.90]);
        nfsePaidInvoice(null, ['refunded_amount' => 199.90]);
        nfsePaidInvoice(null, ['amount' => 0]);
        nfsePaidInvoice(null, ['billing_reason' => 'unapplied_payment']);
        nfsePaidInvoice(nfseClinic(['is_client' => false]));
        $partial = nfsePaidInvoice(null, ['refunded_amount' => 50.00]); // estorno parcial não impede

        $this->artisan('fiscal:nfse-reissue')->assertExitCode(0);

        expect(NfseDocument::query()->pluck('invoice_id')->all())->toEqualCanonicalizing([$eligible->id, $partial->id]);
    });

    it('só a janela recente (--since e config)', function () {
        $recent = nfsePaidInvoice(null, ['paid_at' => now()->subDays(10)]);
        $old    = nfsePaidInvoice(null, ['paid_at' => now()->subDays(100)]);

        $this->artisan('fiscal:nfse-reissue')->assertExitCode(0); // padrão 90 dias

        expect(NfseDocument::query()->pluck('invoice_id')->all())->toBe([$recent->id]);

        $this->artisan('fiscal:nfse-reissue --since=200')->assertExitCode(0);

        expect(NfseDocument::query()->pluck('invoice_id')->all())->toEqualCanonicalizing([$recent->id, $old->id]);
    });

    it('fatura com nota a caminho ou autorizada não entra', function () {
        foreach ([NfseStatus::PendingSend, NfseStatus::Processing, NfseStatus::Authorized] as $status) {
            nfseDocument(nfsePaidInvoice(), ['status' => $status->value]);
        }

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Nenhuma fatura paga sem nota')->assertExitCode(0);

        expect(NfseDocument::query()->count())->toBe(3);
    });

    it('nota CANCELADA nunca é refeita sozinha (cancelar é decisão humana)', function () {
        nfseAge(nfseDocument(nfsePaidInvoice(), ['status' => NfseStatus::Cancelled->value]), 48);

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Nenhuma fatura paga sem nota')->assertExitCode(0);

        expect(NfseDocument::query()->count())->toBe(1);
    });

    it('refaz a que falhou, mas só depois de 6 h da última tentativa', function (NfseStatus $failure) {
        $invoice = nfsePaidInvoice();
        $failed  = nfseDocument($invoice, ['status' => $failure->value]);

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Nenhuma fatura paga sem nota')->assertExitCode(0);

        nfseAge($failed, 5);
        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Nenhuma fatura paga sem nota')->assertExitCode(0);

        nfseAge($failed, 7);
        $this->artisan('fiscal:nfse-reissue')->assertExitCode(0);

        expect(NfseDocument::query()->where('invoice_id', $invoice->id)->count())->toBe(2)
            ->and(NfseDocument::query()->where('invoice_id', $invoice->id)->where('status', NfseStatus::PendingSend->value)->count())->toBe(1);
    })->with([NfseStatus::SendFailed, NfseStatus::AuthorizationError]);

    it('para de insistir depois de 3 notas na mesma fatura (erro persistente não vira uma nota nova por dia)', function () {
        $invoice = nfsePaidInvoice();

        foreach (range(1, NfsePendingInvoices::BATCH_MAX_DOCUMENTS) as $attempt) {
            nfseAge(nfseDocument($invoice, ['status' => NfseStatus::AuthorizationError->value]), 24 * $attempt);
        }

        $this->artisan('fiscal:nfse-reissue')->expectsOutputToContain('Nenhuma fatura paga sem nota')->assertExitCode(0);

        expect(NfseDocument::query()->count())->toBe(3);
    });
});

describe('lista do Manager (mesma regra, sem os tetos do lote)', function () {
    it('inclui a fatura de nota cancelada ou falha antiga, e exclui a que tem nota ocupando', function () {
        $cancelled = nfsePaidInvoice();
        nfseDocument($cancelled, ['status' => NfseStatus::Cancelled->value]);

        $failed = nfsePaidInvoice();
        nfseDocument($failed, ['status' => NfseStatus::AuthorizationError->value]);

        $busy = nfsePaidInvoice();
        nfseDocument($busy, ['status' => NfseStatus::Processing->value]);

        $none = nfsePaidInvoice();

        expect(NfsePendingInvoices::forList()->pluck('invoices.id')->all())->toEqualCanonicalizing([$cancelled->id, $failed->id, $none->id]);
    });
});

describe('agenda', function () {
    it('roda todo dia às 05:10, sem sobreposição', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'fiscal:nfse-reissue'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('10 5 * * *')
            ->and($event->withoutOverlapping)->toBeTrue();
    });
});
