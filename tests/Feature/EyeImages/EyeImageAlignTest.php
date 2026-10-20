<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Patient, PatientExam, User};
use App\Services\EyeImages\BoundedVisionProcess;
use Illuminate\Support\Facades\{Cache, Log, Storage};
use Symfony\Component\Process\Process;

/**
 * Auto-alinhar (fase 1, translação) — EyeImageAlignController /
 * ExamAlignmentService. Mesmo desenho do panorama: processo Python fingido no
 * container, um caso por `reason`, e um teste "de verdade" que só roda com
 * isolamento do SO e cv2.
 */
beforeEach(function () {
    Storage::fake('s3');

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    $this->members = [];

    foreach ([ClientRule::Secretary, ClientRule::Doctor, ClientRule::Admin, ClientRule::Financial] as $rule) {
        $user = User::factory()->create();

        $this->members[$rule->value] = [$user, createEntityUser($this->entity, $user, $rule->value)];
    }

    Storage::disk('s3')->put('exams/a.jpg', 'bytes-a');
    Storage::disk('s3')->put('exams/b.jpg', 'bytes-b');

    $this->examA = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/a.jpg']);
    $this->examB = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/b.jpg']);
});

function postAlign($test, array $payload, string $rule = 'secretary')
{
    [$user, $member] = $test->members[$rule];

    return $test->actingAs($user)->withSession(panelSession($member))
        ->postJson(route('panel.eye-images.align.store'), $payload);
}

function alignPayload($test): array
{
    return ['exam_id_a' => $test->examA->id, 'exam_id_b' => $test->examB->id];
}

/** Troca o processo de visão por um mock que executa $align no lugar do script. */
function fakeAlign(Closure $align): void
{
    $mock = Mockery::mock(BoundedVisionProcess::class);
    app()->instance(BoundedVisionProcess::class, $mock);

    $mock->shouldReceive('align')->once()->andReturnUsing($align);
}

if (! function_exists('visionRuntimeReady')) {
    /** Isolamento do SO + python3 com cv2/numpy: o que o teste "de verdade" exige. */
    function visionRuntimeReady(): bool
    {
        if (! (new BoundedVisionProcess())->isAvailable()) {
            return false;
        }

        $probe = new Process([BoundedVisionProcess::PYTHON, '-I', '-c', 'import cv2, numpy']);
        $probe->setTimeout(10);
        $probe->run();

        return $probe->isSuccessful();
    }
}

