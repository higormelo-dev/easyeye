<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\{Patient, PatientExam};
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\{DB, Storage};

/**
 * DEV ONLY — vincula os exames de seed (cujo `archive` aponta para arquivos
 * inexistentes, `exams/fake-*`) às IMAGENS REAIS que já existem no bucket Wasabi
 * (`{entity}/{patient}/exams/...`), para que as miniaturas do módulo Eye Image
 * renderizem no ambiente local usando as imagens reais.
 *
 * Não faz upload: apenas reaponta a coluna `archive` para uma chave já existente
 * no bucket, ciclando entre as imagens reais disponíveis.
 *
 *   php artisan eye-images:seed-thumbnails --patient=PAC-0000000007
 *   php artisan eye-images:seed-thumbnails --limit=400
 *   php artisan eye-images:seed-thumbnails --all --cleanup
 */
class SeedEyeImageThumbnails extends Command
{
    protected $signature = 'eye-images:seed-thumbnails
        {--patient= : Código do paciente (PAC-...) a vincular}
        {--limit=0 : Limite de exames (mais recentes) quando sem --patient}
        {--all : Vincula todos os exames}
        {--cleanup : Remove do bucket os placeholders exams/fake-* que ficaram órfãos}
        {--force : Reaponta mesmo exames que já apontam para imagem real}';

    protected $description = 'DEV: vincula exames de seed às imagens reais do Wasabi (miniaturas Eye Image)';

    /** @var list<string> */
    private array $real = [];

    private int $cursor = 0;

    private int $linked = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Bloqueado em produção.');

            return self::FAILURE;
        }

        $disk = Storage::disk('s3');

        // Imagens reais já presentes no bucket (caminho com entity/patient/exams).
        $this->real = array_values(array_filter(
            $disk->allFiles(),
            static fn (string $f): bool => ! str_starts_with($f, 'exams/')
                && preg_match('#/exams/.+\.(jpe?g|png)$#i', $f) === 1,
        ));

        if ($this->real === []) {
            $this->error('Nenhuma imagem real encontrada no bucket. Confira as credenciais/bucket.');

            return self::FAILURE;
        }

        $this->info(count($this->real) . ' imagens reais disponíveis no Wasabi.');

        $patientCode = $this->option('patient');
        $limit       = (int) $this->option('limit');
        $all         = (bool) $this->option('all');
        $force       = (bool) $this->option('force');

        $base = PatientExam::query()->whereNotNull('archive');

        if ($patientCode) {
            $patient = Patient::query()->where('code', $patientCode)->first();

            if (! $patient) {
                $this->error("Paciente {$patientCode} não encontrado.");

                return self::FAILURE;
            }

            $base->where('patient_id', $patient->id);
        } elseif ($limit <= 0 && ! $all) {
            $this->error('Informe --patient=PAC-..., --limit=N ou --all.');

            return self::FAILURE;
        }

        $total = (clone $base)->count();
        $bar   = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        if ($all) {
            // Memória estável em bases grandes.
            $base->orderBy('id')->chunkById(2000, function ($exams) use ($force, $bar): void {
                foreach ($exams as $exam) {
                    $this->relink($exam, $force);
                    $bar->advance();
                }
            });
        } else {
            $rows = $limit > 0 ? $base->orderByDesc('created_at')->limit($limit)->get() : $base->get();

            foreach ($rows as $exam) {
                $this->relink($exam, $force);
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Exames vinculados: {$this->linked} | já reais: {$this->skipped}");

        if ($this->option('cleanup')) {
            $this->cleanupOrphanPlaceholders($disk);
        }

        return self::SUCCESS;
    }

    private function relink(PatientExam $exam, bool $force): void
    {
        // Já aponta para imagem real (não é placeholder/seed)? Pula, salvo --force.
        if (! $force && ! str_starts_with((string) $exam->archive, 'exams/')) {
            $this->skipped++;

            return;
        }

        DB::table('patient_exams')
            ->where('id', $exam->id)
            ->update(['archive' => $this->real[$this->cursor % count($this->real)]]);

        $this->cursor++;
        $this->linked++;
    }

    /**
     * Remove do bucket os placeholders exams/fake-* que NÃO são mais referenciados
     * por nenhum exame — evita apagar imagens de exames ainda apontando para elas.
     */
    private function cleanupOrphanPlaceholders(Filesystem $disk): void
    {
        $placeholders = array_values(array_filter(
            $disk->allFiles(),
            static fn (string $f): bool => str_starts_with($f, 'exams/fake-') || str_starts_with($f, 'exams/test-'),
        ));

        if ($placeholders === []) {
            return;
        }

        $referenced = PatientExam::query()
            ->whereIn('archive', $placeholders)
            ->pluck('archive')
            ->all();

        $orphans = array_values(array_diff($placeholders, $referenced));

        if ($orphans !== []) {
            $disk->delete($orphans);
        }

        $this->info('Placeholders órfãos removidos: ' . count($orphans) . ' (mantidos: ' . count($referenced) . ')');
    }
}
