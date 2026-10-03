<?php

use App\Enums\{ClientRule, ImportStatus, MedicineSource};
use App\Models\{DoctorMedicationPreset, Entity, Medicine, MedicineImport, User};
use App\Services\Medicines\AnvisaMedicineImportService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Importação do catálogo global de medicamentos a partir da lista de preços
 * CMED/Anvisa (AnvisaMedicineImportService). Planilha gerada no teste com o
 * MESMO layout da lista real: aba de preâmbulo + aba "Lista PMC" com o
 * cabeçalho na 1ª linha.
 */
const CMED_HEADER = [
    'SUBSTÂNCIA', 'CNPJ', 'LABORATÓRIO', 'CÓDIGO GGREM', 'REGISTRO', 'EAN 1', 'EAN 2', 'EAN 3', 'PRODUTO',
    'APRESENTAÇÃO', 'CLASSE TERAPÊUTICA', 'TIPO DE PRODUTO (STATUS DO PRODUTO)', 'REGIME DE PREÇO',
    'PF Sem Impostos', 'RESTRIÇÃO HOSPITALAR', 'COMERCIALIZAÇÃO 2025', 'TARJA',
];

/** @return array<string, string> */
function cmedRow(array $overrides = []): array
{
    return array_merge([
        'SUBSTÂNCIA'                          => 'ACETATO DE PREDNISOLONA',
        'CNPJ'                                => '00.000.000/0001-00',
        'LABORATÓRIO'                         => 'LAB TESTE S/A',
        'CÓDIGO GGREM'                        => '500000000000001',
        'REGISTRO'                            => '1000000010011',
        'EAN 1'                               => '7890000000011',
        'EAN 2'                               => '    -     ',
        'EAN 3'                               => '    -     ',
        'PRODUTO'                             => 'PREDOPTIC',
        'APRESENTAÇÃO'                        => '10 MG/ML SUS OFT CT FR GOT PLAS OPC X 5 ML',
        'CLASSE TERAPÊUTICA'                  => 'S1B - CORTICOESTERÓIDES OFTÁLMICOS',
        'TIPO DE PRODUTO (STATUS DO PRODUTO)' => 'Similar',
        'REGIME DE PREÇO'                     => 'Regulado',
        'PF Sem Impostos'                     => '10,00',
        'RESTRIÇÃO HOSPITALAR'                => 'Não',
        'COMERCIALIZAÇÃO 2025'                => 'Sim',
        'TARJA'                               => 'Tarja Vermelha',
    ], $overrides);
}

