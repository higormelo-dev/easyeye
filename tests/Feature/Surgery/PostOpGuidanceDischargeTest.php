<?php

declare(strict_types=1);

use App\Enums\Surgery\{PostOpEvaluationStatus, SurgeryDocumentType, SurgicalCaseStatus};
use App\Models\{DoctorWorkSchedule, Medicine, PatientAllergy, Schedule};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase, SurgicalDescription, SurgicalPostopEvaluation, SurgicalTeamMember};
use App\Services\Surgery\PostOp\{PostOpReturnService, SurgeryDischargeService};
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Módulo cirúrgico (Fase 4) / M5 — colírios e orientações pós-operatórias
 * (documento congelado + trava de alergia) e a alta cirúrgica (D1 + descrição
 * finalizadas, só o cirurgião, idempotente, cancela os retornos abertos).
 *
 * Relógio fixo: quinta-feira, 15/10/2026, 10:00.
 */

function sgdPerformed(object $w, array $over = []): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id'        => (string) $w->patient->id,
        'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'             => 'Facoemulsificação com LIO',
        'eye'               => 'od',
        'procedures'        => [['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019']],
    ], $over));

    $case->forceFill(['status' => SurgicalCaseStatus::Performed, 'performed_at' => now()])->save();

    return $case->refresh();
}

/** Avaliação D1 FINALIZADA direto no banco (a regra clínica já é testada em PostOpEvaluationTest). */
function sgdFinalizedEvaluation(SurgicalCase $case, string $milestone = 'd1', string $eye = 'od'): SurgicalPostopEvaluation
{
    $e = SurgicalPostopEvaluation::query()->create([
        'entity_id'    => $case->entity_id, 'surgical_case_id' => $case->id, 'patient_id' => $case->patient_id,
        'doctor_id'    => $case->surgeon_doctor_id, 'milestone' => $milestone, 'eye' => $eye,
        'evaluated_at' => now(), 'iop_mmhg' => 14, 'findings' => [], 'severe' => false,
    ]);

    DB::table('surgical_postop_evaluations')->where('id', $e->id)->update(['status' => 'finalized', 'finalized_at' => now(), 'content_hash' => str_repeat('a', 64)]);

    return $e->refresh();
}

function sgdFinalizedDescription(SurgicalCase $case): void
{
    $d = SurgicalDescription::query()->create([
        'entity_id'         => $case->entity_id, 'surgical_case_id' => $case->id, 'patient_id' => $case->patient_id,
        'surgeon_doctor_id' => $case->surgeon_doctor_id,
    ]);

    DB::table('surgical_descriptions')->where('id', $d->id)->update(['status' => 'finalized', 'finalized_at' => now(), 'content_hash' => str_repeat('b', 64)]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();

    foreach ([1, 2, 3, 4, 5] as $day) {
        DoctorWorkSchedule::query()->create(['doctor_id' => $this->w->doctor->id, 'day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);
    }

    $this->w->doctor->update(['schedule_interval' => 30]);
    $this->case = sgdPerformed($this->w);
});

// ── Colírios e orientações ─────────────────────────────────────────────────

it('emite colírios + orientações como documento congelado do tipo pós-operatório', function () {
    $res = surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), [
        'guidance_html' => '<p>Não esfregue o olho.</p><script>alert(1)</script>',
        'eyedrops'      => [
            ['name' => 'Colírio antibiótico', 'posology' => '1 gota 4x/dia', 'duration' => '7 dias', 'taper' => 'Sem desmame', 'eye' => 'od'],
            ['name' => '', 'posology' => ''], // linha em branco é ignorada
        ],
    ])->assertCreated();

    $issue = SurgeryDocumentIssue::query()->findOrFail($res->json('id'));

    expect($issue->type)->toBe(SurgeryDocumentType::PostOpGuidance)
        ->and($issue->content)->toContain('Colírio antibiótico')->toContain('1 gota 4x/dia')->toContain('Não esfregue o olho.')
        ->and($issue->content)->not->toContain('<script')
        ->and($issue->payload['data']['eyedrops'])->toHaveCount(1)
        ->and($issue->payload['data']['safety_override'])->toBeNull()
        ->and($issue->content_hash)->toHaveLength(64)
        ->and((string) $issue->issued_by_doctor_id)->toBe((string) $this->w->doctor->id);
});

