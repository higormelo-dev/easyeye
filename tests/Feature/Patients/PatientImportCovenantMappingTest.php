<?php

declare(strict_types=1);

/**
 * De-para de convênios na importação de pacientes (plano patients-import-covenant-mapping-plano.md): o nome do
 * convênio como veio na planilha aponta para um convênio/plano do catálogo da clínica; vale para as próximas
 * importações. Isolamento por entity_id, plano opcional que prevalece sobre a coluna da planilha, criação idempotente.
 */

require_once __DIR__ . '/../../Support/PatientImportCovenantHelpers.php';

use App\Enums\ImportStatus;
use App\Jobs\ProcessPatientImportJob;
use App\Models\{Covenant, CovenantPlan, Entity, Patient, PatientImport, PatientImportCovenantMapping, User};
use App\Services\Covenants\{PatientImportCovenantMappingService, PatientImportCovenantReportService, PatientImportCovenantResolver};
use App\Services\PatientImportService;
use App\Support\ImportNameNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Bus, DB, Queue, Storage};

// ── Passo 1: schema e models ───────────────────────────────────────────────────

it('guarda o de-para por clínica: único por nome normalizado; o mesmo nome em outra clínica é permitido', function () {
    [$a, $b]   = [picmEntity(), picmEntity()];
    $covenant  = Covenant::factory()->create(['name' => 'SULAMERICA']);
    $plan      = CovenantPlan::withoutGlobalScopes()->forceCreate(['entity_id' => null, 'covenant_id' => $covenant->id, 'name' => 'SULA ESPECIAL', 'active' => true]);
    $attribute = fn (Entity $entity, string $sourceName = 'Sul América') => [
        'entity_id' => $entity->id, 'source_name' => $sourceName, 'source_name_normalized' => 'sul_america', 'covenant_id' => $covenant->id,
    ];

    $mapping = PatientImportCovenantMapping::create([...$attribute($a), 'covenant_plan_id' => $plan->id]);
    PatientImportCovenantMapping::create($attribute($b));

    expect($mapping->covenant->is($covenant))->toBeTrue()
        ->and($mapping->plan->is($plan))->toBeTrue()
        ->and($mapping->entity->is($a))->toBeTrue()
        ->and(PatientImportCovenantMapping::withoutGlobalScopes()->count())->toBe(2)
        ->and(fn () => DB::transaction(fn () => PatientImportCovenantMapping::create($attribute($a, 'SUL AMERICA'))))->toThrow(QueryException::class);

    // Plano removido não derruba o de-para: ele só perde o plano (nullOnDelete).
    CovenantPlan::withoutGlobalScopes()->whereKey($plan->id)->forceDelete();
    expect($mapping->fresh()->covenant_plan_id)->toBeNull();
});

it('PatientImport guarda linhas reprocessáveis, snapshot e prazo dos arquivos; o filho aponta o pai', function () {
    $entity = picmEntity();
    $parent = picmImport($entity, ['status' => ImportStatus::Done, 'covenant_error_lines' => [2, 5], 'files_expire_at' => now()->addDays(30)]);
    $child  = picmImport($entity, [
        'reprocess_of_id'           => $parent->id, 'reprocess_lines' => [2, 5],
        'covenant_mapping_snapshot' => ['pas_606' => ['source_name' => 'PAS 606', 'covenant_id' => 'x', 'covenant_plan_id' => null]],
    ]);

    [$parent, $child] = [$parent->fresh(), $child->fresh()];

    expect($parent->covenant_error_lines)->toBe([2, 5])
        ->and($parent->hasCovenantErrors())->toBeTrue()
        ->and($parent->isReprocess())->toBeFalse()
        ->and($parent->files_expire_at)->toBeInstanceOf(Carbon::class)
        ->and($parent->files_purged_at)->toBeNull()
        ->and($child->isReprocess())->toBeTrue()
        ->and($child->reprocess_lines)->toBe([2, 5])
        ->and($child->covenant_mapping_snapshot['pas_606']['covenant_id'])->toBe('x')
        ->and($child->reprocessOf->is($parent))->toBeTrue()
        ->and($parent->reprocesses->pluck('id')->all())->toBe([$child->id]);

    // [] = processado sem erro de convênio; null = import anterior a esta funcionalidade.
    expect(picmImport($entity, ['covenant_error_lines' => []])->fresh()->hasCovenantErrors())->toBeFalse()
        ->and(picmImport($entity)->fresh()->covenant_error_lines)->toBeNull();
});

it('só um reprocessamento ativo por import pai (índice único parcial); finalizados não contam', function () {
    $entity = picmEntity();
    $parent = picmImport($entity, ['status' => ImportStatus::Done]);

    picmImport($entity, ['reprocess_of_id' => $parent->id, 'status' => ImportStatus::Done]);
    picmImport($entity, ['reprocess_of_id' => $parent->id, 'status' => ImportStatus::Failed]);
    picmImport($entity, ['reprocess_of_id' => $parent->id, 'status' => ImportStatus::Pending]);

    expect(fn () => DB::transaction(fn () => picmImport($entity, ['reprocess_of_id' => $parent->id, 'status' => ImportStatus::Processing])))
        ->toThrow(QueryException::class);
});

// ── Passo 2: normalizador compartilhado ────────────────────────────────────────

it('ImportNameNormalizer: minúsculo, sem acento; espaço e hífen viram "_"; o resto some', function (string $input, string $expected) {
    expect(ImportNameNormalizer::key($input))->toBe($expected);
})->with([
    'acento e caixa'     => ['SulAmérica', 'sulamerica'],
    'caixa alta'         => ['SULAMERICA', 'sulamerica'],
    'espaço'             => ['PAS 606', 'pas_606'],
    'hífen'              => ['Unimed-Rio', 'unimed_rio'],
    'cabeçalho'          => ['Convênio', 'convenio'],
    'pontuação'          => ['PAS 606 (antigo)', 'pas_606_antigo'],
    'espaços nas bordas' => ['  Amil  ', 'amil'],
    'só símbolos'        => ['   ', ''],
]);

it('covenantKey (só nomes de convênio) é a key() sem separadores: "SulAmérica", "SULAMERICA" e "Sul América" são o mesmo', function (string $input, string $expected) {
    expect(ImportNameNormalizer::covenantKey($input))->toBe($expected);
})->with([
    'junto com acento' => ['SulAmérica', 'sulamerica'],
    'caixa alta'       => ['SULAMERICA', 'sulamerica'],
    'separado'         => ['Sul América', 'sulamerica'],
    'hífen'            => ['Unimed-Rio', 'unimedrio'],
    'número'           => ['PAS 606', 'pas606'],
    'sublinhado'       => ['cartao_debito', 'cartaodebito'],
    'só símbolos'      => ['  - ', ''],
]);

it('covenantSourceKey: vazio é "", nome só de símbolos não colide com "sem convênio"', function () {
    expect(ImportNameNormalizer::covenantSourceKey('  '))->toBe('')
        ->and(ImportNameNormalizer::covenantSourceKey('Sul América'))->toBe('sulamerica')
        ->and(ImportNameNormalizer::covenantSourceKey(' - '))->toBe('#-')
        ->and(ImportNameNormalizer::covenantSourceKey('N/A'))->toBe('na');
});

it('key() segue inalterada para cabeçalhos e planos (mantém o "_")', function () {
    expect(ImportNameNormalizer::key('Nome do Plano'))->toBe('nome_do_plano')
        ->and(ImportNameNormalizer::key('Sul América'))->toBe('sul_america')
        ->and(ImportNameNormalizer::covenantKey('Sul América'))->toBe(ImportNameNormalizer::covenantKey('SulAmérica'));
});

it('o catálogo casa por covenantKey: "Sul América" e "sulamérica" na planilha acham o convênio "SULAMERICA"', function () {
    Storage::fake();
    $clinic = picmClinic();
    $sula   = Covenant::factory()->create(['name' => 'SULAMERICA']);

    $import = picmProcess($clinic, "nome;celular;convenio\nAna Lima;11911110001;Sul América\nBia Lima;11911110002;sulamérica\nCaio Lima;11911110003;SULAMERICA\n");

    expect($import->imported_rows)->toBe(3)
        ->and($import->error_rows)->toBe(0)
        ->and(Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->pluck('covenant_id')->unique()->all())->toBe([$sula->id]);
});

// ── Passo 3: resolvedor de convênios do import ─────────────────────────────────

it('resolvedor: catálogo ativo casa por covenantKey; vazio vira Particular; inativo e inexistente lançam com o motivo', function () {
    $clinic     = picmEntity();
    $sula       = Covenant::factory()->create(['name' => 'SULAMERICA']);
    $particular = picmParticular();
    Covenant::factory()->create(['name' => 'ASPEB', 'active' => false]);
    $resolver = picmResolver($clinic);

    expect($resolver->forRow('Sul América'))->toBe(['covenant_id' => $sula->id, 'mapped_plan_id' => null, 'is_particular' => false])
        ->and($resolver->forRow('  '))->toBe(['covenant_id' => $particular->id, 'mapped_plan_id' => null, 'is_particular' => true])
        ->and($resolver->forRow(null)['covenant_id'])->toBe($particular->id);

    $missing  = picmFailure(fn () => $resolver->forRow('PAS 606'));
    $inactive = picmFailure(fn () => $resolver->forRow('Aspeb'));

    expect([$missing->reason, $missing->sourceName, $missing->sourceNameNormalized])->toBe(['not_found', 'PAS 606', 'pas606'])
        ->and($missing->getMessage())->toContain('Convênio "PAS 606" não encontrado')
        ->and([$inactive->reason, $inactive->sourceNameNormalized])->toBe(['inactive', 'aspeb'])
        ->and($inactive->getMessage())->toBe(__('validation.custom.covenant.inactive_named', ['name' => 'Aspeb']))
        ->and($missing)->toBeInstanceOf(RuntimeException::class);
});