/** Gera a planilha CMED (preâmbulo + Lista PMC) e devolve o caminho no disco padrão. */
function storeCmedXlsx(array $rows, string $name = 'cmed.xlsx'): string
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->setTitle('Cabeçalho')->setCellValue('A1', 'Secretaria Executiva - CMED');

    $sheet = $spreadsheet->createSheet()->setTitle('Lista PMC');
    $sheet->fromArray([CMED_HEADER, ...array_map(fn ($r) => array_values($r), $rows)], null, 'A1', true);

    // Códigos longos como TEXTO (a lista real guarda GGREM/EAN como texto).
    foreach ($rows as $i => $row) {
        foreach (['D', 'E', 'F'] as $col) {
            $value = $row[CMED_HEADER[ord($col) - 65]];
            $sheet->setCellValueExplicit($col . ($i + 2), $value, DataType::TYPE_STRING);
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cmed') . '.xlsx';
    (new Xlsx($spreadsheet))->save($tmp);
    Storage::disk()->put("imports/medicines/test/{$name}", file_get_contents($tmp));
    @unlink($tmp);

    return "imports/medicines/test/{$name}";
}

function storeOpenDataCsv(array $registrations): string
{
    $lines = ['TIPO_PRODUTO;NOME_PRODUTO;NUMERO_REGISTRO_PRODUTO;SITUACAO_REGISTRO;PRINCIPIO_ATIVO'];

    foreach ($registrations as $registration => $status) {
        $lines[] = "\"MEDICAMENTO\";\"X\";{$registration};\"{$status}\";\"x\"";
    }

    // Arquivo real da Anvisa vem em Latin-1.
    Storage::disk()->put('imports/medicines/test/dados.csv', mb_convert_encoding(implode("\n", $lines), 'ISO-8859-1', 'UTF-8'));

    return 'imports/medicines/test/dados.csv';
}

function runCmedImport(string $cmedPath, ?string $openDataPath = null, ?User $user = null): MedicineImport
{
    $import = MedicineImport::query()->create([
        'user_id'                 => $user?->id,
        'status'                  => ImportStatus::Pending,
        'cmed_file_path'          => $cmedPath,
        'cmed_original_name'      => basename($cmedPath),
        'open_data_file_path'     => $openDataPath,
        'open_data_original_name' => $openDataPath ? basename($openDataPath) : null,
    ]);

    app(AnvisaMedicineImportService::class)->process($import);

    return $import->fresh();
}

function cmedMedicine(string $ggrem): ?Medicine
{
    return Medicine::withoutGlobalScopes()->where('source', 'cmed')->where('source_code', $ggrem)->first();
}

beforeEach(function () {
    Storage::fake();
});

it('importa apresentações com genérico, concentração, forma e laboratório', function () {
    $import = runCmedImport(storeCmedXlsx([
        cmedRow(),
        cmedRow([
            'SUBSTÂNCIA'                          => 'CLORIDRATO DE CIPROFLOXACINO',
            'CÓDIGO GGREM'                        => '500000000000002',
            'REGISTRO'                            => '1000000020022',
            'PRODUTO'                             => 'CLORIDRATO DE CIPROFLOXACINO',
            'APRESENTAÇÃO'                        => '500 MG COM REV CT BL AL PLAS X 14',
            'TIPO DE PRODUTO (STATUS DO PRODUTO)' => 'Genérico',
            'COMERCIALIZAÇÃO 2025'                => 'Não',
        ]),
    ]));

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->created_count)->toBe(2)
        ->and($import->updated_count)->toBe(0)
        // barra de progresso: total real gravado no fim, 100%, sem fase
        ->and($import->total_rows)->toBe(2)
        ->and($import->processed_rows)->toBe(2)
        ->and($import->progressPercent())->toBe(100)
        ->and($import->phase)->toBeNull();

    $pred = cmedMedicine('500000000000001');
    expect($pred->entity_id)->toBeNull()
        ->and($pred->source)->toBe(MedicineSource::Cmed)
        ->and($pred->name)->toBe('PREDOPTIC')
        ->and($pred->active_ingredient)->toBe('acetato de prednisolona')
        ->and($pred->concentration)->toBe('10 MG/ML')
        ->and($pred->pharmaceutical_form)->toBe('susp_oft')
        ->and($pred->is_ophthalmic)->toBeTrue()
        ->and($pred->laboratory)->toBe('LAB TESTE S/A')
        ->and($pred->anvisa_registration)->toBe('1000000010011')
        ->and($pred->ean)->toBe('7890000000011')
        ->and($pred->regulatory_category)->toBe('Similar')
        ->and($pred->active)->toBeTrue()
        ->and($pred->search_text)->toContain('predoptic')
        ->and($pred->search_text)->toContain('acetato de prednisolona')
        ->and($pred->search_text)->toContain('colirio');

    expect(cmedMedicine('500000000000002')->is_marketed)->toBeFalse()
        ->and(cmedMedicine('500000000000002')->is_ophthalmic)->toBeFalse();
});

it('ignora uso hospitalar, linha sem produto e GGREM repetido', function () {
    $import = runCmedImport(storeCmedXlsx([
        cmedRow(),
        cmedRow(['CÓDIGO GGREM' => '500000000000003', 'RESTRIÇÃO HOSPITALAR' => 'Sim']),
        cmedRow(['CÓDIGO GGREM' => '500000000000004', 'PRODUTO' => '']),
        cmedRow(['PRODUTO' => 'DUPLICADO']),
    ]));

    expect($import->created_count)->toBe(1)
        ->and($import->skipped_hospital)->toBe(1)
        ->and($import->skipped_invalid)->toBe(1)
        ->and(cmedMedicine('500000000000001')->name)->toBe('PREDOPTIC')
        ->and(cmedMedicine('500000000000003'))->toBeNull();
});

