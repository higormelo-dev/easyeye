<?php

namespace App\Console\Commands;

use App\Jobs\GenerateExamDerivatives;
use App\Models\PatientExam;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Storage};
use RuntimeException;
use Throwable;

/** At-least-once publication: broker failures retain the durable intent. */
class PublishIntegratorExamOutbox extends Command
{
    protected $signature = 'integrator-outbox:publish {--limit=100}';

    protected $description = 'Publica derivados e limpeza de aquisições confirmadas';

    public function handle(): int
    {
        $failed = false;
        $ids    = DB::table('integrator_exam_outbox')->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id) {
                    $intent = DB::table('integrator_exam_outbox')->where('id', $id)->lockForUpdate()->first();

                    if ($intent === null) {
                        return;
                    }

                    if ($intent->operation === 'derivatives') {
                        GenerateExamDerivatives::dispatch($intent->patient_exam_id, $intent->archive)->beforeCommit();
                    } elseif ($intent->operation === 'delete_archive') {
                        if (! PatientExam::where('archive', $intent->archive)->exists() && ! Storage::disk('s3')->delete($intent->archive)) {
                            throw new RuntimeException('Archive cleanup failed');
                        }
                    } else {
                        throw new RuntimeException('Unsupported outbox operation');
                    }
                    DB::table('integrator_exam_outbox')->where('id', $id)->delete();
                });
            } catch (Throwable $error) {
                $failed = true;
                report($error);
                $this->warn("Intenção {$id} preservada para nova tentativa.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
