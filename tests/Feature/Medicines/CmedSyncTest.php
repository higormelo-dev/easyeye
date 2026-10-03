<?php

use App\Enums\{ImportStatus, MedicineSource, SaasRule};
use App\Jobs\ProcessMedicineImportJob;
use App\Models\{Entity, Medicine, MedicineImport, User};
use App\Services\Medicines\MedicineCatalogSyncService;
use Illuminate\Console\Scheduling\{Event as ScheduledEvent, Schedule};
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Http, Queue, Storage};
use Illuminate\Support\Sleep;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Sincronização do catálogo de medicamentos direto das fontes oficiais
 * (MedicineCatalogSyncService + CmedListDownloader): link da lista PMC lido
 * da página da CMED, reserva no portal de dados abertos, situação dos
 * registros, "nada mudou", segurança dos downloads, fila e tela.
 */
const SYNC_CMED_HEADER = [
    'SUBSTÂNCIA', 'CNPJ', 'LABORATÓRIO', 'CÓDIGO GGREM', 'REGISTRO', 'EAN 1', 'EAN 2', 'EAN 3', 'PRODUTO',
    'APRESENTAÇÃO', 'CLASSE TERAPÊUTICA', 'TIPO DE PRODUTO (STATUS DO PRODUTO)', 'REGIME DE PREÇO',
    'PF Sem Impostos', 'RESTRIÇÃO HOSPITALAR', 'COMERCIALIZAÇÃO 2025', 'TARJA',
];

const SYNC_PAGE  = 'https://www.gov.br/anvisa/pt-br/assuntos/medicamentos/cmed/precos';
const SYNC_LIST  = 'https://www.gov.br/anvisa/pt-br/assuntos/medicamentos/cmed/precos/arquivos/lista_pmc_20260923_222937320.xlsx/@@download/file';
const SYNC_OLDER = 'https://www.gov.br/anvisa/pt-br/assuntos/medicamentos/cmed/precos/arquivos/lista_pmc_20260815_111111111.xlsx/@@download/file';

/** @return array<string, string> */
function syncCmedRow(string $ggrem, string $product, string $registration = '1000000010011'): array
{
    return [
        'SUBSTÂNCIA'                          => 'ACETATO DE PREDNISOLONA',
        'CNPJ'                                => '00.000.000/0001-00',
        'LABORATÓRIO'                         => 'LAB TESTE S/A',
        'CÓDIGO GGREM'                        => $ggrem,
        'REGISTRO'                            => $registration,
        'EAN 1'                               => '7890000000011',
        'EAN 2'                               => '    -     ',
        'EAN 3'                               => '    -     ',
        'PRODUTO'                             => $product,
        'APRESENTAÇÃO'                        => '10 MG/ML SUS OFT CT FR GOT PLAS OPC X 5 ML',
        'CLASSE TERAPÊUTICA'                  => 'S1B - CORTICOESTERÓIDES OFTÁLMICOS',
        'TIPO DE PRODUTO (STATUS DO PRODUTO)' => 'Similar',
        'REGIME DE PREÇO'                     => 'Regulado',
        'PF Sem Impostos'                     => '10,00',
        'RESTRIÇÃO HOSPITALAR'                => 'Não',
        'COMERCIALIZAÇÃO 2025'                => 'Sim',
        'TARJA'                               => 'Tarja Vermelha',
    ];
}

