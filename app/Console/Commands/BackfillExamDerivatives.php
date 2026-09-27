<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateExamDerivatives;
use App\Models\{Patient, PatientExam};
use Illuminate\Console\Command;

/**
 * PRODUÇÃO — enfileira GenerateExamDerivatives para exames que ainda não têm
 * `display_archive`/`thumb_archive` (exames criados antes da migration
 * 2026_09_01_120000_add_derivative_archives_to_patient_exams, ou que falharam
 * na geração original — ver bug de dispatch sem afterCommit() corrigido em
 * PatientExamService::persistExam()).
 *
 * Sem esse backfill, exames antigos continuam servindo o arquivo original em
 * resolução total no grid do Gerenciador de Imagens (lento), mesmo depois da
 * infra de miniatura já existir.
 *
 *   php artisan eye-images:backfill-derivatives --limit=500
 *   php artisan eye-images:backfill-derivatives --patient=PAC-0000000007
 *   php artisan eye-images:backfill-derivatives --all
 *   php artisan eye-images:backfill-derivatives --all --force
 */
class BackfillExamDerivatives extends Command
{
    protected $signature = 'eye-images:backfill-derivatives
        {--patient= : Código do paciente (PAC-...) a processar}
        {--limit=0 : Limite de exames (mais recentes) quando sem --patient}
        {--all : Processa todos os exames pendentes}
        {--force : Reprocessa mesmo exames que já têm thumb_archive}';

    protected $description = 'Enfileira geração de miniatura/JPEG de exibição para exames sem derivados';

    public function handle(): int
    {
        $patientCode = $this->option('patient');
        $limit       = (int) $this->option('limit');
        $all         = (bool) $this->option('all');
        $force       = (bool) $this->option('force');

        $base = PatientExam::query()->whereNotNull('archive');

        if (! $force) {
            $base->whereNull('thumb_archive');
        }

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

        $total  = (clone $base)->count();
        $target = $limit > 0 ? min($limit, $total) : $total;
        $bar    = $this->output->createProgressBar($target);
        $queued = 0;
        $bar->start();

        if ($all || $patientCode) {
            $base->orderBy('id')->chunkById(500, function ($exams) use (&$queued, $bar, $limit): void {
                foreach ($exams as $exam) {
                    if ($limit > 0 && $queued >= $limit) {
                        return;
                    }

                    GenerateExamDerivatives::dispatch($exam->id);
                    $queued++;
                    $bar->advance();
                }
            });
        } else {
            $base->orderByDesc('created_at')->limit($limit)->get()->each(function (PatientExam $exam) use (&$queued, $bar): void {
                GenerateExamDerivatives::dispatch($exam->id);
                $queued++;
                $bar->advance();
            });
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Exames enfileirados para gerar derivados: {$queued} (de {$total} pendentes).");

        return self::SUCCESS;
    }
}
