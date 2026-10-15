<?php

declare(strict_types=1);

use App\Enums\Surgery\{ChecklistPhase, SurgeryDocumentType, SurgicalCaseStatus};
use App\Models\Surgery\{SurgicalChecklistPhase, SurgicalOperation};
use App\Services\Surgery\Guards\{ChecklistTimeOutGuard, ConsentSignedGuard};
use App\Services\Surgery\SurgeryDocumentIssueService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/OperationFixtures.php';

/*
 * Jornada do módulo cirúrgico com as condições REAIS de config('surgery.
 * perform_guards') ligadas (os testes de cada etapa as isolam): registrar a
 * cirurgia exige Pausa Cirúrgica fechada, termo de consentimento com a cópia
 * assinada e autorização válida — cada uma só cede à justificativa do
 * cirurgião, que fica gravada.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    Storage::fake('local');
    $this->w = surgeryWorld();
});

function sjgPerform(object $t, $case, array $body = [])
{
    return surgeryAs($t->w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), array_merge(['team_confirmed' => true], $body));
}

function sjgCloseTimeOut($case): void
{
    SurgicalChecklistPhase::query()->forceCreate([
        'entity_id'       => $case->entity_id, 'surgical_case_id' => $case->id, 'phase' => ChecklistPhase::TimeOut->value,
        'catalog_version' => '2026.1', 'locale' => 'pt_BR', 'opened_at' => now(), 'closed_at' => now(), 'content_hash' => str_repeat('a', 64),
    ]);
}

function sjgSignedConsent(object $w, $case): void
{
    surgeryAs($w, 'doctor');
    $issue = app(SurgeryDocumentIssueService::class)->issue($case, SurgeryDocumentType::Consent, 'TCLE', '<p>termo</p>');
    app(SurgeryDocumentIssueService::class)->attachSignedCopy($issue, UploadedFile::fake()->createWithContent('termo.pdf', "%PDF-1.4\n%fake\n"));
}

it('com as condições reais ligadas, sem Pausa Cirúrgica nem termo assinado a cirurgia NÃO é registrada', function () {
    $case = sopCase($this->w);

    expect(config('surgery.perform_guards'))->toContain(ChecklistTimeOutGuard::class, ConsentSignedGuard::class);

    sjgPerform($this, $case)->assertStatus(409);

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Scheduled)->and(SurgicalOperation::query()->count())->toBe(0);
});

it('Pausa fechada + termo assinado + autorização válida: registra, sem justificativa gravada', function () {
    $case = sopCase($this->w);
    sjgCloseTimeOut($case);
    sjgSignedConsent($this->w, $case);

    sjgPerform($this, $case)->assertSuccessful();

    $operation = SurgicalOperation::query()->firstOrFail();

    expect($case->refresh()->status)->toBe(SurgicalCaseStatus::Performed)->and($operation->override_reasons)->toBe([]);
});

it('termo sem cópia assinada: só passa com a justificativa do cirurgião, que fica gravada', function () {
    $case = sopCase($this->w);
    sjgCloseTimeOut($case);

    surgeryAs($this->w, 'doctor');
    app(SurgeryDocumentIssueService::class)->issue($case, SurgeryDocumentType::Consent, 'TCLE', '<p>termo</p>');

    sjgPerform($this, $case, ['consent_override_reason' => 'curta'])->assertStatus(409);
    sjgPerform($this, $case, ['consent_override_reason' => 'Paciente assinou em papel; digitalização amanhã'])->assertSuccessful();

    expect(SurgicalOperation::query()->firstOrFail()->override_reasons)->toMatchArray(['consent' => 'Paciente assinou em papel; digitalização amanhã']);
});

it('a tela é avisada do que falta (termo e Pausa) antes de o cirurgião tentar', function () {
    $case = sopCase($this->w, state: ['scheduled_start' => now()->addDay()->setTime(8, 0), 'scheduled_end' => now()->addDay()->setTime(9, 0)]);

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $case))->assertInertia(fn ($page) => $page
        ->where('sections.operation.requirements', fn ($rows) => collect($rows)->pluck('key')->contains('consent') && collect($rows)->firstWhere('key', 'consent')['ok'] === false
            && collect($rows)->firstWhere('key', 'consent')['override'] === 'consent_override_reason'));
});

it('a secretária não registra a cirurgia como realizada (ato do cirurgião)', function () {
    $case = sopCase($this->w);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.operation.perform', $case), ['team_confirmed' => true])->assertForbidden();
});