/** Bytes de uma lista PMC em XLSX (aba de preâmbulo + "Lista PMC"). */
function syncCmedXlsx(array $rows): string
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->setTitle('Cabeçalho')->setCellValue('A1', 'Secretaria Executiva - CMED');
    $sheet = $spreadsheet->createSheet()->setTitle('Lista PMC');
    $sheet->fromArray([SYNC_CMED_HEADER, ...array_map(fn ($r) => array_values($r), $rows)], null, 'A1', true);

    foreach ($rows as $i => $row) {
        foreach (['D', 'E', 'F'] as $col) {
            $sheet->setCellValueExplicit($col . ($i + 2), $row[SYNC_CMED_HEADER[ord($col) - 65]], DataType::TYPE_STRING);
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'synccmed') . '.xlsx';
    (new Xlsx($spreadsheet))->save($tmp);
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

/** Mesma lista em CSV, como no portal de dados abertos (preâmbulo + cabeçalho). */
function syncCmedCsv(array $rows): string
{
    $lines = [
        'Secretaria Executiva - CMED;;;',
        'LISTA DE PREÇOS DE MEDICAMENTOS - PREÇOS FÁBRICA E MÁXIMOS AO CONSUMIDOR;;;',
        'Publicada em 21/07/2026 17h30min.;;;',
        '',
        implode(';', SYNC_CMED_HEADER),
        ...array_map(fn ($r) => implode(';', array_values($r)), $rows),
    ];

    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

/** Dados abertos de medicamentos (Latin-1, como o arquivo real). */
function syncOpenData(array $registrations): string
{
    $lines = ['TIPO_PRODUTO;NOME_PRODUTO;NUMERO_REGISTRO_PRODUTO;SITUACAO_REGISTRO;PRINCIPIO_ATIVO'];

    foreach ($registrations as $registration => $status) {
        $lines[] = "\"MEDICAMENTO\";\"X\";{$registration};\"{$status}\";\"x\"";
    }

    return mb_convert_encoding(implode("\n", $lines), 'ISO-8859-1', 'UTF-8');
}

function syncPage(array $links): string
{
    return '<html><body>' . implode('', array_map(fn ($href) => "<a href=\"{$href}\">Lista</a>", $links)) . '</body></html>';
}

/**
 * Fontes simuladas. Cada valor: corpo (string), resposta ou null (fora do
 * ar). HEAD devolve Last-Modified.
 *
 * @param array<string, mixed> $sources
 */
function fakeOfficialSources(array $sources): void
{
    test()->sources = $sources + [
        'page'          => syncPage(['/anvisa/pt-br/assuntos/medicamentos/cmed/precos/arquivos/lista_pmc_20260815_111111111.xlsx/@@download/file', '/anvisa/pt-br/assuntos/medicamentos/cmed/precos/arquivos/lista_pmc_20260923_222937320.xlsx/@@download/file']),
        'list'          => syncCmedXlsx([syncCmedRow('500000000000001', 'PREDOPTIC')]),
        'fallback'      => syncCmedCsv([syncCmedRow('500000000000099', 'RESERVA')]),
        'open'          => syncOpenData(['100000001' => 'Ativo']),
        'open_modified' => 'Fri, 02 Oct 2026 18:28:42 GMT',
    ];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $s   = test()->sources;
        $url = $request->url();

        $route = match (true) {
            $url === SYNC_PAGE                                  => 'page',
            str_contains($url, 'lista_pmc_')                    => 'list',
            $url === config('medicines.cmed.fallback_list_url') => 'fallback',
            $url === config('medicines.cmed.open_data_url')     => 'open',
            default                                             => null,
        };

        if ($route === null || ($s[$route] ?? null) === null) {
            return Http::response('fora do ar', 503);
        }

        if ($request->method() === 'HEAD') {
            return Http::response('', 200, ['Last-Modified' => $route === 'open' ? $s['open_modified'] : 'Tue, 21 Jul 2026 20:39:00 GMT']);
        }

        return $s[$route] instanceof Closure ? ($s[$route])() : Http::response($s[$route]);
    });
}

function runSync(bool $force = false, string $source = MedicineImport::SOURCE_CMED): MedicineImport
{
    $import = MedicineImport::query()->create(['source' => $source, 'force' => $force, 'status' => ImportStatus::Pending]);

    app(MedicineCatalogSyncService::class)->run($import);

    return $import->fresh();
}

function syncMedicine(string $ggrem): ?Medicine
{
    return Medicine::withoutGlobalScopes()->where('source', MedicineSource::Cmed->value)->where('source_code', $ggrem)->first();
}

beforeEach(function () {
    Storage::fake();
    Sleep::fake();
    $this->sources = [];
});

it('baixa a lista PMC MAIS RECENTE da página oficial + dados abertos, importa e registra a versão', function () {
    fakeOfficialSources([]);

    $import = runSync();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->created_count)->toBe(1)
        ->and($import->list_version)->toBe('20260923_222937320')
        ->and($import->list_published_at->toDateString())->toBe('2026-09-23')
        ->and($import->list_url)->toBe(SYNC_LIST)
        ->and($import->open_data_version)->toBe('2026-10-02T18:28:42Z')
        ->and($import->cmed_original_name)->toBe('lista_pmc_20260923_222937320.xlsx')
        ->and($import->open_data_original_name)->toBe('DADOS_ABERTOS_MEDICAMENTOS.csv')
        ->and($import->notice)->toBeNull()
        ->and(syncMedicine('500000000000001')?->name)->toBe('PREDOPTIC');

    Http::assertSent(fn (Request $r) => $r->url() === SYNC_LIST && $r->method() === 'GET');
    Http::assertNotSent(fn (Request $r) => $r->url() === SYNC_OLDER);

    // Arquivos baixados não ficam guardados (públicos e versionados).
    expect($import->cmed_file_path)->toBeNull()
        ->and(Storage::disk()->allFiles())->toBe([]);
});

