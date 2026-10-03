<?php

declare(strict_types=1);

namespace App\Services\Covenants;

use App\Enums\CovenantSource;
use App\Models\{CovenantImport, CovenantPlan};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, Http};
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Sincroniza os PLANOS (produtos registrados na ANS) dos convênios globais
 * com os dados abertos "Características dos Produtos da Saúde Suplementar"
 * (~75 MB, ~166 mil produtos, atualizado diariamente).
 *
 * - Plano novo entra só se a operadora está no catálogo, a cobertura é
 *   médico-hospitalar (exclusivamente odontológico fica de fora) e a
 *   situação é Ativo ou Suspenso (comercialização suspensa ainda atende
 *   beneficiários).
 * - Plano que já está no catálogo sempre recebe os dados atualizados;
 *   Cancelado/Transferido deixa de ser escolhível (nunca é excluído: pode
 *   estar no cadastro de pacientes).
 *
 * Memória limitada (worker com --memory=256): o arquivo vai direto para o
 * disco (sink), é lido linha a linha e gravado em lotes; só o mapa
 * ID_PLANO → assinatura dos planos existentes fica em memória.
 */
class AnsPlanImportService
{
    public const PHASE_DOWNLOADING = 'plans_downloading';

    public const PHASE_PROCESSING = 'plans_processing';

    private const COLUMNS = [
        'ID_PLANO', 'CD_PLANO', 'NM_PLANO', 'REGISTRO_OPERADORA', 'CONTRATACAO', 'SGMT_ASSISTENCIAL', 'COBERTURA',
        'ABRANGENCIA_COBERTURA', 'FATOR_MODERADOR', 'ACOMODACAO_HOSPITALAR', 'VIGENCIA_PLANO', 'SITUACAO_PLANO',
        'DT_SITUACAO', 'DT_REGISTRO_PLANO',
    ];

    /** SITUACAO_PLANO (sem acento, maiúsculas) → código gravado. */
    private const STATUSES = [
        'ATIVO' => 'active', 'SUSPENSO' => 'suspended', 'CANCELADO' => 'cancelled', 'TRANSFERIDO' => 'transferred',
    ];

    /** Campos oficiais regravados pela sincronização (upsert). */
    private const UPDATE_COLUMNS = [
        'covenant_id', 'name', 'ans_code', 'contracting', 'segmentation', 'coverage_area', 'accommodation',
        'moderating_factor', 'regulation', 'ans_status', 'ans_status_at', 'ans_registered_at', 'active',
        'sync_hash', 'updated_at',
    ];

    private const BATCH = 1000;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<array<string, mixed>> */
    private array $batch = [];

    private int $processed = 0;

    private float $lastReportAt = 0.0;

    /**
     * @param int $baseRows linhas já processadas na etapa de operadoras (a
     *                      barra de progresso continua de onde parou)
     */
    public function sync(CovenantImport $import, int $baseRows = 0): void
    {
        $this->counts = [
            'plans_created_count'     => 0, 'plans_updated_count' => 0, 'plans_unchanged_count' => 0,
            'plans_deactivated_count' => 0, 'plans_skipped_count' => 0,
        ];
        $this->batch        = [];
        $this->processed    = $baseRows;
        $this->lastReportAt = 0.0;

        $import->update(['phase' => self::PHASE_DOWNLOADING]);

        $path = $this->download();

        try {
            $lines = $this->countLines($path);
            $import->update([
                'phase'          => self::PHASE_PROCESSING,
                'total_rows'     => $baseRows + $lines,
                'processed_rows' => $baseRows,
            ]);

            $this->process($import, $path);
        } finally {
            @unlink($path);
        }

        $import->update([...$this->counts, 'processed_rows' => $this->processed, 'total_rows' => $this->processed]);
    }

