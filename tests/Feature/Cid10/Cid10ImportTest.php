<?php

use App\Broadcasting\ManagerCid10ImportChannel;
use App\Enums\{ImportStatus, SaasRule};
use App\Events\ImportProgressUpdated;
use App\Jobs\ProcessCid10ImportJob;
use App\Models\{Cid10Code, Cid10Import, Entity, User};
use App\Services\Cid10\Cid10ImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\{Event, Http, Queue, Route, Storage};
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Manager → CID-10 → Importar: CID10CSV.zip do DATASUS ou CSVs, validados
 * e normalizados no envio, processados em fila com progresso por WebSocket
 * (sem endpoint HTTP de status). Só acrescenta e atualiza o oficial; nunca
 * exclui nem troca descrição editada à mão.
 */
function cid10ImportSession(Entity $saas, string $rule = 'admin'): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => $rule,
    ];
}

function asCid10Importer()
{
    return test()->actingAs(test()->admin)->withSession(cid10ImportSession(test()->saas));
}

/**
 * CSV oficial como o DATASUS publica (ISO-8859-1, CRLF), a partir da cópia
 * UTF-8 do repositório. $lines = só essas linhas de dados (null = todas).
 *
 * @param list<string>|null $lines
 */
function cid10OfficialCsv(string $kind, ?array $lines = null, bool $latin1 = true): string
{
    $all  = explode("\n", trim((string) file_get_contents(database_path("data/cid10/cid-10-{$kind}.csv"))));
    $body = $lines === null ? array_slice($all, 1) : $lines;
    $text = implode("\r\n", [$all[0], ...$body]) . "\r\n";

    return $latin1 ? mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8') : $text;
}

function cid10CategoriesCsv(): string
{
    return mb_convert_encoding("CAT;CLASSIF;DESCRICAO;DESCRABREV;REFER;EXCLUIDOS;\r\nA00;;Cólera;A00   Colera;;;\r\n", 'ISO-8859-1', 'UTF-8');
}

/** @param array<string, string> $entries nome => conteúdo */
function cid10Zip(array $entries, string $name = 'CID10CSV.zip'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'cid10zip') . '.zip';
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $entry => $content) {
        $zip->addFromString($entry, $content);
    }

    $zip->close();

    return new UploadedFile($path, $name, 'application/zip', null, true);
}

function cid10CsvUpload(string $content, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'cid10csv') . '.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/** Importação já gravada no disco (como o envio deixa) pronta para o job. */
function cid10StoredImport(string $subcategories, array $extra = []): Cid10Import
{
    $folder = 'imports/cid10/test-' . uniqid();
    Storage::disk()->put("{$folder}/cid-10-subcategorias.csv", $subcategories);

    foreach ($extra as $kind => $content) {
        Storage::disk()->put("{$folder}/cid-10-{$kind}.csv", $content);
    }

    return Cid10Import::query()->create([
        'user_id'       => test()->admin->id,
        'status'        => ImportStatus::Pending,
        'folder'        => $folder,
        'original_name' => 'CID10CSV.zip',
        'files'         => [['name' => 'CID-10-SUBCATEGORIAS.CSV', 'kind' => 'subcategorias', 'converted' => true]],
    ]);
}

function cid10RunJob(Cid10Import $import): Cid10Import
{
    (new ProcessCid10ImportJob($import))->handle(app(Cid10ImportService::class));

    return $import->fresh();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
});