it('nada mudou (mesma lista e mesmos dados abertos): termina sem baixar nem reprocessar; "forçar" reprocessa', function () {
    fakeOfficialSources([]);
    runSync();

    $second = runSync();

    expect($second->status)->toBe(ImportStatus::Done)
        ->and($second->created_count + $second->updated_count)->toBe(0)
        ->and($second->notice)->toBe(__('manager_medicines.sync_unchanged', ['date' => $second->list_published_at->isoFormat('L')]));
    // 1ª carga: página, HEAD dos dados abertos, lista, dados abertos; 2ª: só página + HEAD.
    Http::assertSentCount(6);

    $forced = runSync(force: true);
    expect($forced->updated_count)->toBe(1)
        ->and($forced->notice)->toBeNull();
});

it('dados abertos mudaram (registro cancelado): reprocessa e tira do receituário', function () {
    $list = syncCmedXlsx([
        syncCmedRow('500000000000001', 'PREDOPTIC', '1000000010011'),
        syncCmedRow('500000000000002', 'OUTRO COLÍRIO', '2000000020011'),
    ]);
    fakeOfficialSources(['list' => $list]);
    runSync();

    fakeOfficialSources(['list' => $list, 'open' => syncOpenData(['100000001' => 'Cancelado']), 'open_modified' => 'Sat, 03 Oct 2026 18:00:00 GMT']);
    $import = runSync();

    expect($import->skipped_inactive_registration)->toBe(1)
        ->and($import->deactivated_count)->toBe(1)
        ->and(syncMedicine('500000000000001')->active)->toBeFalse()
        ->and(syncMedicine('500000000000002')->active)->toBeTrue();
});

it('página da CMED fora do ar: usa a reserva do portal de dados abertos e avisa a data da lista', function () {
    fakeOfficialSources(['page' => null]);

    $import = runSync();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->list_version)->toBe('dados_abertos_20260721')
        ->and($import->list_published_at->toDateString())->toBe('2026-07-21')
        ->and($import->list_url)->toBe(config('medicines.cmed.fallback_list_url'))
        ->and($import->notice)->toBe(__('manager_medicines.sync_fallback_used', ['date' => '21/07/2026']))
        ->and(syncMedicine('500000000000099')?->name)->toBe('RESERVA');
});

it('página sem o link (layout mudou) ou link do arquivo devolvendo página HTML: reserva', function (array $sources) {
    fakeOfficialSources($sources);

    $import = runSync();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->list_version)->toBe('dados_abertos_20260721');
})->with([
    'sem link'       => [['page' => '<html><body>Página nova</body></html>']],
    'arquivo é HTML' => [['list' => '<!doctype html><html><body>Acesso negado</body></html>']],
]);

it('[SEGURANÇA] link de outro domínio na página é ignorado, mesmo sendo o "mais novo"', function () {
    fakeOfficialSources(['page' => syncPage([
        'https://evil.example/anvisa/lista_pmc_20261231_999.xlsx/@@download/file',
        '/anvisa/pt-br/assuntos/medicamentos/cmed/precos/arquivos/lista_pmc_20260923_222937320.xlsx/@@download/file',
    ])]);

    $import = runSync();

    expect($import->list_url)->toBe(SYNC_LIST);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
});

it('[SEGURANÇA] arquivo acima do teto é recusado (e a reserva também): carga falha sem tocar no catálogo', function () {
    config(['medicines.cmed.file_max_bytes' => 100]);
    fakeOfficialSources([]);

    $import = runSync();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_medicines.sync_download_failed'))
        ->and(Medicine::withoutGlobalScopes()->where('source', 'cmed')->count())->toBe(0);
});

it('página e reserva fora do ar: falha com orientação para enviar a planilha', function () {
    fakeOfficialSources(['page' => null, 'fallback' => null]);

    $import = runSync();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_medicines.sync_list_unavailable'));
});

it('dados abertos fora do ar: importa sem conferir os registros e avisa', function () {
    fakeOfficialSources(['open' => null]);

    $import = runSync();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->created_count)->toBe(1)
        ->and($import->open_data_version)->toBeNull()
        ->and($import->open_data_original_name)->toBeNull()
        ->and($import->notice)->toBe(__('manager_medicines.sync_open_data_unavailable'));
});

it('[FILA] job ignora carga cancelada; envio manual continua pelo mesmo job', function () {
    Http::preventStrayRequests();
    Http::fake();
    $cancelled = MedicineImport::query()->create(['source' => MedicineImport::SOURCE_CMED, 'status' => ImportStatus::Cancelled]);

    (new ProcessMedicineImportJob($cancelled))->handle(app(MedicineCatalogSyncService::class));

    expect($cancelled->fresh()->status)->toBe(ImportStatus::Cancelled)
        ->and($cancelled->fresh()->started_at)->toBeNull();
    Http::assertNothingSent();
});

