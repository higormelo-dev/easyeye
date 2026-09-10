<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\StockUnit;
use App\Models\{EntityProduct, ProductCategory};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Importação em massa de produtos via CSV (GAP fechado — revisão pós-Fase
 * 4, "melhorar o módulo de estoque"). Mesmo VALOR de PatientImportService
 * (upload → preview com erro por linha → confirmação → relatório), mas
 * SÍNCRONO — não usa Job/fila/polling como o de pacientes: catálogo de
 * produto de uma clínica é ordens de grandeza menor que a base de
 * pacientes (dezenas/poucas centenas de linhas numa importação típica de
 * onboarding, nunca milhares), então processar tudo dentro do próprio
 * request é simples, correto, e evita a máquina inteira de
 * model+job+polling só pra um volume que nunca justificaria assíncrono.
 * Limite de linhas em MAX_ROWS abaixo é a trava de segurança pra esse
 * pressuposto nunca virar timeout de request.
 *
 * Isolamento: TODA validação de duplicidade (sku/barcode) considera o
 * ENTITY_ID informado — nunca cruza dado entre clínicas.
 */
class ProductImportService
{
    public const MAX_ROWS = 1000;

    /**
     * Lê e valida o CSV inteiro SEM gravar nada — preview antes de
     * confirmar. Cada linha inválida vira uma entrada em `errors` com o
     * motivo; linhas válidas vão pra `valid` já normalizadas, prontas pra
     * `import()` sem precisar reler o arquivo.
     *
     * @return array{valid: list<array<string, mixed>>, errors: list<array{line: int, name: string, reason: string}>, total: int}
     */
    public function preview(UploadedFile $file, string $entityId): array
    {
        $rows = $this->readCsv($file);

        if (count($rows) > self::MAX_ROWS) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        $categories = ProductCategory::where('entity_id', $entityId)->active()->get(['id', 'name'])
            ->keyBy(fn (ProductCategory $c) => mb_strtolower(trim($c->name)));

        $existingSkus     = EntityProduct::where('entity_id', $entityId)->whereNotNull('sku')->pluck('sku')->map(fn ($v) => mb_strtolower($v))->flip();
        $existingBarcodes = EntityProduct::where('entity_id', $entityId)->whereNotNull('barcode')->pluck('barcode')->flip();

        $valid              = [];
        $errors             = [];
        $seenSkusInFile     = [];
        $seenBarcodesInFile = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 header, +1 índice 0-based -> humano
            $name = trim((string) ($row['nome'] ?? ''));

            if ($name === '') {
                $errors[] = ['line' => $line, 'name' => '(sem nome)', 'reason' => 'Nome é obrigatório.'];

                continue;
            }

            $unit = $this->resolveUnit((string) ($row['unidade'] ?? ''));

            if ($unit === null) {
                $errors[] = ['line' => $line, 'name' => $name, 'reason' => 'Unidade inválida ou ausente (ex.: un, cx, fr, ml...).'];

                continue;
            }

            $sku     = trim((string) ($row['sku'] ?? '')) ?: null;
            $barcode = trim((string) ($row['codigo_barras'] ?? '')) ?: null;

            if ($sku !== null) {
                $skuKey = mb_strtolower($sku);

                if (isset($existingSkus[$skuKey]) || isset($seenSkusInFile[$skuKey])) {
                    $errors[] = ['line' => $line, 'name' => $name, 'reason' => "SKU '{$sku}' já existe (cadastro ou outra linha deste arquivo)."];

                    continue;
                }
                $seenSkusInFile[$skuKey] = true;
            }

            if ($barcode !== null) {
                if (isset($existingBarcodes[$barcode]) || isset($seenBarcodesInFile[$barcode])) {
                    $errors[] = ['line' => $line, 'name' => $name, 'reason' => "Código de barras '{$barcode}' já existe (cadastro ou outra linha deste arquivo)."];

                    continue;
                }
                $seenBarcodesInFile[$barcode] = true;
            }

            $categoryName = trim((string) ($row['categoria'] ?? ''));
            $categoryId   = $categoryName !== '' ? ($categories[mb_strtolower($categoryName)]?->id ?? null) : null;

            $valid[] = [
                'name'                => $name,
                'unit'                => $unit->value,
                'sku'                 => $sku,
                'barcode'             => $barcode,
                'product_category_id' => $categoryId,
                'sale_price'          => $this->parseDecimal($row['preco_venda'] ?? null),
                'min_qty'             => $this->parseDecimal($row['estoque_minimo'] ?? null) ?? 0,
                'max_qty'             => $this->parseDecimal($row['estoque_maximo'] ?? null),
                'category_not_found'  => $categoryName !== '' && $categoryId === null, // aviso, não erro — produto entra sem categoria
            ];
        }