describe('POST eye-images/align', function () {
    it('devolve dx, dy e confiança do processo (px do natural de A)', function () {
        fakeAlign(fn () => ['dx' => 12.5, 'dy' => -3.0, 'confidence' => 0.83]);

        postAlign($this, alignPayload($this))
            ->assertOk()
            ->assertExactJson(['dx' => 12.5, 'dy' => -3.0, 'confidence' => 0.83]);
    });

    it('admin, médico e secretária alinham; financeiro não (403)', function (string $rule, int $status) {
        if ($status === 200) {
            fakeAlign(fn () => ['dx' => 1.0, 'dy' => 2.0, 'confidence' => 0.9]);
        } else {
            $this->mock(BoundedVisionProcess::class)->shouldNotReceive('align');
        }

        postAlign($this, alignPayload($this), $rule)->assertStatus($status);
    })->with([
        'admin'     => ['admin', 200],
        'doctor'    => ['doctor', 200],
        'secretary' => ['secretary', 200],
        'financial' => ['financial', 403],
    ]);

    it('visitante não alinha (401)', function () {
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('align');

        $this->postJson(route('panel.eye-images.align.store'), alignPayload($this))->assertUnauthorized();
    });

    it('entrega ao script A e B nessa ordem, preferindo o display_archive pronto', function () {
        Storage::disk('s3')->put('exams/a_display.jpg', 'display-a');
        $this->examA->forceFill(['display_archive' => 'exams/a_display.jpg', 'derivative_status' => 'ready'])->save();
        // Derivado ainda não pronto: o original é que vale.
        Storage::disk('s3')->put('exams/b_display.jpg', 'display-b-pendente');
        $this->examB->forceFill(['display_archive' => 'exams/b_display.jpg', 'derivative_status' => 'pending'])->save();

        $seen = [];

        fakeAlign(function (string $a, string $b) use (&$seen) {
            $seen = [file_get_contents($a), file_get_contents($b)];

            return ['dx' => 0.0, 'dy' => 0.0, 'confidence' => 1.0];
        });

        postAlign($this, alignPayload($this))->assertOk();

        expect($seen)->toBe(['display-a', 'bytes-b']);
    });

    it('derivado "pronto" sem display_archive cai no original', function () {
        $this->examA->forceFill(['display_archive' => null, 'derivative_status' => 'ready'])->save();

        $seen = [];

        fakeAlign(function (string $a, string $b) use (&$seen) {
            $seen = [file_get_contents($a), file_get_contents($b)];

            return ['dx' => 0.0, 'dy' => 0.0, 'confidence' => 1.0];
        });

        postAlign($this, alignPayload($this))->assertOk();

        expect($seen)->toBe(['bytes-a', 'bytes-b']);
    });

    it('apaga os dois temporários, no sucesso e na falha', function (bool $fails) {
        $files = [];

        fakeAlign(function (string $a, string $b) use (&$files, $fails) {
            $files = [$a, $b];
            expect($files)->each(fn ($file) => $file->toBeFile());

            if ($fails) {
                throw new RuntimeException('low_confidence');
            }

            return ['dx' => 0.0, 'dy' => 0.0, 'confidence' => 1.0];
        });

        postAlign($this, alignPayload($this))->assertStatus($fails ? 422 : 200);

        expect($files)->toHaveCount(2);

        foreach ($files as $file) {
            expect(file_exists($file))->toBeFalse();
        }
    })->with([false, true]);

    it('um reason por mensagem: cada código do processo vira o texto certo e o reason cru não vaza', function (string $reason, string $key) {
        $this->mock(BoundedVisionProcess::class)
            ->shouldReceive('align')->once()->andThrow(new RuntimeException($reason));

        $response = postAlign($this, alignPayload($this))->assertStatus(422);

        $message = __($key);

        expect($message)->not->toStartWith('eye_images.')
            ->and($response->json('message'))->toBe($message)
            ->and($response->getContent())->not->toContain('/tmp/vision-secret')
            ->and($response->getContent())->not->toContain($reason);
    })->with([
        'cv2 ausente'             => ['cv2_unavailable', 'eye_images.align_unavailable'],
        'sem isolamento do SO'    => ['vision_process_isolation_unavailable', 'eye_images.align_unavailable'],
        'imagem ilegível'         => ['unreadable_image:/tmp/vision-secret1', 'eye_images.align_unreadable_image'],
        'arquivo fora do storage' => ['archive_unavailable', 'eye_images.align_unreadable_image'],
        'arquivo grande demais'   => ['archive_size_limit', 'eye_images.align_unreadable_image'],
        'exame sem arquivo'       => ['vision_image_missing', 'eye_images.align_unreadable_image'],
        'sem pontos em comum'     => ['no_matches', 'eye_images.align_no_matches'],
        'baixa confiança'         => ['low_confidence', 'eye_images.align_low_confidence'],
        'timeout'                 => ['vision_timeout', 'eye_images.align_timeout'],
        'worker caiu'             => ['vision_worker_failed', 'eye_images.align_failed'],
        'saída grande demais'     => ['vision_output_limit', 'eye_images.align_failed'],
        'saída inválida'          => ['vision_invalid_output', 'eye_images.align_failed'],
        'uso incorreto'           => ['usage', 'eye_images.align_failed'],
    ]);

    it('arquivo que não está no storage vira "imagem ilegível" sem chamar o script', function () {
        Storage::disk('s3')->delete('exams/b.jpg');
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('align');

        $response = postAlign($this, alignPayload($this))->assertStatus(422);

        expect($response->json('message'))->toBe(__('eye_images.align_unreadable_image'));
    });

    it('loga só o código do reason, sem o caminho do temporário', function () {
        Log::spy();
        $this->mock(BoundedVisionProcess::class)
            ->shouldReceive('align')->once()->andThrow(new RuntimeException('unreadable_image:/tmp/vision-secret2'));

        postAlign($this, alignPayload($this))->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'eye_images.align_failed' && $context === ['reason' => 'unreadable_image'])
            ->once();
    });

    it('[SEGURANÇA] exame de outra clínica dá 403 (em A ou em B) e o script nem roda', function (string $slot) {
        $otherEntity  = Entity::factory()->create(['is_client' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id, 'archive' => 'exams/other.jpg']);

        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('align');

        $payload        = alignPayload($this);
        $payload[$slot] = $otherExam->id;

        postAlign($this, $payload)->assertForbidden();
    })->with(['exam_id_a', 'exam_id_b']);

    it('valida o payload: ids iguais, ausentes ou que não são uuid dão 422', function (Closure $payload, string $field) {
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('align');

        postAlign($this, $payload($this))->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        'ids iguais' => [fn ($t) => ['exam_id_a' => $t->examA->id, 'exam_id_b' => $t->examA->id], 'exam_id_a'],
        'sem B'      => [fn ($t) => ['exam_id_a' => $t->examA->id], 'exam_id_b'],
        'sem A'      => [fn ($t) => ['exam_id_b' => $t->examB->id], 'exam_id_a'],
        'A inválido' => [fn ($t) => ['exam_id_a' => 'nao-e-uuid', 'exam_id_b' => $t->examB->id], 'exam_id_a'],
    ]);

    it('vídeo em A ou em B dá 422 antes de baixar ou processar qualquer coisa', function (string $slot) {
        $video = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/clip.webm']);
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('align');

        $payload        = alignPayload($this);
        $payload[$slot] = $video->id;

        $response = postAlign($this, $payload)->assertStatus(422);

        expect($response->json('message'))->toBe(__('eye_images.align_video_not_allowed'));
    })->with(['exam_id_a', 'exam_id_b']);
});

