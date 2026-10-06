<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cid10Code;
use App\Services\Cid10\{Cid10Classifier, Cid10ImportException};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carrega a lista completa da CID-10 (DATASUS, versão 2008 — tabela oficial
 * usada no SUS e na TISS) no catálogo global cid10_codes.
 *
 * Fonte: http://www2.datasus.gov.br/cid10/V2008/descrcsv.htm
 * (CID10CSV.zip), convertida para UTF-8 em database/data/cid10/ — ou os
 * arquivos enviados na tela Manager → CID-10 (Cid10ImportFiles normaliza).
 *
 * - Código: subcategoria "H251" → "H25.1"; categoria sem subdivisão fica
 *   com 3 caracteres ("A09") — são os códigos válidos para diagnóstico.
 * - official_description, chapter e group_name: SEMPRE o oficial.
 * - description (o que o médico vê): a oficial — a seleção oftalmológica
 *   escrita à mão tinha textos que apontavam para outra doença (ex.: H50.5
 *   "Estrabismo paralítico", o oficial é Heteroforia) e o import corrige —,
 *   EXCETO quando o manager editou a descrição (description_edited_at): a
 *   edição manual é mantida e a oficial fica ao lado.
 * - Código criado pelo manager (custom) que aparece na lista oficial vira
 *   datasus; se o texto dele difere do oficial, fica como editado.
 * - Categoria: a curada (Pálpebras, Retina…) é mantida em quem já tem; código
 *   novo recebe o grupo oficial.
 * - Nunca exclui. Código fora da lista oficial (ex.: B30, categoria com
 *   subdivisões) não tem o texto tocado e, se nunca foi confirmado por uma
 *   importação, é marcado como custom (fora da tabela oficial).
 * - Idempotente.
 */
class Cid10CatalogImporter
{
    public const PHASE_READING = 'reading';

    public const PHASE_APPLYING = 'applying';

    private const CHUNK = 500;

    /**
     * @param (callable(string, int, int): void)|null $progress fase, processados, total
     *
     * @return array{read: int, inserted: int, corrected: int, official_updated: int, kept_edited: int, skipped: int}
     */
    public function import(?string $directory = null, ?callable $progress = null): array
    {
        $directory ??= database_path('data/cid10');
        // Envio só com subcategorias: capítulo/grupo vêm dos arquivos oficiais do repositório.
        $classifier = Cid10Classifier::fromDirectory($directory, Cid10Classifier::bundled());
        $progress ??= static function (string $phase, int $processed, int $total): void {
        };

        $progress(self::PHASE_READING, 0, 0);

        [$official, $skipped] = $this->readSubcategories($directory . '/cid-10-subcategorias.csv', $classifier);

        if ($official === []) {
            throw new Cid10ImportException(__('manager_cid10.import_no_valid_rows'));
        }

        $total  = count($official);
        $now    = now();
        $counts = ['read' => $total, 'inserted' => 0, 'corrected' => 0, 'official_updated' => 0, 'kept_edited' => 0, 'skipped' => $skipped];

        $existing = DB::table('cid10_codes')
            ->get(['id', 'code', 'description', 'official_description', 'chapter', 'group_name', 'source', 'description_edited_at'])
            ->keyBy('code');

        $progress(self::PHASE_APPLYING, 0, $total);

        $inserts   = [];
        $processed = 0;

        foreach (array_chunk($official, self::CHUNK, true) as $chunk) {
            DB::transaction(function () use ($chunk, $existing, $now, &$inserts, &$counts): void {
                foreach ($chunk as $code => $item) {
                    $row = $existing->get($code);

                    if ($row === null) {
                        $inserts[] = [
                            'id'                   => (string) Str::uuid(),
                            'code'                 => $code,
                            'description'          => $item['description'],
                            'official_description' => $item['description'],
                            'category'             => $item['group'],
                            'group_name'           => $item['group'],
                            'chapter'              => $item['chapter'],
                            'source'               => Cid10Code::SOURCE_DATASUS,
                            'created_at'           => $now,
                            'updated_at'           => $now,
                        ];

                        continue;
                    }

                    $this->applyOfficial($row, $item, $now, $counts);
                }

                if ($inserts !== []) {
                    // insertOrIgnore: código criado no meio da carga (manager) não derruba o lote.
                    $counts['inserted'] += DB::table('cid10_codes')->insertOrIgnore($inserts);
                    $inserts = [];
                }
            });

            $processed += count($chunk);
            $progress(self::PHASE_APPLYING, $processed, $total);
        }

        $this->classifyOutsideList($classifier);

        return $counts;
    }

