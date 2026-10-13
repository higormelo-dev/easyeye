<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType, FeatureKey, SubscriptionStatus};
use App\Exceptions\LockedMedicalRecordException;
use App\Models\{Covenant, Entity, MedicalRecord, MedicalRecordDocumentation, MedicalRecordFile, Patient, People, Plan, PlanFeature, Subscription, User};
use Illuminate\Database\Events\{QueryExecuted, TransactionCommitted};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Event, Storage};

uses(RefreshDatabase::class);

/**
 * clinical_revision também muda quando documento/anexo do prontuário muda —
 * achado via revisão adversarial Codex: o token só subia quando a linha do
 * PRÓPRIO prontuário era atualizada. Outro médico emitia ou excluía um laudo
 * depois de a gaveta carregar, o signatário clicava "Assinar" com a revisão
 * antiga e o servidor aceitava: a assinatura trava o conjunto de documentos
 * alterado (e libera o compartilhamento com o paciente) sem que o médico
 * tenha revisado esse conjunto.
 *
 * A revisão sobe dentro da MESMA transação e sob o MESMO lock da linha do
 * prontuário que GuardedBySignedRecord já tira — sign() trava a mesma linha,
 * então quem vem depois vê o resultado do outro (ver o teste de duas conexões
 * em tests/Integration/MedicalRecordSignatureConcurrencyTest.php).
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    // Médica A: quem abre a gaveta e assina. Médico B: outro médico da clínica.
    $this->userA = User::factory()->create();
    $this->euA   = createEntityUser($this->entity, $this->userA, ClientRule::Doctor->value);
    $this->drA   = doctorRecordForEntityUser($this->euA);

    $this->userB = User::factory()->create();
    $this->euB   = createEntityUser($this->entity, $this->userB, ClientRule::Doctor->value);
    $this->drB   = doctorRecordForEntityUser($this->euB);

    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id, 'main_complaint' => 'Consulta',
    ]);

    // O que a gaveta da médica A mostrou ao carregar.
    $this->seenRevision = $this->record->fresh()->clinical_revision;
});

function srdAs(object $test, $entityUser): object
{
    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

function srdSignAs(object $test, $entityUser, int $revision): object
{
    return srdAs($test, $entityUser)->post(
        route('panel.patients.medicalrecords.sign', [$test->patient, $test->record]),
        ['clinical_revision' => $revision],
    );
}

function srdNewDocumentation(object $test): MedicalRecordDocumentation
{
    return MedicalRecordDocumentation::create([
        'medical_record_id' => $test->record->id, 'patient_id' => $test->patient->id, 'doctor_id' => $test->drB->id,
        'type'              => DocumentationType::Report, 'title' => 'Laudo', 'content' => '<p>Laudo</p>',
    ]);
}

function srdNewFile(object $test): MedicalRecordFile
{
    return MedicalRecordFile::create([
        'medical_record_id' => $test->record->id, 'patient_id' => $test->patient->id, 'file_path' => 'x.pdf',
        'original_name'     => 'x.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
    ]);
}

/** Assina com a revisão antiga e confirma que NADA foi assinado. */
function srdExpectStaleSigning(object $test): void
{
    srdSignAs($test, $test->euA, $test->seenRevision)
        ->assertRedirect()
        ->assertSessionHas('error', __('actions.medical_records.sign_stale_revision'));

    expect($test->record->fresh()->isSigned())->toBeFalse()
        ->and($test->record->fresh()->isLocked())->toBeFalse();
}

it('[GAP] laudo emitido por OUTRO médico depois de a gaveta carregar invalida a revisão — assinar com a antiga é rejeitado e nada é assinado', function () {
    srdAs($this, $this->euB)->postJson(route('panel.patients.medicalrecords.tonometry.store', [$this->patient, $this->record]), [
        'od' => 14, 'oe' => 15, 'time' => '10:00',
    ])->assertCreated();

    // Exatamente +1 por escrita de documento (nem 0, nem duplicado por evento).
    expect($this->record->fresh()->clinical_revision)->toBe($this->seenRevision + 1);

    srdExpectStaleSigning($this);

    // Recarregou a gaveta (revisão atual) e revisou o conjunto novo: assina.
    srdSignAs($this, $this->euA, $this->record->fresh()->clinical_revision)->assertSessionHas('success');
    expect($this->record->fresh()->isSigned())->toBeTrue();
});

it('[GAP] documento EXCLUÍDO depois de a gaveta carregar invalida a revisão', function () {
    $doc                = srdNewDocumentation($this);
    $this->seenRevision = $this->record->fresh()->clinical_revision; // a gaveta já mostrava o laudo

    srdAs($this, $this->euB)->deleteJson(route('panel.patients.medicalrecords.documentations.destroy', [$this->patient, $this->record, $doc]))
        ->assertOk();

    expect($this->record->fresh()->clinical_revision)->toBeGreaterThan($this->seenRevision);

    srdExpectStaleSigning($this);
});

