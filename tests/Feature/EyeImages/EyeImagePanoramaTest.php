<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Patient, PatientExam, User};
use App\Services\EyeImages\BoundedVisionProcess;
use Illuminate\Support\Facades\{Cache, Log, Storage};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Panorama automático — EyeImagePanoramaController / PanoramaService.
 *
 * O processo Python (cv2) é FINGIDO no container: um caso por `reason` do
 * script, para provar que cada código vira a mensagem certa e que o reason cru
 * (que pode citar caminho de arquivo temporário) nunca chega ao cliente. O
 * teste "de verdade" só roda com isolamento do SO e cv2 instalados.
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
    Storage::disk('s3')->put('exams/c.jpg', 'bytes-c');

    $this->examA = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/a.jpg']);
    $this->examB = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/b.jpg']);
    $this->examC = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/c.jpg']);
});

function postPanorama($test, array $ids, string $rule = 'secretary')
{
    [$user, $member] = $test->members[$rule];

    return $test->actingAs($user)->withSession(panelSession($member))
        ->postJson(route('panel.eye-images.panorama.store'), ['exam_ids' => $ids]);
}

/** Troca o processo de visão por um mock que executa $stitch no lugar do script. */
function fakeStitch(Closure $stitch): void
{
    $mock = Mockery::mock(BoundedVisionProcess::class);
    app()->instance(BoundedVisionProcess::class, $mock);

    $mock->shouldReceive('stitch')->once()->andReturnUsing($stitch);
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

describe('POST eye-images/panorama', function () {
    it('devolve o PNG gerado como anexo, com os bytes do script', function () {
        fakeStitch(fn (array $paths, string $out) => file_put_contents($out, 'PNG-SENTINELA'));

        $response = postPanorama($this, [$this->examA->id, $this->examB->id])->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="panorama.png"')
            ->and($response->getContent())->toBe('PNG-SENTINELA');
    });

    it('admin, médico e secretária geram; financeiro não (403)', function (string $rule, int $status) {
        if ($status === 200) {
            fakeStitch(fn (array $paths, string $out) => file_put_contents($out, 'PNG'));
        } else {
            $this->mock(BoundedVisionProcess::class)->shouldNotReceive('stitch');
        }

        postPanorama($this, [$this->examA->id, $this->examB->id], $rule)->assertStatus($status);
    })->with([
        'admin'     => ['admin', 200],
        'doctor'    => ['doctor', 200],
        'secretary' => ['secretary', 200],
        'financial' => ['financial', 403],
    ]);

    it('visitante não gera (401)', function () {
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('stitch');

        $this->postJson(route('panel.eye-images.panorama.store'), ['exam_ids' => [$this->examA->id, $this->examB->id]])
            ->assertUnauthorized();
    });

    it('entrega ao script as imagens na ordem da seleção, preferindo o display_archive pronto', function () {
        Storage::disk('s3')->put('exams/a_display.jpg', 'display-a');
        $this->examA->forceFill(['display_archive' => 'exams/a_display.jpg', 'derivative_status' => 'ready'])->save();
        // Derivado ainda não pronto: o original é que vale, mesmo com display preenchido.
        Storage::disk('s3')->put('exams/b_display.jpg', 'display-b-pendente');
        $this->examB->forceFill(['display_archive' => 'exams/b_display.jpg', 'derivative_status' => 'pending'])->save();

        $seen = [];

        fakeStitch(function (array $paths, string $out) use (&$seen) {
            $seen = array_map('file_get_contents', $paths);
            file_put_contents($out, 'PNG');
        });

        // Seleção C, A, B — a ordem do request, não a do banco.
        postPanorama($this, [$this->examC->id, $this->examA->id, $this->examB->id])->assertOk();

        expect($seen)->toBe(['bytes-c', 'display-a', 'bytes-b']);
    });

    it('derivado "pronto" sem display_archive cai no original', function () {
        $this->examA->forceFill(['display_archive' => null, 'derivative_status' => 'ready'])->save();

        $seen = [];

        fakeStitch(function (array $paths, string $out) use (&$seen) {
            $seen = array_map('file_get_contents', $paths);
            file_put_contents($out, 'PNG');
        });

        postPanorama($this, [$this->examA->id, $this->examB->id])->assertOk();

        expect($seen)->toBe(['bytes-a', 'bytes-b']);
    });

    it('apaga todos os temporários (entrada e saída), no sucesso e na falha', function (bool $fails) {
        $files = [];

        fakeStitch(function (array $paths, string $out) use (&$files, $fails) {
            $files = [...$paths, $out];
            expect($files)->each(fn ($file) => $file->toBeFile());

            if ($fails) {
                throw new RuntimeException('stitch_failed:1');
            }

            file_put_contents($out, 'PNG');
        });

        postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus($fails ? 422 : 200);

        expect($files)->toHaveCount(3);

        foreach ($files as $file) {
            expect(file_exists($file))->toBeFalse();
        }
    })->with([false, true]);

    it('um reason por mensagem: cada código do processo vira o texto certo e o reason cru não vaza', function (string $reason, string $key) {
        $this->mock(BoundedVisionProcess::class)
            ->shouldReceive('stitch')->once()->andThrow(new RuntimeException($reason));

        $response = postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(422);

        $message = __($key);

        expect($message)->not->toStartWith('eye_images.')
            ->and($response->json('message'))->toBe($message)
            ->and($response->getContent())->not->toContain('/tmp/vision-secret')
            ->and($response->getContent())->not->toContain($reason);
    })->with([
        'cv2 ausente'               => ['cv2_unavailable', 'eye_images.panorama_unavailable'],
        'sem isolamento do SO'      => ['vision_process_isolation_unavailable', 'eye_images.panorama_unavailable'],
        'imagem ilegível'           => ['unreadable_image:/tmp/vision-secret1', 'eye_images.panorama_unreadable_image'],
        'arquivo fora do storage'   => ['archive_unavailable', 'eye_images.panorama_unreadable_image'],
        'arquivo grande demais'     => ['archive_size_limit', 'eye_images.panorama_unreadable_image'],
        'exame sem arquivo'         => ['vision_image_missing', 'eye_images.panorama_unreadable_image'],
        'sobreposição insuficiente' => ['stitch_failed:1', 'eye_images.panorama_stitch_failed'],
        'timeout'                   => ['vision_timeout', 'eye_images.panorama_timeout'],
        'falha ao gravar'           => ['write_failed', 'eye_images.panorama_failed'],
        'worker caiu'               => ['vision_worker_failed', 'eye_images.panorama_failed'],
        'saída grande demais'       => ['vision_output_limit', 'eye_images.panorama_failed'],
        'saída inválida'            => ['vision_invalid_output', 'eye_images.panorama_failed'],
        'uso incorreto'             => ['usage', 'eye_images.panorama_failed'],
    ]);

    it('script que não grava nada vira panorama_failed, não PNG vazio', function () {
        Log::spy();
        fakeStitch(fn (array $paths, string $out) => null);

        $response = postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(422);

        expect($response->json('message'))->toBe(__('eye_images.panorama_failed'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $context === ['reason' => 'vision_invalid_output'])
            ->once();
    });

    it('arquivo que não está no storage vira "imagem ilegível" sem chamar o script', function () {
        Storage::disk('s3')->delete('exams/b.jpg');
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('stitch');

        $response = postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(422);

        expect($response->json('message'))->toBe(__('eye_images.panorama_unreadable_image'));
    });

    it('loga só o código do reason, sem o caminho do temporário', function () {
        Log::spy();
        $this->mock(BoundedVisionProcess::class)
            ->shouldReceive('stitch')->once()->andThrow(new RuntimeException('unreadable_image:/tmp/vision-secret2'));

        postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'eye_images.panorama_failed' && $context === ['reason' => 'unreadable_image'])
            ->once();
    });

    it('[SEGURANÇA] exame de outra clínica dá 403 e o script nem roda', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id, 'archive' => 'exams/other.jpg']);

        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('stitch');

        postPanorama($this, [$this->examA->id, $otherExam->id])->assertForbidden();
    });

    it('valida a quantidade e os ids: 1, 7 ou repetidos dão 422', function (Closure $ids) {
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('stitch');

        postPanorama($this, $ids($this))->assertStatus(422)->assertJsonValidationErrors('exam_ids');
    })->with([
        'um só'  => [fn ($t) => [$t->examA->id]],
        'sete'   => [fn ($t) => collect(range(1, 7))->map(fn () => (string) Str::uuid())->all()],
        'nenhum' => [fn ($t) => []],
    ]);

    it('ids repetidos dão 422', function () {
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('stitch');

        postPanorama($this, [$this->examA->id, $this->examA->id])->assertStatus(422)->assertJsonValidationErrors('exam_ids.1');
    });

    it('aceita exatamente 6 imagens (o teto)', function () {
        $exams = collect(range(1, 3))->map(fn ($i) => PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'archive'    => 'exams/a.jpg',
        ]));

        $count = 0;
        fakeStitch(function (array $paths, string $out) use (&$count) {
            $count = count($paths);
            file_put_contents($out, 'PNG');
        });

        postPanorama($this, [$this->examA->id, $this->examB->id, $this->examC->id, ...$exams->pluck('id')->all()])->assertOk();

        expect($count)->toBe(6);
    });

    it('vídeo na seleção dá 422 antes de baixar ou processar qualquer coisa', function () {
        $video = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'archive' => 'exams/clip.mp4']);
        $this->mock(BoundedVisionProcess::class)->shouldNotReceive('stitch');

        $response = postPanorama($this, [$this->examA->id, $video->id])->assertStatus(422);

        expect($response->json('message'))->toBe(__('eye_images.panorama_video_not_allowed'));
    });
});