describe('envio e validação', function () {
    it('zip oficial (ISO-8859-1, CRLF): reconhece os arquivos, converte para UTF-8, guarda e enfileira', function () {
        Queue::fake();

        asCid10Importer()->post(route('manager.cid10.imports.store'), ['files' => [cid10Zip([
            'CID-10-SUBCATEGORIAS.CSV' => cid10OfficialCsv('subcategorias'),
            'CID-10-GRUPOS.CSV'        => cid10OfficialCsv('grupos'),
            'CID-10-CAPITULOS.CSV'     => cid10OfficialCsv('capitulos'),
            'CID-10-CATEGORIAS.CSV'    => cid10CategoriesCsv(),
            // outra classificação (neoplasias): ignorada
            'CID-O-GRUPOS.CSV' => "CATINIC;CATFIM;DESCRICAO;REFER;\r\nM800;M800;Neoplasias SOE;;\r\n",
        ])]])->assertRedirect()->assertSessionHasNoErrors();

        $import = Cid10Import::query()->sole();
        expect($import->status)->toBe(ImportStatus::Pending)
            ->and($import->user_id)->toBe($this->admin->id)
            ->and($import->original_name)->toBe('CID10CSV.zip')
            ->and(array_column($import->files, 'kind'))->toBe(['subcategorias', 'grupos', 'capitulos', 'categorias'])
            ->and(array_column($import->files, 'converted'))->toBe([true, true, true, true]);

        $stored = Storage::disk()->get("{$import->folder}/cid-10-subcategorias.csv");
        expect(mb_check_encoding($stored, 'UTF-8'))->toBeTrue()
            ->and($stored)->toContain('A000;;;;Cólera devida a Vibrio cholerae 01, biótipo cholerae')
            ->and($stored)->not->toContain("\r");
        Storage::disk()->assertExists("{$import->folder}/cid-10-grupos.csv");
        Storage::disk()->assertExists("{$import->folder}/cid-10-capitulos.csv");
        Storage::disk()->assertMissing("{$import->folder}/cid-10-categorias.csv");
        Queue::assertPushed(ProcessCid10ImportJob::class, fn ($job) => $job->import->is($import));
    });

    it('CSVs soltos (só subcategorias, UTF-8 com BOM, nome qualquer) também valem', function () {
        Queue::fake();

        asCid10Importer()->post(route('manager.cid10.imports.store'), [
            'files' => [cid10CsvUpload("\xEF\xBB\xBF" . cid10OfficialCsv('subcategorias', ['H251;;;;Catarata senil nuclear;H25.1 Catarata senil nuclear;;;'], latin1: false), 'minha-lista.csv')],
        ])->assertSessionHasNoErrors();

        $import = Cid10Import::query()->sole();
        expect($import->files)->toBe([['name' => 'minha-lista.csv', 'kind' => 'subcategorias', 'converted' => false]])
            ->and(Storage::disk()->get("{$import->folder}/cid-10-subcategorias.csv"))->toStartWith('SUBCAT;');
        Queue::assertPushed(ProcessCid10ImportJob::class);
    });

    it('[422] recusa envio inválido com mensagem traduzida, sem criar importação nem deixar arquivo', function (callable $files, string $message) {
        Queue::fake();

        asCid10Importer()->post(route('manager.cid10.imports.store'), ['files' => $files()])
            ->assertSessionHasErrors(['files' => $message]);

        expect(Cid10Import::query()->count())->toBe(0)
            ->and(Storage::disk()->allFiles('imports/cid10'))->toBe([]);
        Queue::assertNothingPushed();
    })->with([
        'zip sem subcategorias' => [
            fn () => [cid10Zip(['CID-10-GRUPOS.CSV' => cid10OfficialCsv('grupos')])],
            fn () => __('manager_cid10.import_missing_subcategories'),
        ],
        'CSV de outra coisa' => [
            fn () => [cid10CsvUpload("nome;email\r\nFulano;f@x.com\r\n", 'pacientes.csv')],
            fn () => __('manager_cid10.import_unrecognized_file', ['name' => 'pacientes.csv']),
        ],
        'CID-O (cabeçalho parecido com o de grupos)' => [
            fn () => [cid10CsvUpload("CATINIC;CATFIM;DESCRICAO;REFER;\r\nM800;M800;Neoplasias SOE;;\r\n", 'grupos.csv')],
            fn () => __('manager_cid10.import_unrecognized_file', ['name' => 'grupos.csv']),
        ],
        'zip corrompido' => [
            fn () => [new UploadedFile(tap(tempnam(sys_get_temp_dir(), 'z') . '.zip', fn ($p) => file_put_contents($p, 'PK nao e zip de verdade')), 'CID10CSV.zip', 'application/zip', null, true)],
            fn () => __('manager_cid10.import_invalid_zip', ['name' => 'CID10CSV.zip']),
        ],
        'zip sem CSV' => [
            fn () => [cid10Zip(['LEIAME.TXT' => 'nada'])],
            fn () => __('manager_cid10.import_zip_without_csv', ['name' => 'CID10CSV.zip']),
        ],
        'mesmo tipo duas vezes' => [
            fn () => [cid10CsvUpload(cid10OfficialCsv('subcategorias', ['A09;;;;Diarréia;;;;']), 'a.csv'), cid10CsvUpload(cid10OfficialCsv('subcategorias', ['A09;;;;Diarréia;;;;']), 'b.csv')],
            fn () => __('manager_cid10.import_duplicate_file', ['name' => 'b.csv']),
        ],
    ]);

    it('[422] recusa tipo de arquivo e tamanho fora do limite (regras do formulário)', function () {
        Queue::fake();

        asCid10Importer()->post(route('manager.cid10.imports.store'), [
            'files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors('files.0');

        asCid10Importer()->post(route('manager.cid10.imports.store'), [
            'files' => [UploadedFile::fake()->create('enorme.csv', 11 * 1024, 'text/csv')],
        ])->assertSessionHasErrors('files.0');

        asCid10Importer()->post(route('manager.cid10.imports.store'), [])->assertSessionHasErrors('files');

        Queue::assertNothingPushed();
    });

    it('não aceita nova importação com outra em andamento', function () {
        Queue::fake();
        cid10StoredImport('x')->update(['status' => ImportStatus::Processing]);

        asCid10Importer()->post(route('manager.cid10.imports.store'), [
            'files' => [cid10Zip(['CID-10-SUBCATEGORIAS.CSV' => cid10OfficialCsv('subcategorias', ['A09;;;;Diarréia;;;;'])])],
        ])->assertSessionHasErrors(['files' => __('manager_cid10.import_in_progress')]);

        Queue::assertNothingPushed();
    });
});

describe('processamento (job)', function () {
    it('acrescenta, corrige texto não editado, mantém o editado (atualizando o oficial), conta erros e transmite o progresso', function () {
        Event::fake([ImportProgressUpdated::class]);

        Cid10Code::where('code', 'A00.0')->delete();
        Cid10Code::where('code', 'H50.5')->update(['description' => 'Estrabismo paralítico']);
        Cid10Code::where('code', 'H25.1')->update(['description' => 'Catarata nuclear (texto da casa)', 'description_edited_at' => now()]);
        $total = Cid10Code::count();

        $import = cid10RunJob(cid10StoredImport(cid10OfficialCsv('subcategorias', [
            'A000;;;;Cólera devida a Vibrio cholerae 01, biótipo cholerae;A00.0 Colera;;;',
            'H505;;;;Heteroforia;H50.5 Heteroforia;;;',
            // DATASUS revisou o texto de um código que o manager editou
            'H251;;;;Catarata senil nuclear (revisão);H25.1 Catarata senil nuclear;;;',
            'XYZ;;;;linha inválida;;;;',
        ])));

        expect($import->status)->toBe(ImportStatus::Done)
            ->and($import->only(['read_count', 'created_count', 'corrected_count', 'official_updated_count', 'kept_edited_count', 'skipped_invalid', 'total_rows', 'processed_rows']))
            ->toBe(['read_count' => 3, 'created_count' => 1, 'corrected_count' => 1, 'official_updated_count' => 1, 'kept_edited_count' => 1, 'skipped_invalid' => 1, 'total_rows' => 3, 'processed_rows' => 3])
            ->and($import->finished_at)->not->toBeNull()
            ->and(Cid10Code::count())->toBe($total + 1);

        expect(Cid10Code::where('code', 'A00.0')->sole()->only(['source', 'chapter', 'official_description']))
            ->toBe(['source' => 'datasus', 'chapter' => 'I', 'official_description' => 'Cólera devida a Vibrio cholerae 01, biótipo cholerae'])
            ->and(Cid10Code::where('code', 'H50.5')->value('description'))->toBe('Heteroforia')
            ->and(Cid10Code::where('code', 'H25.1')->sole()->only(['description', 'official_description']))
            ->toBe(['description' => 'Catarata nuclear (texto da casa)', 'official_description' => 'Catarata senil nuclear (revisão)']);

        Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->channel === "manager.imports.cid10.{$import->id}"
            && $e->payload['status'] === 'processing');
        Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->channel === "manager.imports.cid10.{$import->id}"
            && $e->payload['is_done'] === true
            && $e->payload['progress'] === 100
            && $e->payload['created_count'] === 1);
    });

    it('nunca exclui: código fora do arquivo enviado continua; personalizado que entrou na lista vira oficial sem perder o texto', function () {
        Cid10Code::where('code', 'A00.1')->delete();
        Cid10Code::create(['code' => 'A00.1', 'description' => 'Cólera El Tor (texto da clínica)', 'source' => 'custom']);
        $total = Cid10Code::count();

        $import = cid10RunJob(cid10StoredImport(cid10OfficialCsv('subcategorias', [
            'A001;;;;Cólera devida a Vibrio cholerae 01, biótipo El Tor;A00.1 Colera;;;',
        ])));

        $code = Cid10Code::where('code', 'A00.1')->sole();
        expect($import->kept_edited_count)->toBe(1)
            ->and(Cid10Code::count())->toBe($total)
            ->and($code->only(['source', 'description', 'official_description']))->toBe([
                'source' => 'datasus', 'description' => 'Cólera El Tor (texto da clínica)', 'official_description' => 'Cólera devida a Vibrio cholerae 01, biótipo El Tor',
            ])
            ->and($code->description_edited_at)->not->toBeNull()
            ->and(Cid10Code::where('code', 'B30')->value('source'))->toBe('custom');
    });

    it('arquivo sem nenhum código válido: falha com a mensagem e não mexe no catálogo', function () {
        $before = Cid10Code::count();

        $import = cid10RunJob(cid10StoredImport(cid10OfficialCsv('subcategorias', ['XX;;;;nada;;;;'])));

        expect($import->status)->toBe(ImportStatus::Failed)
            ->and($import->error)->toBe(__('manager_cid10.import_no_valid_rows'))
            ->and(Cid10Code::count())->toBe($before);
    });

    it('arquivo sumiu do disco: falha com a mensagem', function () {
        $import = cid10StoredImport('x');
        Storage::disk()->deleteDirectory($import->folder);

        expect(cid10RunJob($import)->only(['status', 'error']))->toBe([
            'status' => ImportStatus::Failed, 'error' => __('manager_cid10.import_file_missing'),
        ]);
    });

    it('cancelada enquanto esperava na fila: o job não roda', function () {
        $import = cid10StoredImport(cid10OfficialCsv('subcategorias', ['A09;;;;Diarréia;;;;']));
        $import->update(['status' => ImportStatus::Cancelled]);

        expect(cid10RunJob($import)->status)->toBe(ImportStatus::Cancelled);
    });

    it('worker morreu no meio: o job encerra a importação como falha (não fica processando para sempre)', function () {
        $import = cid10StoredImport('x');
        $import->update(['status' => ImportStatus::Processing]);

        (new ProcessCid10ImportJob($import))->failed(new MaxAttemptsExceededException('x'));

        expect($import->fresh()->only(['status', 'error']))->toBe([
            'status' => ImportStatus::Failed, 'error' => __('manager_cid10.import_failed_generic'),
        ]);
    });
});