    private function process(CovenantImport $import, string $path): void
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new CovenantImportException(__('manager_covenants.import_file_missing'));
        }

        try {
            $header = fgetcsv($handle, 0, ';', '"', '');
            $header = is_array($header) ? array_map(fn ($h) => $this->normalize($this->utf8((string) $h)), $header) : [];

            foreach (self::COLUMNS as $column) {
                if (! in_array($column, $header, true)) {
                    throw new CovenantImportException(__('manager_covenants.plans_header_not_found'));
                }
            }

            $index     = array_flip($header);
            $covenants = $this->globalCovenants();
            $existing  = $this->existingPlans();
            $now       = now()->toDateTimeString();
            $seen      = false;

            while (($raw = fgetcsv($handle, 0, ';', '"', '')) !== false) {
                if ($raw === [null] || $raw === []) {
                    continue;
                }

                $this->processed++;
                $seen = true;

                $value  = fn (string $column) => $this->utf8((string) ($raw[$index[$column]] ?? ''));
                $planId = trim($value('ID_PLANO'));

                if (! ctype_digit($planId)) {
                    $this->counts['plans_skipped_count']++;
                    $this->reportProgress($import);

                    continue;
                }

                $status     = self::STATUSES[$this->normalize($value('SITUACAO_PLANO'))] ?? null;
                $covenantId = $covenants[$this->registry($value('REGISTRO_OPERADORA'))] ?? null;
                $current    = isset($existing[$planId]) ? $this->unpack($existing[$planId]) : null;

                if ($current === null) {
                    $medical = str_starts_with($this->normalize($value('COBERTURA')), 'MEDICO');

                    if ($covenantId === null || ! $medical || ! in_array($status, CovenantPlan::SELECTABLE_STATUSES, true)) {
                        $this->counts['plans_skipped_count']++;
                        $this->reportProgress($import);

                        continue;
                    }
                }

                // Operadora fora do catálogo agora (não acontece: convênio não é
                // excluído) mantém o vínculo antigo.
                $covenantId ??= $current['covenant_id'];
                $name = $this->clean($value('NM_PLANO'));

                if ($name === null) {
                    $this->counts['plans_skipped_count']++;
                    $this->reportProgress($import);

                    continue;
                }

                $values = [
                    'covenant_id'       => $covenantId,
                    'name'              => mb_substr(mb_strtoupper($name, 'UTF-8'), 0, 255),
                    'ans_code'          => $this->clean($value('CD_PLANO'), 30),
                    'contracting'       => $this->clean($value('CONTRATACAO'), 80),
                    'segmentation'      => $this->clean($value('SGMT_ASSISTENCIAL'), 120),
                    'coverage_area'     => $this->clean($value('ABRANGENCIA_COBERTURA'), 40),
                    'accommodation'     => $this->clean($value('ACOMODACAO_HOSPITALAR'), 40),
                    'moderating_factor' => $this->clean($value('FATOR_MODERADOR'), 40),
                    'regulation'        => in_array($v = strtoupper(trim($value('VIGENCIA_PLANO'))), ['A', 'P'], true) ? $v : null,
                    'ans_status'        => $status,
                    'ans_status_at'     => $this->date($value('DT_SITUACAO')),
                    'ans_registered_at' => $this->date($value('DT_REGISTRO_PLANO')),
                    'active'            => in_array($status, CovenantPlan::SELECTABLE_STATUSES, true),
                ];
                $hash = md5(json_encode($values, JSON_UNESCAPED_UNICODE) ?: '');

                if ($current !== null && $current['hash'] === $hash) {
                    $this->counts['plans_unchanged_count']++;
                    $this->reportProgress($import);

                    continue;
                }

                if ($current === null) {
                    $this->counts['plans_created_count']++;
                } else {
                    $this->counts['plans_updated_count']++;

                    if ($current['active'] && ! $values['active']) {
                        $this->counts['plans_deactivated_count']++;
                    }
                }

                $this->batch[] = [
                    'id'          => $current['id'] ?? (string) Str::uuid7(),
                    'entity_id'   => null,
                    'ans_plan_id' => (int) $planId,
                    ...$values,
                    'source'     => CovenantSource::Ans->value,
                    'sync_hash'  => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($this->batch) >= self::BATCH) {
                    $this->flush();
                }

                $this->reportProgress($import);
            }

            $this->flush();

            if (! $seen) {
                throw new CovenantImportException(__('manager_covenants.plans_no_valid_rows'));
            }
        } finally {
            fclose($handle);
        }
    }

    /** Grava o lote: insere os novos, atualiza só os campos oficiais dos existentes. */
    private function flush(): void
    {
        if ($this->batch === []) {
            return;
        }

        DB::table('covenant_plans')->upsert($this->batch, ['ans_plan_id'], self::UPDATE_COLUMNS);
        $this->batch = [];
    }

    /** Baixa o arquivo direto para o disco (75 MB não cabem na memória do worker). */
    private function download(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ansplans_');
        $max  = (int) config('covenants.ans.plans_max_bytes');

        if ($path === false) {
            throw new CovenantImportException(__('manager_covenants.plans_download_failed'));
        }

        try {
            $response = Http::timeout((int) config('covenants.ans.plans_timeout_seconds', 600))
                ->connectTimeout(30)
                ->retry(2, 5000, throw: false)
                ->sink($path)
                ->withOptions([
                    // Corta antes de baixar um arquivo maior que o teto.
                    'on_headers' => function (ResponseInterface $response) use ($max) {
                        if ((int) $response->getHeaderLine('Content-Length') > $max) {
                            throw new RuntimeException('ANS plans file too large');
                        }
                    },
                ])
                ->get((string) config('covenants.ans.plans_url'));
        } catch (Throwable) {
            @unlink($path);

            throw new CovenantImportException(__('manager_covenants.plans_download_failed'));
        }

        clearstatcache(true, $path);
        $size = (int) @filesize($path);

        if (! $response->successful() || $size === 0 || $size > $max) {
            @unlink($path);

            throw new CovenantImportException(__('manager_covenants.plans_download_failed'));
        }

        return $path;
    }

    /** @return array<string, string> registro ANS (6 dígitos) → convênio global */
    private function globalCovenants(): array
    {
        $map = [];

        DB::table('covenants')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->whereNotNull('ans_registry')
            ->orderBy('created_at')
            ->get(['id', 'ans_registry'])
            ->each(function (object $row) use (&$map) {
                $registry = $this->registry((string) $row->ans_registry);

                if ($registry !== '' && ! isset($map[$registry])) {
                    $map[$registry] = (string) $row->id;
                }
            });

        return $map;
    }

    /**
     * Planos globais já sincronizados: ID_PLANO → "id|assinatura|ativo|convênio"
     * (string compacta: ~66 mil linhas em ~12 MB, contra ~40 MB em arrays).
     *
     * @return array<string, string>
     */
    private function existingPlans(): array
    {
        $map = [];

        DB::table('covenant_plans')
            ->whereNull('entity_id')
            ->whereNotNull('ans_plan_id')
            ->select(['id', 'ans_plan_id', 'sync_hash', 'active', 'covenant_id'])
            ->lazyById(5000, 'id')
            ->each(function (object $row) use (&$map) {
                $map[(string) $row->ans_plan_id] = implode('|', [
                    $row->id, (string) $row->sync_hash, $row->active ? '1' : '0', $row->covenant_id,
                ]);
            });

        return $map;
    }

    /** @return array{id: string, hash: string, active: bool, covenant_id: string} */
    private function unpack(string $packed): array
    {
        [$id, $hash, $active, $covenantId] = explode('|', $packed);

        return ['id' => $id, 'hash' => $hash, 'active' => $active === '1', 'covenant_id' => $covenantId];
    }

    private function countLines(string $path): int
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return 0;
        }

        $lines = -1; // cabeçalho

        while (fgets($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return max(0, $lines);
    }

    /** Progresso no import (a tela recebe por WebSocket). No máximo 1 escrita/segundo. */
    private function reportProgress(CovenantImport $import): void
    {
        $now = microtime(true);

        if ($now - $this->lastReportAt < 1.0) {
            return;
        }

        $this->lastReportAt = $now;
        $import->update([...$this->counts, 'processed_rows' => $this->processed]);
    }

    private function registry(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return ($digits === '' || strlen($digits) > 6 || (int) $digits === 0) ? '' : str_pad($digits, 6, '0', STR_PAD_LEFT);
    }

    private function clean(string $value, int $max = 255): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function date(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** Maiúsculas sem acento — casamento de cabeçalho, situação e cobertura. */
    private function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9_ -]/', '', Str::ascii(trim($value))) ?? '', 'UTF-8');
    }

    private function utf8(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
}
