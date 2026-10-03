<?php

use App\Enums\ClientRule;
use App\Models\{Entity, Patient, PatientExam, User};
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->membership = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $this->patient    = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->exam       = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'created_at' => now()->subMonth()]);
    $this->actingAs($this->user)->withSession(panelSession($this->membership));
});

it('loads the requested exam outside default date filters and pagination', function () {
    $this->get(route('panel.eye-images.index', ['patient_id' => $this->patient->id, 'exam_id' => $this->exam->id, 'search' => 'no-matching-result']))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Panel/EyeImages/Index')
        ->where('reviewTarget.patient.id', $this->patient->id)
        ->where('reviewTarget.exam_id', $this->exam->id)
        ->where('reviewTarget.patient.exams.0.id', $this->exam->id)
        ->has('reviewTarget.patient.exams', 1));
});

it('refuses foreign tenant, patient-exam mismatch and structured query parameters', function () {
    $other   = Patient::factory()->create(['entity_id' => Entity::factory()->create()->id]);
    $foreign = PatientExam::factory()->create(['patient_id' => $other->id]);
    $this->get(route('panel.eye-images.index', ['patient_id' => $other->id, 'exam_id' => $foreign->id]))->assertNotFound();
    $this->get(route('panel.eye-images.index', ['patient_id' => $this->patient->id, 'exam_id' => $foreign->id]))->assertNotFound();
    $this->get(route('panel.eye-images.index', ['patient_id' => ['bad'], 'exam_id' => $this->exam->id]))->assertNotFound();
});