it('resolvedor: sem convênio Particular cadastrado, linha vazia lança particular_missing', function () {
    picmParticular()->delete();
    $resolver = picmResolver(picmEntity());

    $e = picmFailure(fn () => $resolver->forRow(null));

    expect($e->reason)->toBe('particular_missing')->and($e->getMessage())->toContain('Particular');
});

it('resolvedor: o de-para prevalece sobre o catálogo e o plano mapeado vem junto; Particular é aceito', function () {
    $clinic     = picmEntity();
    $sula       = Covenant::factory()->create(['name' => 'SULAMERICA']);
    $amil       = Covenant::factory()->create(['name' => 'AMIL']);
    $particular = picmParticular();
    $gold       = picmPlan($amil);
    picmMapping($clinic, 'PAS 606', $amil, $gold);
    picmMapping($clinic, 'SULAMERICA', $amil);          // o próprio nome do catálogo pode ser redirecionado
    picmMapping($clinic, 'CORTESIA 179', $particular);
    $resolver = picmResolver($clinic);

    expect($resolver->forRow('pas 606'))->toBe(['covenant_id' => $amil->id, 'mapped_plan_id' => $gold->id, 'is_particular' => false])
        ->and($resolver->forRow('Sul América')['covenant_id'])->toBe($amil->id)
        ->and($resolver->forRow('CORTESIA 179'))->toBe(['covenant_id' => $particular->id, 'mapped_plan_id' => null, 'is_particular' => true])
        ->and($sula->id)->not->toBe($amil->id);
});

it('resolvedor: de-para para convênio removido/inativo ou plano indisponível lança com o motivo', function () {
    $clinic = picmEntity();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $other  = Covenant::factory()->create(['name' => 'BRADESCO']);
    $gone   = Covenant::factory()->create(['name' => 'EXTINTO']);
    $off    = Covenant::factory()->create(['name' => 'DESATIVADO']);
    picmMapping($clinic, 'A', $gone);
    picmMapping($clinic, 'B', $off);
    picmMapping($clinic, 'C', $amil, picmPlan($other));                                  // plano de outro convênio
    picmMapping($clinic, 'D', $amil, picmPlan($amil, 'VELHO', ['active' => false]));     // plano inativo
    picmMapping($clinic, 'E', $amil, picmPlan($amil, 'DA OUTRA', ['entity_id' => picmEntity()->id])); // plano de outra clínica
    $gone->delete();
    $off->update(['active' => false]);
    $resolver = picmResolver($clinic);

    $reasons = fn (string $name) => picmFailure(fn () => $resolver->forRow($name))->reason;

    expect($reasons('A'))->toBe('mapping_covenant_unavailable')
        ->and($reasons('B'))->toBe('mapping_covenant_unavailable')
        ->and($reasons('C'))->toBe('mapping_plan_unavailable')
        ->and($reasons('D'))->toBe('mapping_plan_unavailable')
        ->and($reasons('E'))->toBe('mapping_plan_unavailable');
});

it('resolvedor: o snapshot do import congela o de-para (a tabela é ignorada, inclusive com snapshot vazio)', function () {
    $clinic = picmEntity();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $sula   = Covenant::factory()->create(['name' => 'SULAMERICA']);
    picmMapping($clinic, 'PAS 606', $sula);                       // tabela hoje: SulAmérica

    $frozen = ['pas606' => ['source_name' => 'PAS 606', 'covenant_id' => $amil->id, 'covenant_plan_id' => null]];

    expect(picmResolver($clinic)->forRow('PAS 606')['covenant_id'])->toBe($sula->id)
        ->and(picmResolver($clinic, $frozen)->forRow('PAS 606')['covenant_id'])->toBe($amil->id)
        ->and(picmFailure(fn () => picmResolver($clinic, [])->forRow('PAS 606'))->reason)->toBe('not_found');
});

it('resolvedor: isolamento — de-para e convênio próprio de outra clínica não valem aqui', function () {
    [$a, $b] = [picmEntity(), picmEntity()];
    $global  = Covenant::factory()->create(['name' => 'AMIL']);
    $ownB    = Covenant::factory()->create(['name' => 'CONVENIO DA B', 'entity_id' => $b->id]);
    picmMapping($b, 'PAS 606', $global);
    $resolverA = picmResolver($a);

    expect(picmFailure(fn () => $resolverA->forRow('PAS 606'))->reason)->toBe('not_found')
        ->and(picmFailure(fn () => $resolverA->forRow('Convenio da B'))->reason)->toBe('not_found')
        ->and(picmResolver($b)->forRow('Convenio da B')['covenant_id'])->toBe($ownB->id)
        ->and(picmResolver($b)->forRow('PAS 606')['covenant_id'])->toBe($global->id);
});

it('resolvedor: o convênio da clínica vence o global de mesmo nome; o ativo vence o inativo', function () {
    $clinic = picmEntity();
    Covenant::factory()->create(['name' => 'UNIMED']);
    $own = Covenant::factory()->create(['name' => 'UNIMED', 'entity_id' => $clinic->id]);
    Covenant::factory()->create(['name' => 'FUSEX', 'active' => false]);
    $fusex    = Covenant::factory()->create(['name' => 'FUSEX']);
    $resolver = picmResolver($clinic);

    expect($resolver->forRow('unimed')['covenant_id'])->toBe($own->id)
        ->and($resolver->forRow('Fusex')['covenant_id'])->toBe($fusex->id);
});

it('resolvedor: forSource devolve o status de cada nome e o alvo atual', function () {
    $clinic     = picmEntity();
    $sula       = Covenant::factory()->create(['name' => 'SULAMERICA']);
    $amil       = Covenant::factory()->create(['name' => 'AMIL']);
    $particular = picmParticular();
    Covenant::factory()->create(['name' => 'ASPEB', 'active' => false]);
    $gold = picmPlan($amil);
    picmMapping($clinic, 'PAS 606', $amil, $gold);
    picmMapping($clinic, 'CORTESIA 179', $particular);
    picmMapping($clinic, 'POSTAL', Covenant::factory()->create(['name' => 'SUMIDO']))->covenant->delete();
    $resolver = picmResolver($clinic);

    $item = fn (string $name) => $resolver->forSource($name);

    expect($item('Sul América'))->toMatchArray(['source_name' => 'Sul América', 'source_name_normalized' => 'sulamerica', 'status' => 'found', 'mapping' => null, 'plan' => null])
        ->and($item('Sul América')['covenant'])->toMatchArray(['id' => $sula->id, 'name' => 'SULAMERICA', 'active' => true])
        ->and($item('SEM CADASTRO'))->toMatchArray(['status' => 'unmatched', 'covenant' => null, 'retryable' => true])
        ->and($item('Aspeb'))->toMatchArray(['status' => 'inactive', 'retryable' => true])
        ->and($item('pas 606'))->toMatchArray(['status' => 'mapped', 'retryable' => false])
        ->and($item('pas 606')['covenant']['id'])->toBe($amil->id)
        ->and($item('pas 606')['plan']['id'])->toBe($gold->id)
        ->and($item('pas 606')['mapping'])->toBe(['covenant_id' => $amil->id, 'covenant_plan_id' => $gold->id])
        ->and($item('CORTESIA 179')['status'])->toBe('particular')
        ->and($item('POSTAL'))->toMatchArray(['status' => 'invalid_mapping', 'retryable' => true])
        ->and($item(''))->toMatchArray(['source_name' => '', 'status' => 'particular']);
});

it('resolvedor: snapshot() traz só o de-para dos nomes pedidos, chaveado pela covenantKey', function () {
    $clinic = picmEntity();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $gold   = picmPlan($amil);
    picmMapping($clinic, 'PAS 606', $amil, $gold);
    picmMapping($clinic, 'OUTRO NOME', $amil);

    expect(picmResolver($clinic)->snapshot(['pas 606', 'Sem de-para', '']))
        ->toBe(['pas606' => ['source_name' => 'PAS 606', 'covenant_id' => $amil->id, 'covenant_plan_id' => $gold->id]]);
});

// ── Passo 4: relatório de convênios do import ──────────────────────────────────