it('[GAP] documento ALTERADO depois de a gaveta carregar invalida a revisão', function () {
    $doc                = srdNewDocumentation($this);
    $this->seenRevision = $this->record->fresh()->clinical_revision;

    $doc->update(['title' => 'Laudo corrigido']);

    srdExpectStaleSigning($this);
});

it('[GAP] anexo enviado depois de a gaveta carregar invalida a revisão', function () {
    Storage::fake();

    // O upload exige cota de armazenamento no plano (0 = ilimitado).
    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->limit(FeatureKey::MaxStorageGB, 0)->for($plan)->create();
    Subscription::factory()->create([
        'entity_id' => $this->entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    srdAs($this, $this->euB)->postJson(route('panel.patients.medicalrecords.files.store', [$this->patient, $this->record]), [
        'files' => [UploadedFile::fake()->create('exame.pdf', 10, 'application/pdf')],
    ])->assertCreated();

    srdExpectStaleSigning($this);
});

it('[GAP] anexo EXCLUÍDO depois de a gaveta carregar invalida a revisão', function () {
    $file               = srdNewFile($this);
    $this->seenRevision = $this->record->fresh()->clinical_revision;

    srdAs($this, $this->euB)->deleteJson(route('panel.patients.medicalrecords.files.destroy', [$this->patient, $this->record, $file]))
        ->assertOk();

    srdExpectStaleSigning($this);
});

it('bookkeeping de entrega (saveQuietly) NÃO invalida a revisão — assinar com a revisão que a gaveta mostrou segue funcionando', function () {
    $doc                = srdNewDocumentation($this);
    $this->seenRevision = $this->record->fresh()->clinical_revision;

    // Canal de bookkeeping pós-emissão (EyeImageReportController/ResendController):
    // sem eventos, sem guard — não é conteúdo clínico.
    $doc->forceFill(['requesting_doctor_sent_at' => now()])->saveQuietly();

    expect($this->record->fresh()->clinical_revision)->toBe($this->seenRevision);

    srdSignAs($this, $this->euA, $this->seenRevision)->assertSessionHas('success');
    expect($this->record->fresh()->isSigned())->toBeTrue();
});

it('escrita recusada porque o prontuário já está assinado não mexe na revisão (a transação desfaz)', function () {
    srdSignAs($this, $this->euA, $this->seenRevision)->assertSessionHas('success');
    $signedRevision = $this->record->fresh()->clinical_revision;

    expect(fn () => srdNewDocumentation($this))->toThrow(LockedMedicalRecordException::class);

    expect($this->record->fresh()->clinical_revision)->toBe($signedRevision)
        ->and(MedicalRecordDocumentation::count())->toBe(0);
});

it('mudança em documento de OUTRO prontuário não invalida a revisão deste', function () {
    $other = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->drA->id, 'main_complaint' => 'Outra consulta',
    ]);

    MedicalRecordDocumentation::create([
        'medical_record_id' => $other->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->drB->id,
        'type'              => DocumentationType::Report, 'title' => 'Laudo', 'content' => '<p>x</p>',
    ]);

    expect($this->record->fresh()->clinical_revision)->toBe($this->seenRevision)
        ->and($other->fresh()->clinical_revision)->toBeGreaterThan(1);

    srdSignAs($this, $this->euA, $this->seenRevision)->assertSessionHas('success');
});

it('o incremento da revisão roda DENTRO da transação do guard, depois do lock da linha do prontuário e da escrita do documento', function () {
    $steps = [];

    Event::listen(TransactionCommitted::class, function () use (&$steps) {
        $steps[] = 'commit';
    });
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$steps) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'for update') && str_contains($sql, '"medical_records"')) {
            $steps[] = 'lock';
        } elseif (str_starts_with($sql, 'insert into "medical_record_documentations"')) {
            $steps[] = 'insert';
        } elseif (str_starts_with($sql, 'update "medical_records"') && str_contains($sql, 'clinical_revision')) {
            $steps[] = 'bump';
        }
    });

    srdNewDocumentation($this);

    // Ordem relativa: lock → insert → bump → commit.
    expect($steps)->toContain('lock', 'insert', 'bump');

    $bump = array_search('bump', $steps, true);

    expect(array_search('lock', $steps, true))->toBeLessThan(array_search('insert', $steps, true))
        ->and(array_search('insert', $steps, true))->toBeLessThan($bump)
        // A transação do guard só fecha DEPOIS do incremento — fora dela o
        // incremento ficaria sem o lock da linha do prontuário.
        ->and(array_slice($steps, $bump + 1))->toContain('commit');
});