        return ['valid' => $valid, 'errors' => $errors, 'total' => count($rows)];
    }

    /**
     * Cria de fato os produtos — chamado só depois do usuário confirmar o
     * preview. Recebe as linhas JÁ VALIDADAS (reconstruídas pelo controller
     * a partir do payload do preview), mas revalida sku/barcode contra o
     * banco de novo aqui: tempo pode ter passado entre preview e confirmar,
     * outro lançamento pode ter ocupado o mesmo sku nesse meio-tempo.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{created: int, skipped: list<array{name: string, reason: string}>}
     */
    public function import(array $rows, string $entityId): array
    {
        $created = 0;
        $skipped = [];

        // Categorias válidas DESTA clínica, resolvidas UMA vez — o payload
        // de confirmação vem do client (eco do preview); um
        // product_category_id adulterado pra apontar pra categoria de
        // OUTRA clínica nunca deve colar (produto entra sem categoria em
        // vez de vazar posse cruzada).
        $validCategoryIds = ProductCategory::where('entity_id', $entityId)->pluck('id')->flip();

        DB::transaction(function () use ($rows, $entityId, $validCategoryIds, &$created, &$skipped) {
            foreach ($rows as $row) {
                // Defesa em profundidade: payload de confirmação é eco do
                // client (preview → tela → confirmar) — nunca confia cegamente
                // que `unit` ainda é um valor válido do enum (StockUnit::class
                // no cast do model lançaria ValueError não tratado se não
                // fosse, derrubando a transação inteira por 1 linha ruim).
                if (trim((string) ($row['name'] ?? '')) === '') {
                    $skipped[] = ['name' => '(sem nome)', 'reason' => 'Nome ausente no momento da confirmação.'];

                    continue;
                }

                if (StockUnit::tryFrom((string) ($row['unit'] ?? '')) === null) {
                    $skipped[] = ['name' => $row['name'], 'reason' => 'Unidade inválida no momento da confirmação.'];

                    continue;
                }

                if (! empty($row['sku']) && EntityProduct::where('entity_id', $entityId)->where('sku', $row['sku'])->exists()) {
                    $skipped[] = ['name' => $row['name'], 'reason' => "SKU '{$row['sku']}' já foi usado (concorrência ou duplicata no arquivo)."];

                    continue;
                }

                if (! empty($row['barcode']) && EntityProduct::where('entity_id', $entityId)->where('barcode', $row['barcode'])->exists()) {
                    $skipped[] = ['name' => $row['name'], 'reason' => "Código de barras '{$row['barcode']}' já foi usado."];

                    continue;
                }

                $categoryId = $row['product_category_id'] ?? null;

                if ($categoryId !== null && ! isset($validCategoryIds[$categoryId])) {
                    $categoryId = null;
                }

                EntityProduct::create([
                    'entity_id'           => $entityId,
                    'name'                => $row['name'],
                    'unit'                => $row['unit'],
                    'sku'                 => $row['sku'],
                    'barcode'             => $row['barcode'],
                    'product_category_id' => $categoryId,
                    'sale_price'          => $row['sale_price'],
                    'min_qty'             => $row['min_qty'],
                    'max_qty'             => $row['max_qty'],
                    'active'              => true,
                ]);
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [];
        }

        // BOM UTF-8 (Excel sempre grava um na frente) — sem remover, a
        // primeira coluna do header vem com lixo e a coluna 'nome' nunca bate.
        $firstLine = fgets($handle);
        $firstLine = $firstLine !== false ? preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) : '';
        $header    = str_getcsv(trim((string) $firstLine), ';');
        $header    = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);

        $rows = [];

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // linha em branco
            }
            $rows[] = array_combine($header, array_pad($data, count($header), null));
        }
        fclose($handle);

        return $rows;
    }

    private function resolveUnit(string $value): ?StockUnit
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        foreach (StockUnit::cases() as $unit) {
            if ($value === $unit->value || $value === mb_strtolower($unit->label())) {
                return $unit;
            }
        }

        return null;
    }

    private function parseDecimal(mixed $value): ?float
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        // Aceita tanto "10,50" (BR) quanto "10.50" (US) — planilha exportada
        // do Excel BR usa vírgula decimal.
        $normalized = str_contains($value, ',') ? str_replace(['.', ','], ['', '.'], $value) : $value;

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