it('relatório de import ANTIGO: lê o CSV de erros real, conta por nome e ignora aviso de plano e outros erros', function () {
    Storage::fake();
    picmReportCatalog();
    $clinic = picmEntity();
    $import = picmReportImport($clinic, picmReportCsv());
    picmErrorsFile($import, [
        [2, picmNotFound('PAS 606'), 'PAS 606'],
        [3, picmNotFound('PAS 606'), 'PAS 606'],
        [4, 'Aviso: plano "GOLD" não encontrado no convênio — paciente importado sem plano. Ajuste no cadastro.', 'Sul América'],
        [5, __('validation.custom.covenant.inactive_named', ['name' => 'ASPEB']), 'ASPEB'],
        [6, 'Celular ou telefone obrigatório', ''],
        [7, picmNotFound('pas 606'), 'pas 606'],
    ]);
    $service = app(PatientImportCovenantReportService::class);

    $report = $service->forImport($import->fresh());
    $items  = collect($report['items'])->keyBy('source_name_normalized');

    expect($report['available'])->toBeTrue()
        ->and($report['totals'])->toBe(['file_rows' => 6, 'failed_rows' => 4, 'failed_rows_exact' => true, 'unmatched_names' => 2, 'mapped_names' => 0])
        ->and(array_column($report['items'], 'source_name_normalized'))->toBe(['pas606', 'aspeb', '', 'sulamerica']) // pendentes; depois rows desc e nome (empate)
        ->and($items['pas606'])->toMatchArray(['source_name' => 'PAS 606', 'rows' => 3, 'failed_rows' => 3, 'status' => 'unmatched', 'retryable' => true])
        ->and($items['aspeb'])->toMatchArray(['rows' => 1, 'failed_rows' => 1, 'status' => 'inactive', 'retryable' => true])
        ->and($items['sulamerica'])->toMatchArray(['rows' => 1, 'failed_rows' => 0, 'status' => 'found', 'retryable' => false])
        ->and($items[''])->toMatchArray(['source_name' => '', 'rows' => 1, 'failed_rows' => 0, 'status' => 'particular'])
        ->and($service->retryLineNumbers($import->fresh()))->toBe([2, 3, 5, 7]);
});

it('relatório de import antigo sem o CSV de erros: lista os nomes do original, estima a contagem e não oferece linhas', function () {
    Storage::fake();
    picmReportCatalog();
    $import  = picmReportImport(picmEntity(), picmReportCsv());
    $service = app(PatientImportCovenantReportService::class);

    $report = $service->forImport($import);

    expect($report['available'])->toBeTrue()
        ->and($report['totals'])->toMatchArray(['file_rows' => 6, 'failed_rows' => 4, 'failed_rows_exact' => false, 'unmatched_names' => 2])
        ->and($report['retry_line_count'])->toBe(0)
        ->and($service->retryLineNumbers($import))->toBe([])
        ->and($service->sourceNames($import))->toBe(['PAS 606', 'Sul América', 'ASPEB']);
});

it('relatório sem arquivos (nenhum, expirado ou limpo): indisponível, sem quebrar', function () {
    Storage::fake();
    $clinic  = picmEntity();
    $service = app(PatientImportCovenantReportService::class);

    $missing = picmImport($clinic, ['status' => ImportStatus::Done]); // caminhos que não existem no disco
    $expired = picmReportImport($clinic, picmReportCsv(), ['files_expire_at' => now()->subMinute()]);
    $purged  = picmReportImport($clinic, picmReportCsv(), ['files_purged_at' => now()]);

    foreach ([$missing, $expired, $purged] as $import) {
        $report = $service->forImport($import);

        expect($report['available'])->toBeFalse()
            ->and($report['items'])->toBe([])
            ->and($service->retryLineNumbers($import))->toBe([])
            ->and($service->sourceNames($import))->toBe([]);
    }
});

it('relatório de import NOVO: usa covenant_error_lines e o resumo gravado; o status acompanha o de-para atual', function () {
    Storage::fake();
    picmReportCatalog();
    $clinic  = picmEntity();
    $amil    = Covenant::factory()->create(['name' => 'AMIL']);
    $summary = ['items' => [
        ['source_name' => 'PAS 606', 'source_name_normalized' => 'pas606', 'rows' => 3, 'failed_rows' => 3],
        ['source_name' => 'Sul América', 'source_name_normalized' => 'sulamerica', 'rows' => 1, 'failed_rows' => 0],
    ], 'totals' => ['file_rows' => 6], 'truncated' => false];
    $import  = picmReportImport($clinic, picmReportCsv(), ['covenant_error_lines' => [2, 3, 7], 'preview' => ['covenant_summary' => $summary]]);
    $service = app(PatientImportCovenantReportService::class);

    expect($service->forImport($import)['items'][0])->toMatchArray(['source_name_normalized' => 'pas606', 'status' => 'unmatched', 'failed_rows' => 3]);

    picmMapping($clinic, 'PAS 606', $amil);
    $report = $service->forImport($import->fresh());

    expect($report['items'][0])->toMatchArray(['source_name_normalized' => 'pas606', 'status' => 'mapped', 'failed_rows' => 3, 'retryable' => true])
        ->and($report['totals'])->toMatchArray(['failed_rows' => 3, 'failed_rows_exact' => true, 'unmatched_names' => 0, 'mapped_names' => 1])
        ->and($service->retryLineNumbers($import->fresh()))->toBe([2, 3, 7]);
});

it('relatório com resumo truncado completa os nomes pelo arquivo original e conta as falhas pelo CSV de erros nas linhas certas', function () {
    Storage::fake();
    picmReportCatalog();
    $import = picmReportImport(picmEntity(), picmReportCsv(), [
        'covenant_error_lines' => [2, 3],
        'preview'              => ['covenant_summary' => ['items' => [['source_name' => 'PAS 606', 'source_name_normalized' => 'pas606', 'rows' => 3]], 'truncated' => true]],
    ]);
    picmErrorsFile($import, [[2, 'qualquer texto novo', 'PAS 606'], [3, 'outro texto', 'PAS 606'], [6, 'Celular ou telefone obrigatório', '']]);

    $report = app(PatientImportCovenantReportService::class)->forImport($import->fresh());
    $items  = collect($report['items'])->keyBy('source_name_normalized');

    expect($items->keys()->sort()->values()->all())->toBe(['', 'aspeb', 'pas606', 'sulamerica'])
        ->and($items['pas606'])->toMatchArray(['rows' => 3, 'failed_rows' => 2])
        ->and($report['totals'])->toMatchArray(['failed_rows' => 2, 'failed_rows_exact' => true]);
});