it('com os dados abertos, descarta registro inativo (cruza pelos 9 primeiros dígitos)', function () {
    $import = runCmedImport(
        storeCmedXlsx([
            cmedRow(),
            cmedRow(['CÓDIGO GGREM' => '500000000000005', 'REGISTRO' => '1000000050055']),
            cmedRow(['CÓDIGO GGREM' => '500000000000006', 'REGISTRO' => '1999999990011']),
        ]),
        storeOpenDataCsv(['100000001' => 'Ativo', '100000005' => 'Inativo']),
    );

    expect($import->created_count)->toBe(2)
        ->and($import->skipped_inactive_registration)->toBe(1)
        ->and(cmedMedicine('500000000000005'))->toBeNull()
        // registro ausente dos dados abertos fica (benefício da dúvida)
        ->and(cmedMedicine('500000000000006'))->not->toBeNull();
});

it('reimportar atualiza, desativa o que saiu da lista e preserva posologia e favoritos', function () {
    runCmedImport(storeCmedXlsx([
        cmedRow(),
        cmedRow(['CÓDIGO GGREM' => '500000000000007', 'PRODUTO' => 'VAI SAIR']),
    ]));

    $pred = cmedMedicine('500000000000001');
    Medicine::withoutGlobalScopes()->whereKey($pred->id)->update(['dosage' => '1 gota', 'frequency' => 'de 6/6h']);

    $clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $eu     = createEntityUser($clinic, User::factory()->create(), ClientRule::Doctor->value);
    DoctorMedicationPreset::query()->create([
        'entity_id' => $clinic->id, 'entity_user_id' => $eu->id, 'medicine_id' => $pred->id,
        'posology'  => 'minha', 'is_favorite' => true,
    ]);

    $import = runCmedImport(storeCmedXlsx([cmedRow(['LABORATÓRIO' => 'NOVO LAB'])], 'cmed2.xlsx'));

    $pred->refresh();
    expect($import->updated_count)->toBe(1)
        ->and($import->created_count)->toBe(0)
        ->and($import->deactivated_count)->toBe(1)
        ->and($pred->laboratory)->toBe('NOVO LAB')
        ->and($pred->dosage)->toBe('1 gota')
        ->and($pred->frequency)->toBe('de 6/6h')
        ->and(cmedMedicine('500000000000007')->active)->toBeFalse()
        ->and(DoctorMedicationPreset::query()->where('medicine_id', $pred->id)->exists())->toBeTrue();
});

it('não mexe nos itens curados (manuais) nem nos da clínica', function () {
    $manual = Medicine::withoutGlobalScopes()->create(['name' => 'TOBRAMICINA 0,3%', 'active' => true]);

    runCmedImport(storeCmedXlsx([cmedRow()]));

    expect($manual->fresh()->active)->toBeTrue()
        ->and($manual->fresh()->source)->toBe(MedicineSource::Manual);
});

it('[SEGURANÇA] arquivo errado falha com mensagem clara e não desativa o catálogo', function () {
    runCmedImport(storeCmedXlsx([cmedRow()]));

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->fromArray([['NOME', 'VALOR'], ['x', '1']]);
    $tmp = tempnam(sys_get_temp_dir(), 'bad') . '.xlsx';
    (new Xlsx($spreadsheet))->save($tmp);
    Storage::disk()->put('imports/medicines/test/errado.xlsx', file_get_contents($tmp));
    @unlink($tmp);

    $import = runCmedImport('imports/medicines/test/errado.xlsx');

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_medicines.import_header_not_found'))
        ->and(cmedMedicine('500000000000001')->active)->toBeTrue();
});

it('aceita a lista CMED em CSV (Latin-1, separador ;)', function () {
    $lines = [implode(';', CMED_HEADER), implode(';', array_values(cmedRow()))];
    Storage::disk()->put('imports/medicines/test/cmed.csv', mb_convert_encoding(implode("\n", $lines), 'ISO-8859-1', 'UTF-8'));

    $import = runCmedImport('imports/medicines/test/cmed.csv');

    expect($import->status)->toBe(ImportStatus::Done)
        ->and(cmedMedicine('500000000000001')->active_ingredient)->toBe('acetato de prednisolona');
});