describe('proteção do servidor', function () {
    it('429 na 7ª chamada no mesmo minuto (6 por usuário+clínica), sem rodar o script', function () {
        $mock = Mockery::mock(BoundedVisionProcess::class);
        app()->instance(BoundedVisionProcess::class, $mock);
        $mock->shouldReceive('align')->times(6)->andReturn(['dx' => 1.0, 'dy' => 2.0, 'confidence' => 0.9]);

        for ($i = 1; $i <= 6; $i++) {
            postAlign($this, alignPayload($this))->assertOk();
        }

        postAlign($this, alignPayload($this))->assertStatus(429);
    });

    it('panorama e align dividem o mesmo balde de 6 por minuto', function () {
        $mock = Mockery::mock(BoundedVisionProcess::class);
        app()->instance(BoundedVisionProcess::class, $mock);
        $mock->shouldReceive('align')->times(3)->andReturn(['dx' => 1.0, 'dy' => 2.0, 'confidence' => 0.9]);
        $mock->shouldReceive('stitch')->times(3)->andReturnUsing(fn (array $paths, string $out) => file_put_contents($out, 'PNG'));

        [$user, $member] = $this->members['secretary'];

        for ($i = 1; $i <= 3; $i++) {
            postAlign($this, alignPayload($this))->assertOk();
            $this->actingAs($user)->withSession(panelSession($member))
                ->postJson(route('panel.eye-images.panorama.store'), ['exam_ids' => [$this->examA->id, $this->examB->id]])
                ->assertOk();
        }

        postAlign($this, alignPayload($this))->assertStatus(429);
    });

    it('com os 2 slots de processo ocupados: 422 "servidor ocupado" e o script não roda', function () {
        Cache::lock('vision-slot:0', 60)->get();
        Cache::lock('vision-slot:1', 60)->get();

        $mock = Mockery::mock(BoundedVisionProcess::class)->makePartial();
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldNotReceive('execute');
        app()->instance(BoundedVisionProcess::class, $mock);

        $response = postAlign($this, alignPayload($this))->assertStatus(422);

        expect(__('eye_images.align_busy'))->not->toStartWith('eye_images.')
            ->and($response->json('message'))->toBe(__('eye_images.align_busy'))
            ->and($response->getContent())->not->toContain('vision_busy');
    });

    it('exceção com argv e caminhos do temporário: resposta genérica e log só com vision_worker_failed', function () {
        Log::spy();
        $this->mock(BoundedVisionProcess::class)->shouldReceive('align')->once()->andThrow(
            new RuntimeException('The command "/usr/bin/setsid -- /usr/bin/prlimit python3 -I /tmp/vision-secret3" failed.'),
        );

        $response = postAlign($this, alignPayload($this))->assertStatus(422);

        expect($response->json('message'))->toBe(__('eye_images.align_failed'))
            ->and($response->getContent())->not->toContain('vision-secret3');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'eye_images.align_failed' && $context === ['reason' => 'vision_worker_failed'])
            ->once();
    });
});

describe('processo real (cv2 + isolamento do SO)', function () {
    it('recupera o deslocamento de duas imagens sintéticas (±2 px)', function () {
        if (! visionRuntimeReady()) {
            $this->markTestSkipped('Sem isolamento do SO ou sem python3 com cv2 neste ambiente.');
        }

        mt_srand(11);
        $size   = 700;
        $canvas = imagecreatetruecolor($size + 200, $size + 200);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 90, 90, 90));

        for ($i = 0; $i < 320; $i++) {
            $gray = mt_rand(30, 220);
            imagefilledellipse($canvas, mt_rand(0, $size + 200), mt_rand(0, $size + 200), mt_rand(8, 50), mt_rand(8, 50), imagecolorallocate($canvas, $gray, $gray, $gray));
        }

        // B é o mesmo conteúdo deslocado: o que leva B sobre A é (+30, -20).
        foreach (['exams/a.jpg' => [100, 100], 'exams/b.jpg' => [130, 80]] as $path => [$x, $y]) {
            $crop = imagecreatetruecolor($size, $size);
            imagecopy($crop, $canvas, 0, 0, $x, $y, $size, $size);
            ob_start();
            imagepng($crop);
            Storage::disk('s3')->put($path, (string) ob_get_clean());
        }

        $response = postAlign($this, alignPayload($this))->assertOk();

        expect($response->json('dx'))->toEqualWithDelta(30.0, 2.0)
            ->and($response->json('dy'))->toEqualWithDelta(-20.0, 2.0)
            ->and($response->json('confidence'))->toBeGreaterThanOrEqual(0.3);
    });
});
