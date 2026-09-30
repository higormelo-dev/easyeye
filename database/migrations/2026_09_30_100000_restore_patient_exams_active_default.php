<?php

declare(strict_types=1);

use App\Enums\ExamSource;
use App\Models\PatientExam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Exames enviados pelo integrador voltam a nascer HABILITADOS.
 *
 * Causa: `patient_exams.active` tem default false e o `'active' => true` da
 * criação saiu num refactor (commit 7d1d201, 02/02/2026). Desde então todo
 * exame do integrador nascia "desabilitado/cancelado" — o que, a partir de
 * 09/09/2026, também o tira de laudo, IA e (agora) do repasse médico.
 *
 * - default da coluna passa a true (a aplicação também grava true);
 * - backfill: reabilita os exames do integrador criados a partir do refactor
 *   que NUNCA foram desabilitados por alguém — desabilitar só existe desde
 *   09/09/2026 (EyeImageExamActionsController::toggleActive) e sempre gera
 *   audit_log com `active`; a importação externa já nascia ativa. Cada exame
 *   reabilitado ganha um audit_log (rastreável e reversível);
 * - índice em schedule_id: o repasse busca os exames de cada agendamento.
 *
 * Fora de uma transação única (`withinTransaction = false`): no PostgreSQL a
 * migration inteira numa transação seguraria o lock exclusivo do ALTER
 * (bloqueia até leitura de patient_exams) até o fim do backfill. Assim o
 * ALTER é instantâneo (só o default), o índice é criado CONCURRENTLY (não
 * bloqueia gravação) e cada lote do backfill é uma transação curta.
 */
return new class() extends Migration {
    public $withinTransaction = false;

    private const REGRESSION_DATE = '2026-02-02 00:00:00';

    private const MIGRATION_AGENT = 'migration:2026_09_30_100000_restore_patient_exams_active_default';

    private const INDEX = 'patient_exams_schedule_idx';

    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->boolean('active')->default(true)->change();
        });

        $this->createScheduleIndex();

        $this->candidates()->orderBy('pe.id')->chunkById(500, function ($rows): void {
            DB::transaction(function () use ($rows): void {
                DB::table('patient_exams')->whereIn('id', $rows->pluck('id')->all())->update(['active' => true]);

                DB::table('audit_logs')->insert($rows->map(fn (object $row) => [
                    'id'             => (string) Str::uuid(),
                    'entity_id'      => $row->entity_id,
                    'user_id'        => null,
                    'auditable_type' => PatientExam::class,
                    'auditable_id'   => $row->id,
                    'event'          => 'updated',
                    'old_values'     => json_encode(['active' => false]),
                    'new_values'     => json_encode(['active' => true]),
                    'ip_address'     => null,
                    'user_agent'     => self::MIGRATION_AGENT,
                    'created_at'     => now(),
                ])->all());
            });
        }, 'pe.id', 'id');
    }

    public function down(): void
    {
        // Desfaz só o que este backfill reabilitou e ninguém mexeu depois.
        DB::table('patient_exams as pe')
            ->whereExists(fn (Builder $q) => $q->from('audit_logs as al')
                ->whereColumn('al.auditable_id', 'pe.id')
                ->where('al.auditable_type', PatientExam::class)
                ->where('al.user_agent', self::MIGRATION_AGENT))
            ->where('pe.active', true)
            ->update(['active' => false]);

        DB::table('audit_logs')->where('user_agent', self::MIGRATION_AGENT)->delete();

        $this->dropScheduleIndex();

        Schema::table('patient_exams', function (Blueprint $table) {
            $table->boolean('active')->default(false)->change();
        });
    }

    /** CONCURRENTLY só existe fora de transação (no deploy; num teste com transação aberta, índice comum). */
    private function concurrently(): bool
    {
        return DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 0;
    }

    private function createScheduleIndex(): void
    {
        if ($this->concurrently()) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . ' ON patient_exams (schedule_id)');

            return;
        }

        Schema::table('patient_exams', fn (Blueprint $table) => $table->index('schedule_id', self::INDEX));
    }

    private function dropScheduleIndex(): void
    {
        if ($this->concurrently()) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);

            return;
        }

        Schema::table('patient_exams', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }

    /** Exames do integrador inativos só pelo default, sem desabilitar auditado. */
    private function candidates(): Builder
    {
        return DB::table('patient_exams as pe')
            ->join('patients as pat', 'pat.id', '=', 'pe.patient_id')
            ->where('pe.active', false)
            ->where(fn (Builder $q) => $q->whereNull('pe.source')->orWhere('pe.source', '<>', ExamSource::ExternalImport->value))
            ->where('pe.created_at', '>=', self::REGRESSION_DATE)
            ->whereNotExists(fn (Builder $q) => $q->from('audit_logs as al')
                ->whereColumn('al.auditable_id', 'pe.id')
                ->where('al.auditable_type', PatientExam::class)
                ->where('al.event', 'updated')
                ->whereJsonContainsKey('al.new_values->active'))
            ->select(['pe.id', 'pat.entity_id']);
    }
};
