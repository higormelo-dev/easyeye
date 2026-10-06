<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cid10\Cid10ReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Levantamento (SÓ LEITURA) dos prontuários e exames que usaram um código
 * CID-10 cuja descrição no catálogo apontava para OUTRA doença (seleção
 * oftalmológica escrita à mão até 10/2026 — ex.: H50.5 aparecia como
 * "Estrabismo paralítico", mas oficialmente é Heteroforia).
 *
 * Não altera nada: prontuário assinado não pode ser mudado (CFM) e só o
 * médico sabe se o código ou o texto escolhido estava certo. Mostra só
 * contagens por clínica; --details lista os códigos dos registros (sem
 * nome de paciente) para a clínica localizar e revisar.
 *
 * A apuração fica em Cid10ReviewService — a mesma do card "Registros a
 * revisar" do Manager → CID-10.
 */
class AuditCid10WrongDescriptionsCommand extends Command
{
    /** código => descrição errada que o catálogo mostrava (a oficial vem do catálogo) */
    public const AFFECTED = Cid10ReviewService::AFFECTED;

    private const KIND_LABELS = [
        Cid10ReviewService::KIND_RECORD => 'prontuário',
        Cid10ReviewService::KIND_EXAM   => 'exame',
    ];

    protected $signature = 'cid10:audit-records {--details : lista os códigos dos registros (sem dados do paciente)}';

    protected $description = 'Lista (só leitura) prontuários e exames com CID cuja descrição antiga apontava para outra doença.';

    public function handle(Cid10ReviewService $review): int
    {
        $codes = array_keys(self::AFFECTED);
        $rows  = $review->rows()->map(function (object $row) {
            $row->kind = self::KIND_LABELS[$row->kind];

            return $row;
        });

        $this->info('Códigos verificados: ' . implode(', ', $codes));

        if ($rows->isEmpty()) {
            $this->info('Nenhum prontuário ou exame usa esses códigos. Nada a revisar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Clínica', 'Código CID', 'Tipo', 'Registros', 'Assinados'],
            $rows->groupBy(fn ($r) => "{$r->entity}|{$r->cid}|{$r->kind}")
                ->map(fn ($group) => [
                    $group->first()->entity,
                    $group->first()->cid,
                    $group->first()->kind,
                    $group->count(),
                    $group->where('signed', true)->count(),
                ])
                ->sortBy(fn ($r) => $r[0] . $r[1])
                ->values()
                ->all(),
        );

        $this->newLine();
        $this->line('O que cada código É (oficial) × o que o catálogo mostrava:');

        $official = DB::table('cid10_codes')
            ->whereIn('code', $codes)
            ->get(['code', 'description', 'official_description'])
            ->mapWithKeys(fn ($c) => [$c->code => $c->official_description ?? $c->description]);

        foreach ($rows->pluck('cid')->unique()->sort() as $cid) {
            $this->line("  {$cid}: oficial \"" . ($official[$cid] ?? '?') . '" — o catálogo mostrava "' . self::AFFECTED[$cid] . '"');
        }

        if ($this->option('details')) {
            $this->newLine();
            $this->table(
                ['Clínica', 'Tipo', 'Registro', 'Código CID', 'Texto gravado', 'Assinado'],
                $rows->map(fn ($r) => [$r->entity, $r->kind, $r->code, $r->cid, $r->text, $r->signed ? 'sim' : 'não'])->all(),
            );
        }

        $this->newLine();
        $this->comment('Nada foi alterado. O médico responsável deve revisar cada registro (prontuário assinado: adendo/retificação, não edição).');

        return self::SUCCESS;
    }
}