it('comando enfileira a verificação semanal; não empilha com outra em andamento', function () {
    Queue::fake();

    $this->artisan('medicines:sync-cmed')->assertSuccessful();
    $this->artisan('medicines:sync-cmed')->assertSuccessful();

    $import = MedicineImport::query()->sole();
    expect($import->source)->toBe(MedicineImport::SOURCE_SCHEDULED)
        ->and($import->user_id)->toBeNull()
        ->and($import->force)->toBeFalse();
    Queue::assertPushed(ProcessMedicineImportJob::class, 1);
});

it('agendamento: toda terça às 05:00, sem sobreposição, desligável por config', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $e) => str_contains((string) $e->command, 'medicines:sync-cmed'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 5 * * 2')
        ->and($event->withoutOverlapping)->toBeTrue();

    config(['medicines.cmed.sync_enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['medicines.cmed.sync_enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});

describe('tela do manager', function () {
    beforeEach(function () {
        $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $this->admin = User::factory()->create();
        createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
    });

    function asMedicinesSyncAdmin(User $user, Entity $saas, string $rule = 'admin')
    {
        return test()->actingAs($user)->withSession([
            'selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule,
        ]);
    }

    it('"Atualizar agora" enfileira o download (sem arquivo), com o "forçar"', function () {
        Queue::fake();

        asMedicinesSyncAdmin($this->admin, $this->saas)->post(route('manager.medicines.imports.store'), ['source' => 'cmed', 'force' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $import = MedicineImport::query()->sole();
        expect($import->source)->toBe(MedicineImport::SOURCE_CMED)
            ->and($import->force)->toBeTrue()
            ->and($import->user_id)->toBe($this->admin->id)
            ->and($import->cmed_file_path)->toBeNull();
        Queue::assertPushed(ProcessMedicineImportJob::class);
    });

    it('envio manual sem a planilha é recusado; com outra carga em andamento também', function () {
        Queue::fake();

        asMedicinesSyncAdmin($this->admin, $this->saas)->post(route('manager.medicines.imports.store'), ['source' => 'upload'])
            ->assertSessionHasErrors('cmed_file');

        MedicineImport::query()->create(['source' => 'scheduled', 'status' => ImportStatus::Processing]);
        asMedicinesSyncAdmin($this->admin, $this->saas)->post(route('manager.medicines.imports.store'), ['source' => 'cmed'])
            ->assertSessionHasErrors(['source' => __('manager_medicines.import_in_progress')]);

        Queue::assertNothingPushed();
    });

    it('carga parada na fila: admin cancela e libera; em andamento normal recusa; suporte não cancela', function () {
        $stuck = MedicineImport::query()->create(['source' => 'cmed', 'status' => ImportStatus::Pending]);
        $stuck->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();
        $fresh = MedicineImport::query()->create(['source' => 'cmed', 'status' => ImportStatus::Pending]);

        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        asMedicinesSyncAdmin($support, $this->saas, SaasRule::Support->value)
            ->post(route('manager.medicines.imports.cancel', $stuck->id))->assertForbidden();

        asMedicinesSyncAdmin($this->admin, $this->saas)->post(route('manager.medicines.imports.cancel', $fresh->id))
            ->assertSessionHasErrors(['import' => __('manager_medicines.import_not_stalled')]);

        asMedicinesSyncAdmin($this->admin, $this->saas)->post(route('manager.medicines.imports.cancel', $stuck->id))
            ->assertSessionHasNoErrors();

        expect($stuck->fresh()->status)->toBe(ImportStatus::Cancelled)
            ->and($stuck->fresh()->error)->toBe(__('manager_medicines.import_cancelled_reason'))
            ->and($fresh->fresh()->status)->toBe(ImportStatus::Pending);
    });

    it('tela traz a flag da verificação automática e o progresso com origem e prazo de "parada"', function () {
        config(['medicines.cmed.sync_enabled' => true]);
        MedicineImport::query()->create(['source' => 'scheduled', 'status' => ImportStatus::Pending]);

        asMedicinesSyncAdmin($this->admin, $this->saas)->get(route('manager.medicines.index'))
            ->assertInertia(fn ($page) => $page
                ->where('autoSync', true)
                ->where('runningImport.source', 'scheduled')
                ->where('runningImport.source_label', __('manager_medicines.import_source_scheduled'))
                ->where('runningImport.stall_after_seconds', MedicineImport::PENDING_STALL_SECONDS));
    });
});