it('reemitir aposenta a anterior; o mesmo conteúdo em seguida devolve a emissão existente', function () {
    $post = fn (array $body) => surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), $body);
    $base = ['eyedrops' => [['name' => 'Colírio A', 'posology' => '1 gota 3x/dia']]];

    $first  = $post($base)->assertCreated()->json('id');
    $again  = $post($base)->assertCreated()->json('id');
    $second = $post(['eyedrops' => [['name' => 'Colírio B', 'posology' => '1 gota 2x/dia']]])->assertCreated()->json('id');

    expect($again)->toBe($first)
        ->and(SurgeryDocumentIssue::query()->count())->toBe(2)
        ->and(SurgeryDocumentIssue::query()->findOrFail($first)->superseded_by_id)->toBe($second);
});

it('alergia ativa ao princípio do colírio recusa (422) sem motivo; com motivo segue e o motivo fica no documento', function () {
    Medicine::query()->create(['name' => 'MAXITROL', 'active_ingredient' => 'DEXAMETASONA + SULFATO DE NEOMICINA', 'pharmaceutical_form' => 'susp_oft', 'is_ophthalmic' => true, 'active' => true]);
    $maxitrol = Medicine::query()->firstOrFail();

    PatientAllergy::query()->create([
        'entity_id' => $this->w->entity->id, 'patient_id' => $this->w->patient->id, 'category' => 'medication',
        'substance' => 'Neomicina', 'reaction' => 'Urticária', 'severity' => 'severe', 'status' => 'reported',
    ]);

    $body = ['eyedrops' => [['name' => 'Maxitrol', 'medicine_id' => (string) $maxitrol->id, 'posology' => '1 gota 4x/dia']]];

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('allergy_override_reason')
        ->assertJsonStructure(['allergy_conflicts']);

    expect(SurgeryDocumentIssue::query()->count())->toBe(0);

    $res = surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), $body + ['allergy_override_reason' => 'Benefício supera o risco; paciente ciente.'])
        ->assertCreated();

    $issue = SurgeryDocumentIssue::query()->findOrFail($res->json('id'));

    expect($issue->payload['data']['safety_override']['reason'])->toBe('Benefício supera o risco; paciente ciente.')
        ->and($issue->payload['data']['safety_override']['conflicts'])->not->toBeEmpty();
});

it('alergia citada só no texto das orientações (sem colírio estruturado) também recusa; com motivo segue e o motivo fica no documento', function () {
    PatientAllergy::query()->create([
        'entity_id' => $this->w->entity->id, 'patient_id' => $this->w->patient->id, 'category' => 'medication',
        'substance' => 'Neomicina', 'reaction' => 'Urticária', 'severity' => 'severe', 'status' => 'reported',
    ]);

    $body = ['guidance_html' => '<p>Aplicar pomada de <strong>neomicina</strong> 3 vezes ao dia por 7 dias.</p>'];

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('allergy_override_reason')
        ->assertJsonPath('allergy_conflicts.0.substance', 'Neomicina');

    expect(SurgeryDocumentIssue::query()->count())->toBe(0);

    $res = surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), $body + ['allergy_override_reason' => 'Paciente ciente; teste de contato negativo.'])
        ->assertCreated();

    $issue = SurgeryDocumentIssue::query()->findOrFail($res->json('id'));

    expect($issue->payload['data']['safety_override']['reason'])->toBe('Paciente ciente; teste de contato negativo.')
        ->and($issue->payload['data']['safety_override']['conflicts'][0]['match'])->toBe('text');
});

it('o desmame e a duração do colírio também passam pela trava de alergia; orientação comum não gera falso positivo', function () {
    PatientAllergy::query()->create([
        'entity_id' => $this->w->entity->id, 'patient_id' => $this->w->patient->id, 'category' => 'medication',
        'substance' => 'Neomicina', 'reaction' => 'Urticária', 'severity' => 'severe', 'status' => 'reported',
    ]);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), [
        'eyedrops' => [['name' => 'Colírio antibiótico', 'posology' => '1 gota 4x/dia', 'taper' => 'Na 2ª semana trocar por neomicina 2x/dia']],
    ])->assertStatus(422)->assertJsonValidationErrors('allergy_override_reason');

    // Texto sem a substância (nem classe, nem marca): emite sem pedir motivo.
    $res = surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), [
        'guidance_html' => '<p>Não esfregue o olho e use o protetor ocular para dormir.</p>',
    ])->assertCreated();

    expect(SurgeryDocumentIssue::query()->findOrFail($res->json('id'))->payload['data']['safety_override'])->toBeNull();
});

it('exige colírio completo, olho operado, medicamento do catálogo e algum conteúdo', function () {
    $post = fn (array $body) => surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), $body);

    $post([])->assertStatus(422);
    $post(['eyedrops' => [['name' => 'Só nome']]])->assertStatus(422);
    $post(['eyedrops' => [['name' => 'Colírio', 'posology' => '1 gota', 'eye' => 'oe']]])->assertStatus(422); // caso é OD
    $post(['eyedrops' => [['name' => 'Colírio', 'posology' => '1 gota', 'medicine_id' => '01a13d57-e927-7074-8d8c-35fcf5ab443c']]])->assertStatus(422);

    expect(SurgeryDocumentIssue::query()->count())->toBe(0);
});