describe('acompanhamento na tela (sem endpoint de status)', function () {
    it('não existe endpoint HTTP de status; a tela traz a importação em andamento e o histórico', function () {
        $import = cid10StoredImport('x');

        expect(Route::has('manager.cid10.imports.status'))->toBeFalse();

        asCid10Importer()->get(route('manager.cid10.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('runningImport.id', $import->id)
                ->where('runningImport.is_done', false)
                ->where('runningImport.channel', "manager.imports.cid10.{$import->id}")
                ->where('imports.0.user', $this->admin->name)
                ->where('imports.0.files.0.kind', 'subcategorias'));
    });

    it('cancela só importação parada; em andamento normal recusa', function () {
        $import = cid10StoredImport('x');

        asCid10Importer()->post(route('manager.cid10.imports.cancel', $import->id))
            ->assertSessionHasErrors(['import' => __('manager_cid10.import_not_stalled')]);
        expect($import->fresh()->status)->toBe(ImportStatus::Pending);

        $this->travel(Cid10Import::PENDING_STALL_SECONDS + 5)->seconds();

        asCid10Importer()->post(route('manager.cid10.imports.cancel', $import->id))->assertSessionHasNoErrors();
        expect($import->fresh()->only(['status', 'error']))->toBe([
            'status' => ImportStatus::Cancelled, 'error' => __('manager_cid10.import_cancelled_reason'),
        ]);
    });

    it('[SEGURANÇA] papel SaaS não-admin não importa nem cancela', function () {
        Queue::fake();
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        $import = cid10StoredImport('x');

        $this->actingAs($support)->withSession(cid10ImportSession($this->saas, SaasRule::Support->value))
            ->post(route('manager.cid10.imports.store'), ['files' => [cid10Zip(['CID-10-SUBCATEGORIAS.CSV' => cid10OfficialCsv('subcategorias', ['A09;;;;Diarréia;;;;'])])]])
            ->assertForbidden();
        $this->actingAs($support)->withSession(cid10ImportSession($this->saas, SaasRule::Support->value))
            ->post(route('manager.cid10.imports.cancel', $import->id))
            ->assertForbidden();

        Queue::assertNothingPushed();
    });
});

describe('[SEGURANÇA] canal manager.imports.cid10.{id}', function () {
    function joinCid10Channel(User $user, Entity $entity, string $id): bool
    {
        session(cid10ImportSession($entity));
        session(['selected_entity_is_client' => $entity->isClient()]);

        return app(ManagerCid10ImportChannel::class)->join($user, $id);
    }

    it('admin SaaS entra; suporte, clínica, id inválido ou inexistente não', function () {
        $import  = cid10StoredImport('x');
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        $clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $doctor = User::factory()->create();
        createEntityUser($clinic, $doctor, 'admin');

        expect(joinCid10Channel($this->admin, $this->saas, $import->id))->toBeTrue()
            ->and(joinCid10Channel($support, $this->saas, $import->id))->toBeFalse()
            ->and(joinCid10Channel($doctor, $clinic, $import->id))->toBeFalse()
            ->and(joinCid10Channel($this->admin, $this->saas, 'nao-e-uuid'))->toBeFalse()
            ->and(joinCid10Channel($this->admin, $this->saas, (string) Str::uuid()))->toBeFalse();
    });
});
