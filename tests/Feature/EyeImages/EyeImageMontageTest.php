<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Patient, PatientExam, User};
use App\Services\EyeImages\MontageService;
use Illuminate\Support\Facades\Storage;

/**
 * Montage (colagem de imagens) — benchmark 18/09/2026, EyeImageMontageController.
 *
 * O teste do "caminho feliz" (PNG de verdade gerado) só roda quando a
 * extensão Imagick está disponível no PHP que executa a suíte — ver
 * MontageService::isAvailable(). Sem Imagick, o endpoint degrada com 422 +
 * mensagem clara (nunca 500) — esse caminho é sempre testado, é o que este
 * ambiente de desenvolvimento realmente executa hoje (Imagick não está no
 * Dockerfile do projeto — achado reportado à parte).
 */
beforeEach(function () {
    Storage::fake('s3');

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    $this->staffUser       = User::factory()->create();
    $this->staffEntityUser = createEntityUser($this->entity, $this->staffUser, ClientRule::Secretary->value);

    Storage::disk('s3')->put('exams/a.jpg', 'fake-jpeg-bytes-a');
    Storage::disk('s3')->put('exams/b.jpg', 'fake-jpeg-bytes-b');

    $this->examA = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/a.jpg']);
    $this->examB = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/b.jpg']);
});

function actingAsMontageStaff($test)
{
    return $test->actingAs($test->staffUser)->withSession(panelSession($test->staffEntityUser));
}

it('secretária (não-clínico) tem acesso — Montage não é ato clínico', function () {
    $response = actingAsMontageStaff($this)->postJson(route('panel.eye-images.montage.store'), [
        'exam_ids' => [$this->examA->id, $this->examB->id],
    ]);

    // Sem Imagick: 422 com mensagem clara. Com Imagick: 200 + PNG. Nunca 403/500.
    expect($response->status())->toBeIn([200, 422]);
});

it('rejeita menos de 2 imagens (422)', function () {
    actingAsMontageStaff($this)->postJson(route('panel.eye-images.montage.store'), [
        'exam_ids' => [$this->examA->id],
    ])->assertStatus(422);
});

it('[SEGURANÇA] exam_id de outra clínica é rejeitado com 403', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id, 'archive' => 'exams/other.jpg']);

    actingAsMontageStaff($this)->postJson(route('panel.eye-images.montage.store'), [
        'exam_ids' => [$this->examA->id, $otherExam->id],
    ])->assertForbidden();
});

it('sem Imagick disponível, devolve 422 com mensagem clara (nunca 500)', function () {
    if (app(MontageService::class)->isAvailable()) {
        $this->markTestSkipped('Imagick disponível neste ambiente — cenário de indisponibilidade não se aplica.');
    }

    $response = actingAsMontageStaff($this)->postJson(route('panel.eye-images.montage.store'), [
        'exam_ids' => [$this->examA->id, $this->examB->id],
    ]);

    $response->assertStatus(422)->assertJsonPath('message', __('eye_images.montage_unavailable'));
});

it('[Imagick] gera um PNG de verdade a partir de 2 imagens', function () {
    if (! app(MontageService::class)->isAvailable()) {
        $this->markTestSkipped('Imagick não disponível neste ambiente.');
    }

    // Precisa de bytes de imagem de verdade pro Imagick decodificar — um
    // JPEG 1x1 mínimo válido, não os bytes fake do beforeEach.
    $pixel = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');
    Storage::disk('s3')->put('exams/a.jpg', $pixel);
    Storage::disk('s3')->put('exams/b.jpg', $pixel);

    $response = actingAsMontageStaff($this)->postJson(route('panel.eye-images.montage.store'), [
        'exam_ids' => [$this->examA->id, $this->examB->id],
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png');
    expect(strlen($response->getContent()))->toBeGreaterThan(0);
});