it('só o médico emite; só depois da cirurgia; caso de outra clínica é 404', function () {
    $body = ['eyedrops' => [['name' => 'Colírio', 'posology' => '1 gota 4x/dia']]];

    foreach (['secretary', 'admin', 'financial'] as $who) {
        surgeryAs($this->w, $who)->postJson(route('panel.surgery.postop.guidance.store', $this->case), $body)->assertForbidden();
    }

    $draft = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Rascunho', 'eye' => 'od', 'procedures' => [['description' => 'Procedimento']],
    ]);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $draft), $body)->assertStatus(409);

    $other = sgdPerformed(surgeryWorld());
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $other), $body)->assertNotFound();
});

it('o PDF sai só do retrato congelado e só para quem participa do caso', function () {
    $id = surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.guidance.store', $this->case), [
        'eyedrops' => [['name' => 'Colírio', 'posology' => '1 gota 4x/dia']],
    ])->assertCreated()->json('id');

    $url = route('panel.surgery.postop.documents.pdf', ['surgicalCase' => $this->case, 'document' => $id]);

    surgeryAs($this->w, 'doctor')->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Documento de outro tipo não sai por esta rota.
    $consent = SurgeryDocumentIssue::query()->findOrFail($id)->replicate(['id', 'superseded_by_id']);
    DB::table('surgery_document_issues')->insert([...$consent->getAttributes(), 'id' => (string) Str::uuid(), 'type' => 'consent', 'payload' => json_encode($consent->payload), 'created_at' => now(), 'updated_at' => now(), 'issued_at' => now()]);
    $consentId = DB::table('surgery_document_issues')->where('type', 'consent')->value('id');

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.postop.documents.pdf', ['surgicalCase' => $this->case, 'document' => $consentId]))->assertNotFound();

    speGuidanceOtherDoctor($this->w);
    test()->get($url)->assertForbidden();
});

function speGuidanceOtherDoctor(object $w): void
{
    $doctor = createDoctorForEntity($w->entity);
    test()->actingAs($doctor->entityUser->user)->withSession(panelSession($doctor->entityUser));
}

// ── Alta ────────────────────────────────────────────────────────────────────

it('lista o que falta para a alta: D1 do olho, descrição cirúrgica e rascunhos', function () {
    $service = app(SurgeryDischargeService::class);

    expect($service->blockers($this->case))->toHaveCount(2);

    sgdFinalizedEvaluation($this->case);
    expect($service->blockers($this->case))->toHaveCount(1);

    sgdFinalizedDescription($this->case);
    expect($service->blockers($this->case))->toBe([]);

    SurgicalPostopEvaluation::query()->create([
        'entity_id' => $this->case->entity_id, 'surgical_case_id' => $this->case->id, 'patient_id' => $this->case->patient_id,
        'doctor_id' => $this->case->surgeon_doctor_id, 'milestone' => 'd7', 'eye' => 'od', 'evaluated_at' => now(), 'findings' => [],
    ]);

    expect($service->blockers($this->case))->toHaveCount(1);
});

it('recusa a alta sem D1 finalizado, sem descrição ou com rascunho pendente — e nada muda', function () {
    $payload = ['discharge_text' => 'Paciente evoluiu bem, sem intercorrências.', 'follow_up_plan' => 'Retorno em 6 meses.'];

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), $payload)->assertStatus(409);
    expect($this->case->refresh()->status)->toBe(SurgicalCaseStatus::Performed)->and(SurgeryDocumentIssue::query()->count())->toBe(0);

    sgdFinalizedEvaluation($this->case);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), $payload)->assertStatus(409);

    sgdFinalizedDescription($this->case);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), [...$payload, 'follow_up_plan' => ''])->assertStatus(422);

    expect($this->case->refresh()->status)->toBe(SurgicalCaseStatus::Performed);
});

it('caso de ambos os olhos exige o D1 dos dois', function () {
    $both = sgdPerformed($this->w, ['eye' => 'ao']);
    sgdFinalizedDescription($both);
    sgdFinalizedEvaluation($both, 'd1', 'od');

    expect(app(SurgeryDischargeService::class)->blockers($both))->toHaveCount(1);

    sgdFinalizedEvaluation($both, 'd1', 'oe');
    expect(app(SurgeryDischargeService::class)->blockers($both))->toBe([]);
});