it('o relatório não carrega dado de paciente: só nomes de convênio, contagens e alvos do catálogo', function () {
    Storage::fake();
    picmReportCatalog();
    $import = picmReportImport(picmEntity(), picmReportCsv());
    picmErrorsFile($import, [[2, picmNotFound('PAS 606'), 'PAS 606']]);

    $json = json_encode(app(PatientImportCovenantReportService::class)->forImport($import->fresh()), JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('SIGILOSO')
        ->and($json)->not->toContain('1199990')
        ->and($json)->not->toContain('Ana')
        ->and($json)->not->toContain('11911110001');
});

// ── Passo 5: serviço do de-para ────────────────────────────────────────────────

/** Clínica + admin + import PRÉ-confirmação cujo arquivo cita PAS 606, SULAMERICA 537 e CORTESIA 179. */
function picmMappingWorld(string $rule = 'admin'): object
{
    Storage::fake();
    $clinic = picmEntity();
    $user   = User::factory()->create();
    createEntityUser($clinic, $user, $rule);
    $import = picmReportImport($clinic, "nome;celular;convenio\nAna;11911110001;PAS 606\nBia;11911110002;SULAMERICA 537\nCaio;11911110003;CORTESIA 179\nDani;11911110004;Pas 606\n", [
        'status' => ImportStatus::Pending, 'user_id' => $user->id,
    ]);

    return (object) ['clinic' => $clinic, 'user' => $user, 'import' => $import, 'service' => app(PatientImportCovenantMappingService::class)];
}

it('serviço: map grava o de-para por clínica (nome do arquivo, chave compacta, plano opcional) e atualiza o existente', function () {
    $w    = picmMappingWorld();
    $amil = Covenant::factory()->create(['name' => 'AMIL']);
    $sula = Covenant::factory()->create(['name' => 'SULAMERICA']);
    $gold = picmPlan($amil);

    $meta = $w->service->save($w->import, [['source_name' => 'pas 606', 'action' => 'map', 'covenant_id' => $amil->id, 'covenant_plan_id' => $gold->id]], $w->user);

    expect($meta)->toBe(['saved' => 1, 'created_covenants' => 0, 'reused_covenants' => 0, 'cleared' => 0])
        ->and(picmMappings($w->clinic))->toBe(['pas606' => ['PAS 606', $amil->id, $gold->id]]); // grafia do ARQUIVO, não a do request

    $w->service->save($w->import, [['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $sula->id]], $w->user);

    expect(picmMappings($w->clinic))->toBe(['pas606' => ['PAS 606', $sula->id, null]])
        ->and(picmResolver($w->clinic)->forSource('PAS 606')['status'])->toBe('mapped');
});

it('serviço: create cria o convênio da clínica (ativo, MAIÚSCULO, cor única), mapeia e reutiliza na segunda vez', function () {
    $w = picmMappingWorld();
    Covenant::factory()->create(['name' => 'EXISTENTE', 'entity_id' => $w->clinic->id, 'color' => '#3699FF']);

    $meta = $w->service->save($w->import, [
        ['source_name' => 'SULAMERICA 537', 'action' => 'create'],
        ['source_name' => 'CORTESIA 179', 'action' => 'create'],
    ], $w->user);

    $created = Covenant::withoutGlobalScopes()->where('entity_id', $w->clinic->id)->whereIn('name', ['SULAMERICA 537', 'CORTESIA 179'])->get()->keyBy('name');
    $colors  = Covenant::withoutGlobalScopes()->where('entity_id', $w->clinic->id)->pluck('color')->map(fn ($c) => strtoupper($c));

    expect($meta)->toBe(['saved' => 2, 'created_covenants' => 2, 'reused_covenants' => 0, 'cleared' => 0])
        ->and($created)->toHaveCount(2)
        ->and($created['SULAMERICA 537'])->toMatchArray(['active' => true, 'table' => false])
        ->and($created['SULAMERICA 537']->source->value)->toBe('manual')
        ->and($created['SULAMERICA 537']->color)->toMatch('/^#[0-9A-F]{6}$/i')
        ->and($colors->unique())->toHaveCount(3) // EXISTENTE + 2 novos, nenhuma repetida
        ->and(picmMappings($w->clinic)['sulamerica537'][1])->toBe($created['SULAMERICA 537']->id);

    $again = $w->service->save($w->import, [['source_name' => 'SULAMERICA 537', 'action' => 'create']], $w->user);

    expect($again)->toBe(['saved' => 1, 'created_covenants' => 0, 'reused_covenants' => 1, 'cleared' => 0])
        ->and(Covenant::withoutGlobalScopes()->where('entity_id', $w->clinic->id)->where('name', 'SULAMERICA 537')->count())->toBe(1);
});

it('serviço: create reutiliza o global ativo, reativa o inativo da clínica e restaura o excluído da clínica', function () {
    $w      = picmMappingWorld();
    $global = Covenant::factory()->create(['name' => 'PAS 606']);
    $off    = Covenant::factory()->create(['name' => 'SULAMERICA 537', 'entity_id' => $w->clinic->id, 'active' => false]);
    $gone   = Covenant::factory()->create(['name' => 'CORTESIA 179', 'entity_id' => $w->clinic->id]);
    $gone->delete();

    $meta = $w->service->save($w->import, [
        ['source_name' => 'PAS 606', 'action' => 'create'],
        ['source_name' => 'SULAMERICA 537', 'action' => 'create'],
        ['source_name' => 'CORTESIA 179', 'action' => 'create'],
    ], $w->user);

    expect($meta)->toBe(['saved' => 3, 'created_covenants' => 0, 'reused_covenants' => 3, 'cleared' => 0])
        ->and($off->fresh()->active)->toBeTrue()
        ->and($gone->fresh()->trashed())->toBeFalse()
        ->and($gone->fresh()->active)->toBeTrue()
        ->and(array_column(picmMappings($w->clinic), 1))->toEqualCanonicalizing([$global->id, $off->id, $gone->id])
        ->and(Covenant::withoutGlobalScopes()->where('entity_id', $w->clinic->id)->count())->toBe(2);
});

it('serviço: create exige settings.manage (secretária recebe 403 e NADA é gravado); admin cria', function () {
    $w    = picmMappingWorld('secretary');
    $amil = Covenant::factory()->create(['name' => 'AMIL']);

    expect(fn () => $w->service->save($w->import, [
        ['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id],
        ['source_name' => 'SULAMERICA 537', 'action' => 'create'],
    ], $w->user))->toThrow(AuthorizationException::class);

    expect(picmMappings($w->clinic))->toBe([])
        ->and(Covenant::withoutGlobalScopes()->where('entity_id', $w->clinic->id)->count())->toBe(0);

    // Sem create, a secretária mapeia normalmente (mapear/Particular não exigem settings.manage).
    expect($w->service->save($w->import, [['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id]], $w->user)['saved'])->toBe(1);
});

it('serviço: particular grava o de-para para o Particular; clear remove; clear sem de-para não conta', function () {
    $w = picmMappingWorld();

    $meta = $w->service->save($w->import, [['source_name' => 'CORTESIA 179', 'action' => 'particular']], $w->user);

    expect($meta['saved'])->toBe(1)
        ->and(picmMappings($w->clinic)['cortesia179'][1])->toBe(picmParticular()->id)
        ->and(picmResolver($w->clinic)->forRow('CORTESIA 179')['is_particular'])->toBeTrue();

    $w->service->save($w->import, [['source_name' => 'PAS 606', 'action' => 'clear']], $w->user);

    expect($w->service->save($w->import, [['source_name' => 'cortesia 179', 'action' => 'clear']], $w->user))
        ->toBe(['saved' => 0, 'created_covenants' => 0, 'reused_covenants' => 0, 'cleared' => 1])
        ->and(picmMappings($w->clinic))->toBe([])
        ->and(picmResolver($w->clinic)->forSource('CORTESIA 179')['status'])->toBe('unmatched');
});

it('serviço: validação por linha em tudo-ou-nada — convênio/plano indisponível, nome fora do arquivo, ação inválida, duplicidade', function () {
    $w      = picmMappingWorld();
    $other  = picmEntity();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $bra    = Covenant::factory()->create(['name' => 'BRADESCO']);
    $off    = Covenant::factory()->create(['name' => 'DESATIVADO', 'active' => false]);
    $ownB   = Covenant::factory()->create(['name' => 'DA OUTRA', 'entity_id' => $other->id]);
    $okPlan = picmPlan($amil);

    $rows = [
        ['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id, 'covenant_plan_id' => $okPlan->id], // 0 ok
        ['source_name' => 'SULAMERICA 537', 'action' => 'map', 'covenant_id' => $ownB->id],                              // 1 convênio de outra clínica
        ['source_name' => 'CORTESIA 179', 'action' => 'map', 'covenant_id' => $off->id],                                 // 2 inativo
        ['source_name' => 'NAO ESTA NO ARQUIVO', 'action' => 'map', 'covenant_id' => $amil->id],                         // 3 fora do arquivo
        ['source_name' => 'Pas 606', 'action' => 'clear'],                                                              // 4 duplicado de 0
        ['source_name' => 'X', 'action' => 'explodir'],                                                                  // 5 ação inválida
    ];

    $errors = picmErrors(fn () => $w->service->save($w->import, $rows, $w->user));

    expect(array_keys($errors))->toEqualCanonicalizing(['mappings.1.covenant_id', 'mappings.2.covenant_id', 'mappings.3.source_name', 'mappings.4.source_name', 'mappings.5.action'])
        ->and(picmMappings($w->clinic))->toBe([]); // a linha 0, válida, também não foi gravada

    $planErrors = [
        'outro convênio' => picmPlan($bra),
        'inativo'        => picmPlan($amil, 'VELHO', ['active' => false]),
        'outra clínica'  => picmPlan($amil, 'DA OUTRA', ['entity_id' => $other->id]),
    ];

    foreach ($planErrors as $plan) {
        $keys = array_keys(picmErrors(fn () => $w->service->save($w->import, [['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id, 'covenant_plan_id' => $plan->id]], $w->user)));
        expect($keys)->toBe(['mappings.0.covenant_plan_id']);
    }

    // create/particular/clear não aceitam convênio nem plano; map exige convênio.
    $keys = array_keys(picmErrors(fn () => $w->service->save($w->import, [
        ['source_name' => 'PAS 606', 'action' => 'create', 'covenant_id' => $amil->id],
        ['source_name' => 'SULAMERICA 537', 'action' => 'particular', 'covenant_plan_id' => $okPlan->id],
        ['source_name' => 'CORTESIA 179', 'action' => 'map'],
    ], $w->user)));

    expect($keys)->toEqualCanonicalizing(['mappings.0.covenant_id', 'mappings.1.covenant_plan_id', 'mappings.2.covenant_id'])
        ->and(picmMappings($w->clinic))->toBe([]);
});

it('serviço: com import indisponível (arquivos expirados) nenhum nome é válido', function () {
    $w = picmMappingWorld();
    $w->import->update(['files_expire_at' => now()->subMinute()]);

    expect(array_keys(picmErrors(fn () => $w->service->save($w->import->fresh(), [['source_name' => 'PAS 606', 'action' => 'particular']], $w->user))))->toBe(['mappings.0.source_name']);
});

it('serviço: isolamento — gravar para a clínica A não toca o de-para da B', function () {
    $w    = picmMappingWorld();
    $b    = picmEntity();
    $amil = Covenant::factory()->create(['name' => 'AMIL']);
    $bra  = Covenant::factory()->create(['name' => 'BRADESCO']);
    picmMapping($b, 'PAS 606', $bra);

    $w->service->save($w->import, [['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id]], $w->user);
    $w->service->save($w->import, [['source_name' => 'PAS 606', 'action' => 'clear']], $w->user);

    expect(picmMappings($b))->toBe(['pas606' => ['PAS 606', $bra->id, null]])
        ->and(picmMappings($w->clinic))->toBe([]);
});

it('serviço: antes do confirm atualiza o snapshot e o resumo da prévia; depois do confirm não mexe no snapshot em uso', function () {
    $w    = picmMappingWorld();
    $amil = Covenant::factory()->create(['name' => 'AMIL']);

    $w->service->save($w->import, [['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id]], $w->user);
    $import = $w->import->fresh();
    $items  = collect($import->preview['covenant_summary']['items'])->keyBy('source_name_normalized');

    expect($import->covenant_mapping_snapshot)->toBe(['pas606' => ['source_name' => 'PAS 606', 'covenant_id' => $amil->id, 'covenant_plan_id' => null]])
        ->and($items['pas606'])->toMatchArray(['status' => 'mapped', 'rows' => 2])
        ->and($items['sulamerica537']['status'])->toBe('unmatched')
        ->and($import->preview['covenant_summary']['totals'])->toMatchArray(['file_rows' => 4, 'unmatched_names' => 2, 'mapped_names' => 1])
        ->and($import->preview['covenant_summary']['truncated'])->toBeFalse();

    $import->update(['confirmed_at' => now(), 'status' => ImportStatus::Processing]);
    $before = $import->fresh()->covenant_mapping_snapshot;

    $w->service->save($import->fresh(), [['source_name' => 'SULAMERICA 537', 'action' => 'particular']], $w->user);

    expect($import->fresh()->covenant_mapping_snapshot)->toBe($before)
        ->and(picmMappings($w->clinic))->toHaveKeys(['pas606', 'sulamerica537']); // o de-para novo vale para as próximas importações
});

// ── Passo 5: busca de convênios (GET covenants/search) ────────────────────────

it('busca de convênios: próprios + globais ativos; sem inativos, excluídos nem de outra clínica; próprios primeiro', function () {
    $clinic = picmPanelClinic();
    $other  = picmPanelClinic();
    $own    = Covenant::factory()->create(['name' => 'UNIMED DA CLINICA', 'entity_id' => $clinic->id]);
    $global = Covenant::factory()->create(['name' => 'UNIMED NACIONAL']);
    Covenant::factory()->create(['name' => 'UNIMED INATIVA', 'active' => false]);
    Covenant::factory()->create(['name' => 'UNIMED DA OUTRA', 'entity_id' => $other->id]);
    Covenant::factory()->create(['name' => 'UNIMED EXCLUIDA', 'entity_id' => $clinic->id])->delete();

    $data = picmAs($clinic)->getJson(route('panel.covenants.search', ['q' => 'unímed']))->assertOk()->json('data'); // sem acento/caixa

    expect(array_column($data, 'name'))->toBe(['UNIMED DA CLINICA', 'UNIMED NACIONAL'])
        ->and($data[0])->toBe(['id' => $own->id, 'label' => 'UNIMED DA CLINICA', 'sub_label' => $own->code, 'name' => 'UNIMED DA CLINICA', 'code' => $own->code, 'is_own' => true, 'active' => true])
        ->and($data[1]['is_own'])->toBeFalse()
        ->and($data[1]['id'])->toBe($global->id);
});

it('busca de convênios: sem termo devolve os 30 primeiros; 3+ dígitos casam o código e o registro ANS; termo longo é 422', function () {
    $clinic = picmPanelClinic();
    Covenant::factory()->count(35)->sequence(fn ($s) => ['name' => sprintf('OPERADORA %02d', $s->index)])->create();
    Covenant::factory()->create(['name' => 'AMIL', 'ans_registry' => '326305']);

    expect(picmAs($clinic)->getJson(route('panel.covenants.search'))->assertOk()->json('data'))->toHaveCount(30);

    $byRegistry = picmAs($clinic)->getJson(route('panel.covenants.search', ['q' => '3263']))->assertOk()->json('data');
    expect(array_column($byRegistry, 'name'))->toBe(['AMIL']);

    picmAs($clinic)->getJson(route('panel.covenants.search', ['q' => str_repeat('a', 101)]))->assertStatus(422);
});

it('busca de convênios: exige o grupo de pacientes; usuário sem papel na clínica não entra', function () {
    $clinic = picmPanelClinic();

    picmAs($clinic, 'user')->getJson(route('panel.covenants.search'))->assertForbidden();
});

// ── Passo 6: endpoints de convênios do import (GET/PUT/POST) ───────────────────

/** Import antigo concluído (CSV original + CSV de erros no formato real) de uma clínica do painel. */
function picmHttpWorld(): object
{
    Storage::fake();
    picmReportCatalog();
    $clinic = picmPanelClinic();
    $import = picmReportImport($clinic, picmReportCsv());
    picmErrorsFile($import, [
        [2, picmNotFound('PAS 606'), 'PAS 606'],
        [3, picmNotFound('PAS 606'), 'PAS 606'],
        [5, __('validation.custom.covenant.inactive_named', ['name' => 'ASPEB']), 'ASPEB'],
        [7, picmNotFound('pas 606'), 'pas 606'],
    ]);

    return (object) ['clinic' => $clinic, 'import' => $import->fresh()];
}

it('GET covenants: relatório sem dado de paciente, com permissões e o contrato do plano', function () {
    $w = picmHttpWorld();

    $response = picmAs($w->clinic)->getJson(route('panel.patients.import.covenants.show', $w->import))->assertOk();
    $data     = $response->json('data');

    expect($data['import'])->toMatchArray(['id' => $w->import->id, 'original_name' => 'arquivo.csv', 'status' => 'done', 'is_reprocess' => false, 'reprocess_of_id' => null, 'files_expired' => false, 'report_available' => true])
        ->and($data['import']['files_expire_at'])->toBe($w->import->files_expire_at->toIso8601String())
        ->and($data['permissions'])->toBe(['can_create_covenant' => true, 'can_reprocess' => true])
        ->and($data['totals'])->toBe(['file_rows' => 6, 'failed_rows' => 4, 'failed_rows_exact' => true, 'unmatched_names' => 2, 'mapped_names' => 0])
        ->and(array_column($data['items'], 'source_name_normalized'))->toBe(['pas606', 'aspeb', '', 'sulamerica'])
        ->and($data['items'][0])->toMatchArray(['source_name' => 'PAS 606', 'rows' => 3, 'failed_rows' => 3, 'status' => 'unmatched', 'covenant' => null, 'plan' => null, 'mapping' => null, 'retryable' => true])
        ->and($response->getContent())->not->toContain('SIGILOSO')->not->toContain('1199990');
});

it('GET covenants: sem settings.manage não pode criar convênio; sem arquivos o relatório é indisponível e não reprocessa', function () {
    $w = picmHttpWorld();

    expect(picmAs($w->clinic, 'secretary')->getJson(route('panel.patients.import.covenants.show', $w->import))->assertOk()->json('data.permissions'))
        ->toBe(['can_create_covenant' => false, 'can_reprocess' => true]);

    $gone = picmImport($w->clinic, ['status' => ImportStatus::Done]); // arquivos inexistentes
    $data = picmAs($w->clinic)->getJson(route('panel.patients.import.covenants.show', $gone))->assertOk()->json('data');

    expect($data['import']['report_available'])->toBeFalse()
        ->and($data['items'])->toBe([])
        ->and($data['permissions']['can_reprocess'])->toBeFalse();

    $w->import->update(['files_expire_at' => now()->subMinute()]);
    $expired = picmAs($w->clinic)->getJson(route('panel.patients.import.covenants.show', $w->import))->assertOk()->json('data');

    expect($expired['import'])->toMatchArray(['files_expired' => true, 'report_available' => false])
        ->and($expired['permissions']['can_reprocess'])->toBeFalse();
});

it('GET/PUT/POST covenants: import de outra clínica é 404 e papel sem acesso a pacientes é 403', function () {
    $w     = picmHttpWorld();
    $other = picmPanelClinic();

    picmAs($other)->getJson(route('panel.patients.import.covenants.show', $w->import))->assertNotFound();
    picmAs($other)->putJson(route('panel.patients.import.covenants.update', $w->import), ['mappings' => [['source_name' => 'PAS 606', 'action' => 'particular']]])->assertNotFound();
    picmAs($other)->postJson(route('panel.patients.import.covenants.reprocess', $w->import))->assertNotFound();
    picmAs($w->clinic, 'user')->getJson(route('panel.patients.import.covenants.show', $w->import))->assertForbidden();
    expect(picmMappings($other))->toBe([])->and(picmMappings($w->clinic))->toBe([]);
});

it('PUT covenants: grava o de-para, devolve o relatório atualizado + meta; o mesmo nome em outra importação já vem mapeado', function () {
    $w    = picmHttpWorld();
    $amil = Covenant::factory()->create(['name' => 'AMIL']);
    $gold = picmPlan($amil);

    $response = picmAs($w->clinic)->putJson(route('panel.patients.import.covenants.update', $w->import), ['mappings' => [
        ['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id, 'covenant_plan_id' => $gold->id],
        ['source_name' => 'ASPEB', 'action' => 'particular'],
    ]])->assertOk();

    $items = collect($response->json('data.items'))->keyBy('source_name_normalized');

    expect($response->json('meta'))->toBe(['saved' => 2, 'created_covenants' => 0, 'reused_covenants' => 0, 'cleared' => 0])
        ->and($items['pas606'])->toMatchArray(['status' => 'mapped', 'failed_rows' => 3, 'retryable' => true])
        ->and($items['pas606']['covenant']['id'])->toBe($amil->id)
        ->and($items['pas606']['plan']['id'])->toBe($gold->id)
        ->and($items['aspeb']['status'])->toBe('particular')
        ->and($response->json('data.totals'))->toMatchArray(['unmatched_names' => 0, 'mapped_names' => 2])
        ->and(picmMappings($w->clinic))->toHaveKeys(['pas606', 'aspeb']);

    // Outra importação da mesma clínica com o mesmo nome: o de-para já vale (prévia/relatório).
    $next = picmReportImport($w->clinic, "nome;celular;convenio\nZe;11911110009;pas-606\n", ['status' => ImportStatus::Pending]);

    expect(picmAs($w->clinic)->getJson(route('panel.patients.import.covenants.show', $next))->json('data.items.0'))->toMatchArray(['source_name_normalized' => 'pas606', 'status' => 'mapped']);
});

it('PUT covenants: 422 com erros por linha e nada gravado; create sem settings.manage é 403; corpo vazio é 422', function () {
    $w    = picmHttpWorld();
    $amil = Covenant::factory()->create(['name' => 'AMIL']);
    $url  = route('panel.patients.import.covenants.update', $w->import);

    picmAs($w->clinic)->putJson($url, ['mappings' => [
        ['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id],
        ['source_name' => 'NAO ESTA NO ARQUIVO', 'action' => 'particular'],
        ['source_name' => 'ASPEB', 'action' => 'map'],
    ]])->assertStatus(422)->assertJsonValidationErrors(['mappings.1.source_name', 'mappings.2.covenant_id'])->assertJsonMissingValidationErrors(['mappings.0.source_name', 'mappings.0.covenant_id']);

    picmAs($w->clinic)->putJson($url, ['mappings' => [['source_name' => 'PAS 606', 'action' => 'explodir']]])->assertStatus(422)->assertJsonValidationErrors(['mappings.0.action']);
    picmAs($w->clinic)->putJson($url, ['mappings' => []])->assertStatus(422)->assertJsonValidationErrors(['mappings']);
    picmAs($w->clinic)->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['mappings']);
    picmAs($w->clinic, 'secretary')->putJson($url, ['mappings' => [['source_name' => 'PAS 606', 'action' => 'create']]])->assertForbidden();

    expect(picmMappings($w->clinic))->toBe([])
        ->and(Covenant::withoutGlobalScopes()->where('entity_id', $w->clinic->id)->count())->toBe(0);

    // Admin cria; o convênio e o de-para ficam na clínica.
    picmAs($w->clinic)->putJson($url, ['mappings' => [['source_name' => 'PAS 606', 'action' => 'create']]])->assertOk()->assertJsonPath('meta.created_covenants', 1);
    expect(Covenant::withoutGlobalScopes()->where('entity_id', $w->clinic->id)->value('name'))->toBe('PAS 606');
});

it('POST reprocess: cria o import filho só com as linhas de convênio, copia o arquivo e enfileira; o pai não muda', function () {
    $w    = picmHttpWorld();
    $amil = Covenant::factory()->create(['name' => 'AMIL']);
    picmMapping($w->clinic, 'PAS 606', $amil);
    Queue::fake();

    $response = picmAs($w->clinic)->postJson(route('panel.patients.import.covenants.reprocess', $w->import))->assertStatus(202);
    $child    = PatientImport::withoutGlobalScopes()->where('reprocess_of_id', $w->import->id)->sole();

    expect($response->json('data'))->toBe(['import_id' => $child->id, 'status' => 'pending', 'total_rows' => 4, 'channel' => 'imports.patients.' . $child->id])
        ->and($child->reprocess_lines)->toBe([2, 3, 5, 7])
        ->and($child->total_rows)->toBe(4)
        ->and($child->status)->toBe(ImportStatus::Pending)
        ->and($child->confirmed_at)->not->toBeNull()
        ->and($child->entity_id)->toBe($w->clinic->id)
        ->and($child->original_name)->toBe('arquivo.csv')
        ->and($child->file_path)->not->toBe($w->import->file_path)
        ->and(Storage::disk()->get($child->file_path))->toBe(Storage::disk()->get($w->import->file_path))
        ->and($child->files_expire_at->isFuture())->toBeTrue()
        ->and($child->covenant_mapping_snapshot)->toHaveKey('pas606')
        ->and($child->preview['reprocess'])->toBe(['parent_id' => $w->import->id, 'lines' => 4])
        ->and(collect($child->preview['covenant_summary']['items'])->pluck('source_name_normalized')->all())->toEqualCanonicalizing(['pas606', 'aspeb'])
        ->and($w->import->fresh()->only(['status', 'processed_rows', 'imported_rows', 'file_path', 'errors_file_path']))->toBe($w->import->only(['status', 'processed_rows', 'imported_rows', 'file_path', 'errors_file_path']));

    Queue::assertPushedOn('bulk', ProcessPatientImportJob::class, fn (ProcessPatientImportJob $job) => $job->import->is($child));
});

it('POST reprocess: segundo pedido enquanto o primeiro está ativo é 409; import ativo na clínica também', function () {
    $w = picmHttpWorld();
    Queue::fake();
    $url = route('panel.patients.import.covenants.reprocess', $w->import);

    picmAs($w->clinic)->postJson($url)->assertStatus(202);
    picmAs($w->clinic)->postJson($url)->assertStatus(409);

    expect(PatientImport::withoutGlobalScopes()->where('reprocess_of_id', $w->import->id)->count())->toBe(1);

    // Sem o filho ativo, um upload pendente da clínica também bloqueia (um import ativo por vez).
    PatientImport::withoutGlobalScopes()->where('reprocess_of_id', $w->import->id)->update(['status' => ImportStatus::Done]);
    $pending = picmImport($w->clinic, ['status' => ImportStatus::Pending]);

    picmAs($w->clinic)->postJson($url)->assertStatus(409);

    $pending->update(['status' => ImportStatus::Cancelled]);
    picmAs($w->clinic)->postJson($url)->assertStatus(202);
});

it('POST reprocess: 422 quando não terminou, sem linhas de convênio, arquivo expirado/ausente ou relatório indisponível', function () {
    $w = picmHttpWorld();
    Queue::fake();
    $post = fn (PatientImport $import) => picmAs($w->clinic)->postJson(route('panel.patients.import.covenants.reprocess', $import));

    $post(picmReportImport($w->clinic, picmReportCsv(), ['status' => ImportStatus::Processing]))->assertStatus(422);            // não terminou
    $post(picmReportImport($w->clinic, picmReportCsv(), ['covenant_error_lines' => []]))->assertStatus(422);                     // novo, sem erro de convênio
    $post(picmReportImport($w->clinic, picmReportCsv()))->assertStatus(422);                                                    // antigo sem CSV de erros
    $post(picmReportImport($w->clinic, picmReportCsv(), ['covenant_error_lines' => [2], 'files_expire_at' => now()->subMinute()]))->assertStatus(422); // expirado
    $post(picmReportImport($w->clinic, picmReportCsv(), ['covenant_error_lines' => [2], 'files_purged_at' => now()]))->assertStatus(422);              // limpo
    $post(picmImport($w->clinic, ['status' => ImportStatus::Done, 'covenant_error_lines' => [2]]))->assertStatus(422);            // original sumiu do disco

    expect(PatientImport::withoutGlobalScopes()->whereNotNull('reprocess_of_id')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('POST reprocess: se o dispatch falhar o filho vira failed, mantém o arquivo e não vaza SQL/PHI', function () {
    $w = picmHttpWorld();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('fila indisponível'));

    picmAs($w->clinic)->postJson(route('panel.patients.import.covenants.reprocess', $w->import))->assertStatus(500);

    $child = PatientImport::withoutGlobalScopes()->where('reprocess_of_id', $w->import->id)->sole();

    expect($child->status)->toBe(ImportStatus::Failed)
        ->and($child->abort_reason)->toBe(__('shared_identity.import.failed'))
        ->and(Storage::disk()->exists($child->file_path))->toBeTrue();
});

// ── Passo 7: prévia e processamento com o de-para ──────────────────────────────

function picmPreviewCsv(): string
{
    return "nome;celular;convenio\nAna;11911110001;SulAmérica\nBia;11911110002;SULAMERICA\nCaio;11911110003;Sul América\n"
        . "Dani;11911110004;PAS 606\nEdu;11911110005;PAS 606\nFabi;11911110006;ASPEB\nGabi;11911110007;\n";
}

it('prévia: convênios únicos com linhas e situação (agrupa as grafias), "sem convênio" como Particular, pendentes primeiro', function () {
    Storage::fake();
    picmReportCatalog();
    $import = picmReportImport(picmClinic(), picmPreviewCsv(), ['status' => ImportStatus::Pending]);

    $preview = app(PatientImportService::class)->generatePreview($import);
    $summary = $preview['covenant_summary'];
    $items   = collect($summary['items'])->keyBy('source_name_normalized');

    expect($preview['total_rows'])->toBe(7)
        ->and($preview['can_proceed'])->toBeTrue()
        ->and(array_column($summary['items'], 'source_name_normalized'))->toBe(['pas606', 'aspeb', 'sulamerica', ''])
        ->and($items['sulamerica'])->toMatchArray(['source_name' => 'SulAmérica', 'rows' => 3, 'failed_rows' => 0, 'status' => 'found'])
        ->and($items['pas606'])->toMatchArray(['rows' => 2, 'failed_rows' => 2, 'status' => 'unmatched', 'retryable' => true])
        ->and($items['aspeb'])->toMatchArray(['rows' => 1, 'status' => 'inactive'])
        ->and($items[''])->toMatchArray(['source_name' => '', 'rows' => 1, 'status' => 'particular'])
        ->and($summary['totals'])->toBe(['file_rows' => 7, 'unmatched_names' => 2, 'mapped_names' => 0])
        ->and($summary['truncated'])->toBeFalse();
});

it('prévia: o de-para da clínica já aparece (mapped, com o alvo) e o resumo é limitado a N itens com truncated', function () {
    Storage::fake();
    picmReportCatalog();
    $clinic = picmClinic();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    picmMapping($clinic, 'pas 606', $amil);
    $import = picmReportImport($clinic, picmPreviewCsv(), ['status' => ImportStatus::Pending]);

    $items = collect(app(PatientImportService::class)->generatePreview($import)['covenant_summary']['items'])->keyBy('source_name_normalized');

    expect($items['pas606'])->toMatchArray(['status' => 'mapped', 'failed_rows' => 0])
        ->and($items['pas606']['covenant']['id'])->toBe($amil->id);

    config(['patient_imports.max_covenant_summary_items' => 2]);
    $summary = app(PatientImportService::class)->generatePreview($import)['covenant_summary'];

    expect($summary['items'])->toHaveCount(2)
        ->and($summary['truncated'])->toBeTrue()
        ->and($summary['totals']['file_rows'])->toBe(7);
});

it('prévia: se o catálogo não carregar, a prévia não quebra — itens como não encontrados e um aviso', function () {
    Storage::fake();
    $this->mock(PatientImportCovenantResolver::class)->shouldReceive('load')->andThrow(new RuntimeException('catálogo fora do ar'));
    $import = picmReportImport(picmClinic(), picmPreviewCsv(), ['status' => ImportStatus::Pending]);

    $preview = app(PatientImportService::class)->generatePreview($import);

    expect($preview['can_proceed'])->toBeTrue()
        ->and($preview['covenant_warning'])->toBe('catalog_unavailable')
        ->and(collect($preview['covenant_summary']['items'])->where('source_name', '!=', '')->pluck('status')->unique()->all())->toBe(['unmatched']);
});

it('processamento: o de-para vale; o plano mapeado prevalece sobre a coluna da planilha; sem plano mapeado a planilha decide (com aviso)', function () {
    Storage::fake();
    $clinic = picmClinic();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $gold   = picmPlan($amil, 'GOLD');
    picmPlan($amil, 'PRATA');
    picmMapping($clinic, 'PAS 606', $amil, $gold);
    picmMapping($clinic, 'POSTAL', $amil);

    $import = picmProcess($clinic, "nome;celular;convenio;plano;carteirinha\n"
        . "Ana Lima;11911110001;PAS 606;PRATA;C-1\n"          // plano mapeado (GOLD) vence a coluna (PRATA)
        . "Bia Lima;11911110002;Postal;PRATA;C-2\n"            // sem plano mapeado: a planilha decide
        . "Caio Lima;11911110003;Postal;INEXISTENTE;C-3\n");   // sem plano mapeado e plano inválido: aviso

    $patient = fn (string $name) => Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->whereHas('person', fn ($q) => $q->where('full_name', $name))->sole();

    expect($import->imported_rows)->toBe(3)
        ->and($import->error_rows)->toBe(0)
        ->and($import->warning_rows)->toBe(1)
        ->and($patient('ANA LIMA')->only(['covenant_id', 'covenant_plan_id', 'card_number']))->toBe(['covenant_id' => $amil->id, 'covenant_plan_id' => $gold->id, 'card_number' => 'C-1'])
        ->and($patient('BIA LIMA')->covenant_plan_id)->toBe(CovenantPlan::withoutGlobalScopes()->where('name', 'PRATA')->value('id'))
        ->and($patient('CAIO LIMA')->covenant_plan_id)->toBeNull();
});

it('processamento: de-para para o Particular importa sem plano e sem carteirinha', function () {
    Storage::fake();
    $clinic = picmClinic();
    picmMapping($clinic, 'CORTESIA 179', picmParticular());

    $import  = picmProcess($clinic, "nome;celular;convenio;plano;carteirinha\nAna Lima;11911110001;CORTESIA 179;QUALQUER;C-9\n");
    $patient = Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->sole();

    expect($import->imported_rows)->toBe(1)
        ->and($patient->only(['covenant_id', 'covenant_plan_id', 'card_number']))->toBe(['covenant_id' => picmParticular()->id, 'covenant_plan_id' => null, 'card_number' => null]);
});

it('processamento: falha por convênio vira erro com a mensagem de negócio e entra em covenant_error_lines; o resumo guarda a contagem exata', function () {
    Storage::fake();
    picmReportCatalog();
    $clinic = picmClinic();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $gone   = Covenant::factory()->create(['name' => 'EXTINTO']);
    picmMapping($clinic, 'POSTAL', $gone);
    $gone->delete();

    $import = picmProcess($clinic, "nome;celular;convenio\nAna;11911110001;AMIL\nBia;11911110002;PAS 606\nCaio;11911110003;PAS 606\nDani;11911110004;ASPEB\nEdu;11911110005;POSTAL\nFabi;11911110006;\n");
    $csv    = str_replace('""', '"', ltrim(Storage::disk()->get($import->errors_file_path), "\xEF\xBB\xBF")); // fputcsv duplica as aspas
    $items  = collect($import->preview['covenant_summary']['items'])->keyBy('source_name_normalized');

    expect($import->imported_rows)->toBe(2) // Ana (AMIL) e Fabi (sem convênio → Particular)
        ->and($import->error_rows)->toBe(4)
        ->and($import->covenant_error_lines)->toBe([3, 4, 5, 6])
        ->and($csv)->toContain('Convênio "PAS 606" não encontrado')
        ->and($csv)->toContain(__('validation.custom.covenant.inactive_named', ['name' => 'ASPEB']))
        ->and($csv)->toContain(__('imports.patients.covenant_mapping.target_unavailable', ['name' => 'POSTAL']))
        ->and($items['pas606'])->toMatchArray(['rows' => 2, 'failed_rows' => 2, 'status' => 'unmatched'])
        ->and($items['amil'])->toMatchArray(['rows' => 1, 'failed_rows' => 0, 'status' => 'found'])
        ->and($items['postal']['status'])->toBe('invalid_mapping')
        ->and($import->preview['covenant_summary']['totals']['file_rows'])->toBe(6)
        ->and($import->files_expire_at->isFuture())->toBeTrue();
});

it('processamento: usa o snapshot congelado no confirm — mudar o de-para depois não afeta o job', function () {
    Storage::fake();
    $clinic = picmClinic();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $bra    = Covenant::factory()->create(['name' => 'BRADESCO']);
    picmMapping($clinic, 'PAS 606', $bra); // tabela hoje: Bradesco

    $import = picmProcess($clinic, "nome;celular;convenio\nAna Lima;11911110001;PAS 606\n", [
        'covenant_mapping_snapshot' => ['pas606' => ['source_name' => 'PAS 606', 'covenant_id' => $amil->id, 'covenant_plan_id' => null]],
    ]);

    expect(Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->sole()->covenant_id)->toBe($amil->id)
        ->and($import->covenant_mapping_snapshot)->toBe(['pas606' => ['source_name' => 'PAS 606', 'covenant_id' => $amil->id, 'covenant_plan_id' => null]]);
});

it('processamento sem snapshot (import antigo/direto) lê a tabela e grava o snapshot do que usou', function () {
    Storage::fake();
    $clinic = picmClinic();
    $bra    = Covenant::factory()->create(['name' => 'BRADESCO']);
    picmMapping($clinic, 'PAS 606', $bra);

    $import = picmProcess($clinic, "nome;celular;convenio\nAna Lima;11911110001;PAS 606\n");

    expect(Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->sole()->covenant_id)->toBe($bra->id)
        ->and($import->covenant_mapping_snapshot)->toBe(['pas606' => ['source_name' => 'PAS 606', 'covenant_id' => $bra->id, 'covenant_plan_id' => null]]);
});

// ── Passo 9: store/confirm/index e payloads ────────────────────────────────────

it('store: o upload já traz o resumo de convênios na prévia e o prazo de retenção dos arquivos', function () {
    Storage::fake();
    picmReportCatalog();
    $clinic = picmPanelClinic();

    picmAs($clinic)->post(route('panel.patients.import.store'), ['file' => UploadedFile::fake()->createWithContent('pacientes.csv', picmPreviewCsv())])
        ->assertRedirect(route('panel.patients.import.index'));

    $import = PatientImport::withoutGlobalScopes()->where('entity_id', $clinic->id)->sole();

    expect($import->files_expire_at->between(now()->addDays(29), now()->addDays(31)))->toBeTrue()
        ->and($import->preview['covenant_summary']['totals'])->toBe(['file_rows' => 7, 'unmatched_names' => 2, 'mapped_names' => 0])
        ->and(collect($import->preview['covenant_summary']['items'])->pluck('source_name_normalized')->all())->toBe(['pas606', 'aspeb', 'sulamerica', '']);
});

it('confirm: congela o de-para ATUAL de todos os nomes do arquivo antes de enfileirar', function () {
    Storage::fake();
    Queue::fake();
    $clinic = picmPanelClinic();
    $amil   = Covenant::factory()->create(['name' => 'AMIL']);
    $import = picmReportImport($clinic, picmPreviewCsv(), ['status' => ImportStatus::Pending]);
    $as     = fn () => picmAs($clinic);

    // O de-para muda DEPOIS do último PUT desta importação (outra tela/usuário): o confirm pega o de hoje.
    $as()->putJson(route('panel.patients.import.covenants.update', $import), ['mappings' => [['source_name' => 'PAS 606', 'action' => 'map', 'covenant_id' => $amil->id]]])->assertOk();
    picmMapping($clinic, 'ASPEB', $amil);

    $as()->post(route('panel.patients.import.confirm', $import))->assertRedirect(route('panel.patients.import.index'));

    $import   = $import->fresh();
    $snapshot = $import->covenant_mapping_snapshot;
    ksort($snapshot); // a ordem das chaves não importa

    expect($import->confirmed_at)->not->toBeNull()
        ->and($snapshot)->toBe([
            'aspeb'  => ['source_name' => 'ASPEB', 'covenant_id' => $amil->id, 'covenant_plan_id' => null],
            'pas606' => ['source_name' => 'PAS 606', 'covenant_id' => $amil->id, 'covenant_plan_id' => null],
        ]);
    Queue::assertPushedOn('bulk', ProcessPatientImportJob::class);
});

it('confirm: sem o arquivo não confirma nem enfileira (erro de validação, nada gravado)', function () {
    Storage::fake();
    Queue::fake();
    $clinic = picmPanelClinic();
    $import = picmImport($clinic, ['status' => ImportStatus::Pending]); // arquivo inexistente no disco

    picmAs($clinic)->post(route('panel.patients.import.confirm', $import))->assertSessionHasErrors();

    expect($import->fresh()->confirmed_at)->toBeNull()
        ->and($import->fresh()->covenant_mapping_snapshot)->toBeNull();
    Queue::assertNothingPushed();
});

it('index: expõe reprocesso, erro de convênio, prazo e URLs por import — e nunca as listas de linhas', function () {
    Storage::fake();
    $clinic = picmPanelClinic();
    $parent = picmReportImport($clinic, picmReportCsv(), ['covenant_error_lines' => [2, 3], 'reprocess_lines' => null, 'files_expire_at' => now()->addDays(5)]);
    $child  = picmReportImport($clinic, picmReportCsv(), ['reprocess_of_id' => $parent->id, 'reprocess_lines' => [2, 3], 'covenant_error_lines' => []]);
    $purged = picmReportImport($clinic, picmReportCsv(), ['files_purged_at' => now()]);

    $props = picmAs($clinic)->get(route('panel.patients.import.index'))->assertOk()->viewData('page')['props'];
    $rows  = collect($props['imports'])->keyBy('id');

    expect($rows[$parent->id])->toMatchArray(['is_reprocess' => false, 'reprocess_of_id' => null, 'has_covenant_errors' => true, 'files_expired' => false])
        ->and($rows[$parent->id]['files_expire_at'])->toBe($parent->files_expire_at->toIso8601String())
        ->and($rows[$parent->id]['urls']['covenants'])->toBe(route('panel.patients.import.covenants.show', $parent->id))
        ->and($rows[$parent->id]['urls']['covenants_reprocess'])->toBe(route('panel.patients.import.covenants.reprocess', $parent->id))
        ->and($rows[$child->id])->toMatchArray(['is_reprocess' => true, 'reprocess_of_id' => $parent->id, 'has_covenant_errors' => false])
        ->and($rows[$purged->id]['files_expired'])->toBeTrue()
        ->and($rows[$parent->id])->not->toHaveKeys(['reprocess_lines', 'covenant_error_lines', 'covenant_mapping_snapshot'])
        ->and($props['can_create_covenant'])->toBeTrue()
        ->and($props['urls'])->toMatchArray(['covenant_search' => route('panel.covenants.search'), 'covenant_plan_search' => route('panel.covenant-plans.search')]);

    expect(picmAs($clinic, 'secretary')->get(route('panel.patients.import.index'))->viewData('page')['props']['can_create_covenant'])->toBeFalse();
});

it('progressPayload (evento de WebSocket) leva os mesmos campos novos', function () {
    $clinic = picmEntity();
    $parent = picmImport($clinic, ['status' => ImportStatus::Done, 'covenant_error_lines' => [2], 'files_expire_at' => now()->addDays(3)]);
    $child  = picmImport($clinic, ['reprocess_of_id' => $parent->id]);

    expect($parent->fresh()->progressPayload())->toMatchArray(['is_reprocess' => false, 'reprocess_of_id' => null, 'has_covenant_errors' => true, 'files_expired' => false])
        ->and($parent->fresh()->progressPayload()['files_expire_at'])->toBe($parent->files_expire_at->toIso8601String())
        ->and($child->fresh()->progressPayload())->toMatchArray(['is_reprocess' => true, 'reprocess_of_id' => $parent->id])
        ->and($parent->fresh()->progressPayload())->not->toHaveKeys(['reprocess_lines', 'covenant_error_lines']);
});

// ── Correção (Sentinela): teto de nomes distintos e tenant antes da validação ───

/** Planilha de $distinct convênios distintos (um paciente cada) + $repeats linhas repetindo "CONV 001". */
function picmManyNamesCsv(int $distinct, int $repeats = 0): string
{
    $lines = ['nome;celular;convenio'];

    for ($i = 1; $i <= $distinct + $repeats; $i++) {
        $lines[] = sprintf('Paciente %d;1191%07d;%s', $i, $i, $i <= $distinct ? sprintf('CONV %03d', $i) : 'CONV 001');
    }

    return implode("\n", $lines) . "\n";
}

it('prévia: o teto de nomes distintos para de coletar novos nomes, marca truncated + names_capped e segue contando as linhas dos já coletados', function () {
    Storage::fake();
    config(['patient_imports.max_covenant_names' => 5]);
    $import = picmReportImport(picmClinic(), picmManyNamesCsv(12, 3), ['status' => ImportStatus::Pending]);

    $summary = app(PatientImportService::class)->generatePreview($import)['covenant_summary'];
    $first   = collect($summary['items'])->firstWhere('source_name_normalized', 'conv001');

    expect($summary['items'])->toHaveCount(5)
        ->and($summary['truncated'])->toBeTrue()
        ->and($summary['names_capped'])->toBeTrue()
        ->and($summary['totals']['file_rows'])->toBe(15)
        ->and($first['rows'])->toBe(4); // CONV 001 já estava coletado: continua contando
});

it('prévia: arquivo com milhares de nomes distintos respeita o teto padrão (1000) e o corte de 500 do payload', function () {
    Storage::fake();
    $import = picmReportImport(picmClinic(), picmManyNamesCsv(3000), ['status' => ImportStatus::Pending]);

    $summary = app(PatientImportService::class)->generatePreview($import)['covenant_summary'];

    expect(config('patient_imports.max_covenant_names'))->toBe(1000)
        ->and($summary['items'])->toHaveCount(500)
        ->and($summary['truncated'])->toBeTrue()
        ->and($summary['names_capped'])->toBeTrue()
        ->and($summary['totals']['file_rows'])->toBe(3000);
});

it('prévia: abaixo do teto nada muda — names_capped é false e truncated só vale pelo corte do payload', function () {
    Storage::fake();
    $import = picmReportImport(picmClinic(), picmManyNamesCsv(7), ['status' => ImportStatus::Pending]);

    $summary = app(PatientImportService::class)->generatePreview($import)['covenant_summary'];

    expect($summary['items'])->toHaveCount(7)
        ->and($summary['truncated'])->toBeFalse()
        ->and($summary['names_capped'])->toBeFalse();
});

it('relatório sem resumo (varredura do original) também respeita o teto e avisa names_capped', function () {
    Storage::fake();
    config(['patient_imports.max_covenant_names' => 5]);
    $import = picmReportImport(picmClinic(), picmManyNamesCsv(12));

    $report = app(PatientImportCovenantReportService::class)->forImport($import);

    expect($report['items'])->toHaveCount(5)
        ->and($report['names_capped'])->toBeTrue()
        ->and($report['totals']['file_rows'])->toBe(12)
        ->and(app(PatientImportCovenantReportService::class)->sourceNames($import))->toHaveCount(5);
});

it('processamento com teto: todas as linhas com convênio desconhecido seguem reprocessáveis; o resumo guarda só os nomes coletados', function () {
    Storage::fake();
    config(['patient_imports.max_covenant_names' => 5]);

    $import  = picmProcess(picmClinic(), picmManyNamesCsv(12, 3));
    $summary = $import->preview['covenant_summary'];
    $first   = collect($summary['items'])->firstWhere('source_name_normalized', 'conv001');

    expect($import->error_rows)->toBe(15)
        ->and($import->covenant_error_lines)->toBe(range(2, 16)) // as linhas de TODOS os nomes, inclusive os fora do teto
        ->and($summary['items'])->toHaveCount(5)
        ->and($summary['names_capped'])->toBeTrue()
        ->and($summary['truncated'])->toBeTrue()
        ->and($first)->toMatchArray(['rows' => 4, 'failed_rows' => 4]);
});

it('confirm de arquivo acima do teto não congela um snapshot incompleto: o job lê o de-para atual da clínica', function () {
    Storage::fake();
    Queue::fake();
    config(['patient_imports.max_covenant_names' => 5]);
    $clinic = picmPanelClinic();
    $import = picmReportImport($clinic, picmManyNamesCsv(12), ['status' => ImportStatus::Pending]);

    picmAs($clinic)->post(route('panel.patients.import.confirm', $import))->assertRedirect(route('panel.patients.import.index'));

    expect($import->fresh()->confirmed_at)->not->toBeNull()
        ->and($import->fresh()->covenant_mapping_snapshot)->toBeNull();
    Queue::assertPushedOn('bulk', ProcessPatientImportJob::class);
});

it('PUT covenants: o tenant do import vem ANTES da validação — outra clínica é 404 com payload válido, inválido ou vazio (sem oráculo de existência)', function () {
    $w      = picmHttpWorld();
    $other  = picmPanelClinic();
    $url    = route('panel.patients.import.covenants.update', $w->import);
    $bodies = [
        'vazio'    => [],
        'lista'    => ['mappings' => []],
        'invalido' => ['mappings' => [['source_name' => 'PAS 606', 'action' => 'explodir']]],
        'valido'   => ['mappings' => [['source_name' => 'PAS 606', 'action' => 'particular']]],
    ];

    foreach ($bodies as $body) {
        picmAs($other)->putJson($url, $body)->assertNotFound();
    }

    // Import inexistente dá o mesmo 404; a própria clínica continua recebendo a validação.
    picmAs($other)->putJson(route('panel.patients.import.covenants.update', (string) Str::uuid()), $bodies['invalido'])->assertNotFound();
    picmAs($w->clinic)->putJson($url, $bodies['invalido'])->assertStatus(422);
    picmAs($w->clinic)->putJson($url, $bodies['vazio'])->assertStatus(422);

    expect(picmMappings($other))->toBe([])->and(picmMappings($w->clinic))->toBe([]);
});
