<?php

/*
 * Migration 2026_09_30_100000: exames do integrador criados desde o refactor de
 * 02/02/2026 nasceram inativos por engano (default da coluna). O backfill
 * reabilita só os que ninguém desabilitou (desabilitar sempre gera audit_log
 * com `active`), mantém importação externa e exames antigos como estão e
 * deixa um audit_log por exame reabilitado; o down desfaz só esses.
 */

use App\Models\{Entity, ExamType, Patient, PatientExam};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_09_30_100000_restore_patient_exams_active_default.php');
    $this->migration->down();

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->type    = ExamType::factory()->create();
});

function restoreActiveExam($test, array $attrs): PatientExam
{
    $exam = PatientExam::factory()->create(['patient_id' => $test->patient->id, 'exam_id' => $test->type->id] + $attrs);
    DB::table('patient_exams')->where('id', $exam->id)->update(['active' => $attrs['active'] ?? false, 'created_at' => $attrs['created_at'] ?? '2026-06-10 10:00:00']);

    return $exam;
}

it('reabilita só os exames inativos por engano, com trilha de auditoria, e o down desfaz só esses', function () {
    $stuck     = restoreActiveExam($this, ['source' => 'integrator', 'active' => false]);
    $disabled  = restoreActiveExam($this, ['source' => 'integrator', 'active' => false]);
    $external  = restoreActiveExam($this, ['source' => 'external_import', 'active' => false]);
    $legacy    = restoreActiveExam($this, ['source' => 'integrator', 'active' => false, 'created_at' => '2026-01-15 10:00:00']);
    $alreadyOn = restoreActiveExam($this, ['source' => 'integrator', 'active' => true]);

    // Desabilitado de propósito no Gerenciador de Imagens (audit_log com `active`).
    DB::table('audit_logs')->insert([
        'id'             => (string) Str::uuid(), 'entity_id' => $this->entity->id,
        'auditable_type' => PatientExam::class, 'auditable_id' => $disabled->id, 'event' => 'updated',
        'old_values'     => json_encode(['active' => true]), 'new_values' => json_encode(['active' => false]), 'created_at' => now(),
    ]);

    $this->migration->up();

    expect((bool) $stuck->fresh()->active)->toBeTrue()
        ->and((bool) $disabled->fresh()->active)->toBeFalse()
        ->and((bool) $external->fresh()->active)->toBeFalse()
        ->and((bool) $legacy->fresh()->active)->toBeFalse()
        ->and((bool) $alreadyOn->fresh()->active)->toBeTrue();

    $trail = DB::table('audit_logs')->where('user_agent', 'like', 'migration:2026_09_30_100000%')->get();
    expect($trail)->toHaveCount(1)
        ->and($trail->first()->auditable_id)->toBe($stuck->id)
        ->and($trail->first()->entity_id)->toBe($this->entity->id);

    // Novo exame sem informar `active`: nasce habilitado pelo default da coluna.
    $fresh = DB::table('patient_exams')->insertGetId([
        'id'   => (string) Str::uuid(), 'patient_id' => $this->patient->id, 'exam_id' => $this->type->id,
        'code' => 'MIG-DEFAULT-1', 'name' => 'x', 'archive' => 'x.jpg', 'created_at' => now(), 'updated_at' => now(),
    ], 'id');
    expect((bool) DB::table('patient_exams')->where('id', $fresh)->value('active'))->toBeTrue();

    $this->migration->down();

    expect((bool) $stuck->fresh()->active)->toBeFalse()
        ->and((bool) $alreadyOn->fresh()->active)->toBeTrue()
        ->and(DB::table('audit_logs')->where('user_agent', 'like', 'migration:2026_09_30_100000%')->count())->toBe(0);

    $this->migration->up(); // estado final igual ao das outras suítes
});
