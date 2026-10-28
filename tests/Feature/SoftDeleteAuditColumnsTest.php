<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{AuditLog, Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSetting, ReportSettingContent, User};
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Support\Facades\Schema;

/*
 * Exclusão lógica sem `deleted_by` (erro 500): HasAuditColumns grava
 * `deleted_by` no `deleting` de todo model com SoftDeletes. Duas tabelas
 * nasceram sem a coluna (medical_record_documentations e
 * report_setting_contents) e toda exclusão estourava SQLSTATE 42703.
 *
 * O primeiro teste é a trava GERAL: percorre app/Models inteiro e falha se
 * qualquer model auditado com exclusão lógica não tiver as colunas — o gap
 * não volta com um model novo.
 */

/** @return list<class-string<Model>> */
function f0SoftDeleteAuditedModels(): array
{
    $base    = app_path('Models');
    $classes = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($base) + 1, -4);
        $class    = 'App\\Models\\' . str_replace('/', '\\', $relative);

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract() || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $traits = class_uses_recursive($class);

        if (in_array(SoftDeletes::class, $traits, true) && in_array(HasAuditColumns::class, $traits, true)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('todo model com SoftDeletes + HasAuditColumns tem created_by, updated_by e deleted_by na tabela', function () {
    $models = f0SoftDeleteAuditedModels();

    // Sanidade: a varredura achou os models (inclusive os dois do bug).
    expect($models)->toContain(MedicalRecordDocumentation::class, ReportSettingContent::class)
        ->and(count($models))->toBeGreaterThan(20);

    $missing = [];

    foreach ($models as $class) {
        $table = (new $class())->getTable();

        foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $missing[] = "{$class} ({$table}.{$column})";
            }
        }
    }

    expect($missing)->toBe([]);
});

describe('exclusão de documentação do prontuário', function () {
    beforeEach(function () {
        $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $this->user   = User::factory()->create();
        $entityUser   = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

        $this->doctor = Doctor::create([
            'entity_user_id' => $entityUser->id,
            'person_id'      => People::factory()->create()->id,
            'active'         => true,
        ]);

        $this->patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => People::factory()->create()->id,
            'covenant_id' => Covenant::factory()->create(['active' => true])->id,
            'active'      => true,
        ]);

        $this->record = MedicalRecord::create([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'main_complaint' => 'Baixa visual',
        ]);

        $this->documentation = MedicalRecordDocumentation::create([
            'medical_record_id' => $this->record->id,
            'patient_id'        => $this->patient->id,
            'doctor_id'         => $this->doctor->id,
            'type'              => DocumentationType::Report,
            'title'             => 'Declaração',
            'content'           => '<p>Declaro.</p>',
        ]);

        $this->entityUser = $entityUser;
    });

    it('exclui (200), grava deleted_by e registra a auditoria', function () {
        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->deleteJson(route('panel.patients.medicalrecords.documentations.destroy', [$this->patient, $this->record, $this->documentation]))
            ->assertOk()
            ->assertJsonPath('message', __('actions.medical_records.documentation_deleted'));

        $row = MedicalRecordDocumentation::withTrashed()->find($this->documentation->id);

        expect($row->trashed())->toBeTrue()
            ->and($row->deleted_by)->toBe($this->user->id)
            ->and(AuditLog::query()
                ->where('auditable_type', MedicalRecordDocumentation::class)
                ->where('auditable_id', $this->documentation->id)
                ->where('event', 'deleted')
                ->exists())->toBeTrue();
    });

    it('prontuário assinado continua impedindo excluir a documentação', function () {
        $this->record->sign($this->entityUser);

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->deleteJson(route('panel.patients.medicalrecords.documentations.destroy', [$this->patient, $this->record, $this->documentation]))
            ->assertForbidden()
            ->assertJsonPath('message', __('actions.medical_records.documentation_locked'));

        expect(MedicalRecordDocumentation::find($this->documentation->id))->not->toBeNull();
    });
});

it('remover um modelo de documento do template soft-deleta o conteúdo com deleted_by (sem 500)', function () {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $admin  = User::factory()->create();
    $member = createEntityUser($entity, $admin, ClientRule::Admin->value);

    $setting = ReportSetting::query()->create(['entity_id' => $entity->id, 'title' => 'ATESTADOS DA CLÍNICA']);
    $keep    = $setting->contents()->create(['type' => DocumentationType::Certificate, 'label' => 'Mantido', 'content' => '<p>A</p>']);
    $drop    = $setting->contents()->create(['type' => DocumentationType::Certificate, 'label' => 'Removido', 'content' => '<p>B</p>']);

    $this->actingAs($admin)
        ->withSession(panelSession($member))
        ->put(route('panel.setting.report-settings.update', $setting), [
            'title'    => 'ATESTADOS DA CLÍNICA',
            'contents' => [
                ['id' => $keep->id, 'type' => 'certificate', 'label' => 'Mantido', 'content' => '<p>A</p>', 'active' => true],
            ],
        ])
        ->assertRedirect();

    $deleted = ReportSettingContent::withTrashed()->find($drop->id);

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->deleted_by)->toBe($admin->id)
        ->and(ReportSettingContent::find($keep->id))->not->toBeNull();
});

it('[SEGURANÇA] salvar o template não altera conteúdo de template de outra clínica pelo id', function () {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $admin  = User::factory()->create();
    $member = createEntityUser($entity, $admin, ClientRule::Admin->value);

    $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherSetting = ReportSetting::query()->create(['entity_id' => $other->id, 'title' => 'MODELO ALHEIO']);
    $foreign      = $otherSetting->contents()->create(['type' => DocumentationType::Certificate, 'label' => 'Alheio', 'content' => '<p>Original</p>']);

    $setting = ReportSetting::query()->create(['entity_id' => $entity->id, 'title' => 'MEU MODELO']);

    $this->actingAs($admin)
        ->withSession(panelSession($member))
        ->put(route('panel.setting.report-settings.update', $setting), [
            'title'    => 'MEU MODELO',
            'contents' => [
                ['id' => $foreign->id, 'type' => 'certificate', 'label' => 'Sequestrado', 'content' => '<p>Trocado</p>', 'active' => true],
            ],
        ])
        ->assertRedirect();

    expect($foreign->fresh())
        ->label->toBe('Alheio')
        ->content->toBe('<p>Original</p>')
        ->report_setting_id->toBe($otherSetting->id);
});