    /**
     * @return array{0: array<string, array{description: string, group: ?string, chapter: ?string}>, 1: int}
     */
    private function readSubcategories(string $path, Cid10Classifier $classifier): array
    {
        $official = [];
        $skipped  = 0;

        foreach (Cid10Classifier::csv($path) as $line) {
            $raw         = strtoupper(trim($line['SUBCAT'] ?? ''));
            $description = trim($line['DESCRICAO'] ?? '');

            if (! preg_match('/^[A-Z]\d{2}\d?$/', $raw) || $description === '') {
                $skipped++;

                continue;
            }

            $code            = strlen($raw) === 4 ? substr($raw, 0, 3) . '.' . $raw[3] : $raw;
            $official[$code] = [
                'description' => mb_substr($description, 0, 1000),
                'group'       => $classifier->groupOf($raw),
                'chapter'     => $classifier->chapterOf($raw),
            ];
        }

        return [$official, $skipped];
    }

    /**
     * Código já no catálogo: oficial/capítulo/grupo sempre atualizados; a
     * descrição exibida só quando não foi editada à mão. Os registros
     * clínicos guardam a própria cópia do texto (diagnosis_cids), então nada
     * já gravado muda.
     *
     * @param array{description: string, group: ?string, chapter: ?string} $item
     * @param array<string, int>                                           $counts
     */
    private function applyOfficial(object $row, array $item, mixed $now, array &$counts): void
    {
        $changes = [];

        if ($row->official_description !== null && $row->official_description !== $item['description']) {
            $counts['official_updated']++;
        }

        foreach (['official_description' => $item['description'], 'group_name' => $item['group'], 'chapter' => $item['chapter']] as $column => $value) {
            // Arquivo opcional ausente (grupos/capítulos) não apaga o que já existe.
            if ($value !== null && $row->{$column} !== $value) {
                $changes[$column] = $value;
            }
        }

        $edited = $row->description_edited_at !== null;

        if ($row->source !== Cid10Code::SOURCE_DATASUS) {
            // Criado pelo manager e agora na lista oficial: o texto dele é manual.
            $changes['source'] = Cid10Code::SOURCE_DATASUS;

            if (! $edited && $row->description !== $item['description']) {
                $changes['description_edited_at'] = $now;
                $edited                           = true;
            }
        }

        if ($row->description !== $item['description']) {
            $edited ? $counts['kept_edited']++ : $counts['corrected']++;
        }

        if ($changes !== []) {
            DB::table('cid10_codes')->where('id', $row->id)->update($changes);
        }

        if (! $edited && $row->description !== $item['description']) {
            // Condição repetida no banco: edição manual feita durante a carga não é sobrescrita.
            DB::table('cid10_codes')
                ->where('id', $row->id)
                ->whereNull('description_edited_at')
                ->update(['description' => $item['description'], 'updated_at' => $now]);
        }
    }

    /**
     * Código fora da lista oficial que nenhuma importação confirmou (sem
     * descrição oficial) é "custom" — fora da tabela oficial (ex.: B30,
     * categoria com subdivisões, da seleção curada). Capítulo/grupo vêm da
     * faixa do código.
     */
    private function classifyOutsideList(Cid10Classifier $classifier): void
    {
        DB::table('cid10_codes')
            ->where('source', Cid10Code::SOURCE_DATASUS)
            ->whereNull('official_description')
            ->update(['source' => Cid10Code::SOURCE_CUSTOM]);

        DB::table('cid10_codes')
            ->where('source', Cid10Code::SOURCE_CUSTOM)
            ->where(fn ($q) => $q->whereNull('chapter')->orWhereNull('group_name'))
            ->get(['id', 'code', 'chapter', 'group_name'])
            ->each(function (object $row) use ($classifier): void {
                $changes = array_filter([
                    'chapter'    => $row->chapter ?? $classifier->chapterOf($row->code),
                    'group_name' => $row->group_name ?? $classifier->groupOf($row->code),
                ], fn ($value) => $value !== null);

                if ($changes !== []) {
                    DB::table('cid10_codes')->where('id', $row->id)->update($changes);
                }
            });
    }
}
