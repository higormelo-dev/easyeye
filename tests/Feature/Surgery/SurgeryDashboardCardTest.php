<?php

declare(strict_types=1);

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;

/** Fase 4 / M5 — cartão cirúrgico do Dashboard: só admin e médico, só com o recurso do plano, só agregados. */
function sdcCase(object $w, string $doctorId, SurgicalCaseStatus $status): void
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, [
        'patient_id' => (string) $w->patient->id, 'surgeon_doctor_id' => $doctorId,
        'title'      => 'Faco', 'eye' => 'od', 'procedures' => [['description' => 'Faco']],
    ]);
    $case->forceFill(['status' => $status, 'performed_at' => $status === SurgicalCaseStatus::Performed ? now()->subDays(3) : null])->save();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w     = surgeryWorld();
    $this->other = createDoctorForEntity($this->w->entity);
});

it('admin e médico recebem o cartão e a seção "surgery"; o médico só com os próprios casos', function () {
    sdcCase($this->w, (string) $this->w->doctor->id, SurgicalCaseStatus::Performed);
    sdcCase($this->w, (string) $this->other->id, SurgicalCaseStatus::Performed);
    sdcCase($this->w, (string) $this->other->id, SurgicalCaseStatus::AwaitingAuthorization);

    surgeryAs($this->w, 'admin')->get(route('panel.dashboard'))->assertInertia(fn ($page) => $page
        ->where('surgeryCard.surgeries', 2)
        ->where('surgeryCard.awaiting', 1)
        ->has('surgeryCard.labels.title')
        ->where('surgeryCard.url', route('panel.surgery.indicators'))
        ->where('sections', fn ($sections) => in_array('surgery', $sections->toArray(), true)));

    surgeryAs($this->w, 'doctor')->get(route('panel.dashboard'))->assertInertia(fn ($page) => $page
        ->where('surgeryCard.surgeries', 1)
        ->where('surgeryCard.awaiting', 0));
});

it('secretária e financeiro não recebem o cartão', function () {
    foreach (['secretary', 'financial'] as $who) {
        surgeryAs($this->w, $who)->get(route('panel.dashboard'))->assertInertia(fn ($page) => $page
            ->where('surgeryCard', null)
            ->where('sections', fn ($sections) => ! in_array('surgery', $sections->toArray(), true)));
    }
});

it('clínica sem o recurso do plano não recebe o cartão', function () {
    $w = surgeryWorld(withModule: false);

    surgeryAs($w, 'admin')->get(route('panel.dashboard'))->assertInertia(fn ($page) => $page
        ->where('surgeryCard', null)
        ->where('sections', fn ($sections) => ! in_array('surgery', $sections->toArray(), true)));
});
