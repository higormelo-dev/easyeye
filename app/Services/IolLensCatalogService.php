<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IolLensModel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Catálogo GLOBAL de modelos de lente intraocular (IOL/catarata): busca e
 * dedupe por fabricante+modelo, compartilhado entre todas as clínicas
 * (sem escopo por entity_id — intencional). Mesmo padrão de
 * DiagnosisCatalogService::findOrCreateCustom() aplicado a lentes IOL.
 */
class IolLensCatalogService
{
    /**
     * Busca no catálogo global, ordenada por relevância (prefixo primeiro,
     * depois alfabético) — ordenação aplicada em IolLensModel::scopeSearch().
     *
     * @return Collection<int, IolLensModel>
     */
    public function search(string $term, int $limit = 15): Collection
    {
        return IolLensModel::query()
            ->search($term)
            ->limit($limit)
            ->get();
    }

    /**
     * Busca (ou cria) um modelo de lente no catálogo global pela combinação
     * fabricante+modelo, normalizada (minúsculas + trim) para dedupe via o
     * índice único normalized_key.
     *
     * Protege contra corrida entre requisições concorrentes: se o insert
     * disparar a violação do índice único, refaz o find em vez de propagar
     * o erro pro chamador.
     */
    public function findOrCreateModel(string $manufacturer, string $modelName, ?string $category, string $entityId): IolLensModel
    {
        $manufacturer  = trim($manufacturer);
        $modelName     = trim($modelName);
        $normalizedKey = mb_strtolower(trim("{$manufacturer}|{$modelName}"), 'UTF-8');

        try {
            return IolLensModel::query()->firstOrCreate(
                ['normalized_key' => $normalizedKey],
                [
                    'manufacturer'         => $manufacturer,
                    'model_name'           => $modelName,
                    'category'             => $category,
                    'created_by_entity_id' => $entityId,
                ],
            );
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return IolLensModel::query()
                ->where('normalized_key', $normalizedKey)
                ->firstOrFail();
        }
    }

    /**
     * Salva a foto de produto da lente no disco `public` (não é dado
     * sensível de paciente/saúde — não usa o padrão de disco S3 privado
     * com URL assinada temporária usado pra exames).
     *
     * @return string path relativo salvo no disco `public`
     */
    public function storeImage(UploadedFile $file, string $directory): string
    {
        $path = Storage::disk('public')->putFile($directory, $file);

        if ($path === false) {
            throw new RuntimeException('Falha ao salvar imagem da lente.');
        }

        return $path;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