it('a alta: documento congelado, estado, data e retornos futuros cancelados — na mesma ação', function () {
    app(PostOpReturnService::class)->plan((string) $this->case->id);
    sgdFinalizedEvaluation($this->case);
    sgdFinalizedDescription($this->case);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), [
        'discharge_text' => 'Paciente evoluiu bem, sem intercorrências.',
        'follow_up_plan' => 'Retorno anual com mapeamento de retina.',
    ])->assertOk()->assertJsonPath('status', 'discharged');

    $this->case->refresh();
    $doc = SurgeryDocumentIssue::query()->where('type', 'discharge')->firstOrFail();

    expect($this->case->status)->toBe(SurgicalCaseStatus::Discharged)
        ->and($this->case->discharged_at)->not->toBeNull()
        ->and($doc->content)->toContain('Paciente evoluiu bem')->toContain('Retorno anual')
        // Data e PIO no formato do idioma (pt: dd/mm/aaaa e vírgula decimal).
        ->and($doc->content)->toContain(now()->format('d/m/Y'))->toContain('14,0 mmHg')
        ->and($doc->payload['data']['follow_up_plan'])->toBe('Retorno anual com mapeamento de retina.')
        ->and($doc->content_hash)->toHaveLength(64)
        ->and(collect($this->case->status_history)->last()['to'])->toBe('discharged')
        ->and(Schedule::query()->where('surgical_case_id', $this->case->id)->where('situation', 9)->count())->toBe(3);
});

it('a alta é idempotente: a segunda chamada devolve o mesmo caso, sem segundo documento', function () {
    sgdFinalizedEvaluation($this->case);
    sgdFinalizedDescription($this->case);
    $payload = ['discharge_text' => 'Paciente evoluiu bem, sem intercorrências.', 'follow_up_plan' => 'Retorno anual.'];

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), $payload)->assertOk();
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), $payload)->assertOk()->assertJsonPath('status', 'discharged');

    expect(SurgeryDocumentIssue::query()->where('type', 'discharge')->count())->toBe(1);
});

it('só o cirurgião do caso (ou da equipe) dá a alta; secretária e outro médico não', function () {
    sgdFinalizedEvaluation($this->case);
    sgdFinalizedDescription($this->case);
    $payload = ['discharge_text' => 'Paciente evoluiu bem, sem intercorrências.', 'follow_up_plan' => 'Retorno anual.'];

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.postop.discharge', $this->case), $payload)->assertForbidden();

    $colleague = createDoctorForEntity($this->w->entity);
    SurgicalTeamMember::query()->create([
        'entity_id' => $this->w->entity->id, 'surgical_case_id' => $this->case->id, 'role' => 'assistant', 'doctor_id' => $colleague->id, 'name' => 'AUX',
    ]);

    test()->actingAs($colleague->entityUser->user)->withSession(panelSession($colleague->entityUser))
        ->postJson(route('panel.surgery.postop.discharge', $this->case), $payload)->assertForbidden();

    expect($this->case->refresh()->status)->toBe(SurgicalCaseStatus::Performed);
});

it('a aba Alta mostra o que falta e, depois, o documento emitido', function () {
    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $this->case))
        ->assertInertia(fn ($page) => $page->has('sections.discharge.blockers', 2)->where('sections.discharge.can_discharge', true)->where('sections.discharge.document', null));

    sgdFinalizedEvaluation($this->case);
    sgdFinalizedDescription($this->case);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), [
        'discharge_text' => 'Paciente evoluiu bem, sem intercorrências.', 'follow_up_plan' => 'Retorno anual.',
    ])->assertOk();

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $this->case))
        ->assertInertia(fn ($page) => $page->where('sections.discharge.discharged', true)->where('sections.discharge.can_discharge', false)->has('sections.discharge.document.pdf_url'));
});

it('depois da alta não se cria nem finaliza avaliação (só adendo)', function () {
    $e = sgdFinalizedEvaluation($this->case);
    sgdFinalizedDescription($this->case);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.discharge', $this->case), [
        'discharge_text' => 'Paciente evoluiu bem, sem intercorrências.', 'follow_up_plan' => 'Retorno anual.',
    ])->assertOk();

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.evaluations.store', $this->case), [
        'milestone' => 'd30', 'eye' => 'od', 'evaluated_at' => '2026-10-15 09:30:00', 'iop_mmhg' => 12,
    ])->assertStatus(409);

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.evaluations.addenda', ['surgicalCase' => $this->case, 'evaluation' => $e]), ['text' => 'Complemento após a alta.'])
        ->assertCreated();

    expect(PostOpEvaluationStatus::Finalized->value)->toBe('finalized');
});