describe('proteção do servidor', function () {
    it('429 na 7ª chamada no mesmo minuto (6 por usuário+clínica), sem rodar o script', function () {
        $mock = Mockery::mock(BoundedVisionProcess::class);
        app()->instance(BoundedVisionProcess::class, $mock);
        $mock->shouldReceive('stitch')->times(6)->andReturnUsing(fn (array $paths, string $out) => file_put_contents($out, 'PNG'));

        for ($i = 1; $i <= 6; $i++) {
            postPanorama($this, [$this->examA->id, $this->examB->id])->assertOk();
        }

        postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(429);
    });

    it('o limite é por usuário: outro usuário da mesma clínica ainda gera', function () {
        $mock = Mockery::mock(BoundedVisionProcess::class);
        app()->instance(BoundedVisionProcess::class, $mock);
        $mock->shouldReceive('stitch')->times(7)->andReturnUsing(fn (array $paths, string $out) => file_put_contents($out, 'PNG'));

        for ($i = 1; $i <= 6; $i++) {
            postPanorama($this, [$this->examA->id, $this->examB->id], 'secretary')->assertOk();
        }

        postPanorama($this, [$this->examA->id, $this->examB->id], 'secretary')->assertStatus(429);
        postPanorama($this, [$this->examA->id, $this->examB->id], 'doctor')->assertOk();
    });

    it('o limite é por usuário+clínica: o mesmo usuário em outra clínica tem balde próprio', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherA       = PatientExam::factory()->create(['patient_id' => $otherPatient->id, 'archive' => 'exams/a.jpg']);
        $otherB       = PatientExam::factory()->create(['patient_id' => $otherPatient->id, 'archive' => 'exams/b.jpg']);

        [$user]      = $this->members['secretary'];
        $otherMember = createEntityUser($otherEntity, $user, ClientRule::Secretary->value);

        $mock = Mockery::mock(BoundedVisionProcess::class);
        app()->instance(BoundedVisionProcess::class, $mock);
        $mock->shouldReceive('stitch')->times(7)->andReturnUsing(fn (array $paths, string $out) => file_put_contents($out, 'PNG'));

        for ($i = 1; $i <= 6; $i++) {
            postPanorama($this, [$this->examA->id, $this->examB->id])->assertOk();
        }

        postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(429);

        $this->actingAs($user)->withSession(panelSession($otherMember))
            ->postJson(route('panel.eye-images.panorama.store'), ['exam_ids' => [$otherA->id, $otherB->id]])
            ->assertOk();
    });

    it('com os 2 slots de processo ocupados: 422 "servidor ocupado" e o script não roda', function () {
        Cache::lock('vision-slot:0', 60)->get();
        Cache::lock('vision-slot:1', 60)->get();

        $mock = Mockery::mock(BoundedVisionProcess::class)->makePartial();
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldNotReceive('execute');
        app()->instance(BoundedVisionProcess::class, $mock);

        $response = postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(422);

        expect(__('eye_images.panorama_busy'))->not->toStartWith('eye_images.')
            ->and($response->json('message'))->toBe(__('eye_images.panorama_busy'))
            ->and($response->getContent())->not->toContain('vision_busy');
    });

    it('exceção com argv e caminhos do temporário: resposta genérica e log só com vision_worker_failed', function () {
        Log::spy();
        $this->mock(BoundedVisionProcess::class)->shouldReceive('stitch')->once()->andThrow(
            new RuntimeException('The command "/usr/bin/setsid -- /usr/bin/prlimit python3 -I /tmp/vision-secret3" failed.'),
        );

        $response = postPanorama($this, [$this->examA->id, $this->examB->id])->assertStatus(422);

        expect($response->json('message'))->toBe(__('eye_images.panorama_failed'))
            ->and($response->getContent())->not->toContain('vision-secret3');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'eye_images.panorama_failed' && $context === ['reason' => 'vision_worker_failed'])
            ->once();
    });
});

describe('urls do Gerenciador de Imagens', function () {
    it('oferece panorama e align quando o servidor roda o processo isolado', function () {
        $this->mock(BoundedVisionProcess::class)->shouldReceive('isAvailable')->andReturn(true);
        [$user, $member] = $this->members['doctor'];

        $this->actingAs($user)->withSession(panelSession($member))
            ->get(route('panel.eye-images.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('urls.panorama', route('panel.eye-images.panorama.store'))
                ->where('urls.align', route('panel.eye-images.align.store')));
    });

    it('esconde panorama e align (null) quando o servidor não roda o processo isolado', function () {
        $this->mock(BoundedVisionProcess::class)->shouldReceive('isAvailable')->andReturn(false);
        [$user, $member] = $this->members['doctor'];

        $this->actingAs($user)->withSession(panelSession($member))
            ->get(route('panel.eye-images.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('urls.panorama', null)
                ->where('urls.align', null));
    });
});

describe('processo real (cv2 + isolamento do SO)', function () {
    it('costura duas imagens sintéticas sobrepostas num PNG mais largo', function () {
        if (! visionRuntimeReady()) {
            $this->markTestSkipped('Sem isolamento do SO ou sem python3 com cv2 neste ambiente.');
        }

        mt_srand(21);
        $wide = imagecreatetruecolor(1400, 700);
        imagefill($wide, 0, 0, imagecolorallocate($wide, 90, 90, 90));

        for ($i = 0; $i < 260; $i++) {
            $gray = mt_rand(30, 220);
            imagefilledellipse($wide, mt_rand(0, 1400), mt_rand(0, 700), mt_rand(8, 60), mt_rand(8, 60), imagecolorallocate($wide, $gray, $gray, $gray));
        }

        foreach ([0, 500] as $index => $x) {
            $crop = imagecreatetruecolor(900, 700);
            imagecopy($crop, $wide, 0, 0, $x, 0, 900, 700);
            ob_start();
            imagepng($crop);
            Storage::disk('s3')->put($index === 0 ? 'exams/a.jpg' : 'exams/b.jpg', (string) ob_get_clean());
        }

        $response = postPanorama($this, [$this->examA->id, $this->examB->id])->assertOk();

        $size = getimagesizefromstring($response->getContent());

        expect($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($size[0])->toBeGreaterThan(900);
    });
});
