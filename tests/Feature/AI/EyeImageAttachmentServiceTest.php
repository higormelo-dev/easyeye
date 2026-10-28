<?php

use App\Domains\AI\Services\EyeImageAttachmentService;
use App\Models\PatientExam;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    config()->set('ai.eye_image.max_images', 2);
    config()->set('ai.eye_image.max_dimension', 64);
    $this->service = app(EyeImageAttachmentService::class);
});

function fakeJpeg(int $w = 120, int $h = 90): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** Tela de equipamento conhecida (fixture sem dado real: rótulos + "PACIENTE TESTE"). */
function layoutBytes(string $key = 'oculus_pentacam_panel_a_pt'): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/exam-image-layouts/{$key}.png"));
}

function examWithImage(int $laterality = 1, ?string $bytes = null): PatientExam
{
    $exam = PatientExam::factory()->create(['laterality' => $laterality]);
    Storage::disk('s3')->put($exam->archive, $bytes ?? layoutBytes());

    return $exam;
}

describe('EyeImageAttachmentService::build', function () {
    it('converte exame do S3 em anexo base64 (JPEG) com lateralidade', function () {
        $exam = examWithImage(1);

        $attachments = $this->service->build([$exam]);

        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]['mime_type'])->toBe('image/jpeg')
            ->and($attachments[0]['exam_id'])->toBe((string) $exam->id)
            ->and($attachments[0]['laterality'])->toBe(1);

        // O base64 decodifica para uma imagem válida.
        $decoded = base64_decode($attachments[0]['data']);
        expect(imagecreatefromstring($decoded))->not->toBeFalse();
    });

    it('respeita o limite máximo de imagens', function () {
        $exams = [examWithImage(), examWithImage(), examWithImage()]; // 3, limite 2

        expect($this->service->build($exams))->toHaveCount(2);
    });

    it('ignora exame cujo arquivo não existe no S3', function () {
        $exam = PatientExam::factory()->create(['archive' => 'exams/missing.jpg']);

        expect($this->service->build([$exam]))->toBe([]);
    });
});

describe('EyeImageAttachmentService — LGPD (dados do paciente no pixel)', function () {
    it('tarja os dados do paciente só na cópia enviada; o original no S3 não muda', function () {
        config()->set('ai.eye_image.max_dimension', 2000); // sem reduzir: confere o pixel no lugar
        $exam = examWithImage();

        $prepared = $this->service->prepare([$exam]);

        expect($prepared['outcomes'])->toBe([(string) $exam->id => 'oculus_pentacam_panel_a_pt'])
            ->and($prepared['attachments'])->toHaveCount(1);

        $sent = imagecreatefromstring(base64_decode($prepared['attachments'][0]['data']));
        $rgb  = imagecolorat($sent, 152, 93); // centro do campo [76, 44, 152, 98]

        expect(max(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF))->toBeLessThan(30)
            ->and(Storage::disk('s3')->get($exam->archive))->toBe(layoutBytes());
    });

    it('[LGPD] imagem de layout não reconhecido não sai e fica registrada', function () {
        $exam = examWithImage(2, fakeJpeg());

        $prepared = $this->service->prepare([$exam]);

        expect($prepared['attachments'])->toBe([])
            ->and($prepared['outcomes'])->toBe([(string) $exam->id => EyeImageAttachmentService::SKIPPED_UNRECOGNIZED_LAYOUT])
            ->and($this->service->build([$exam]))->toBe([]);
    });

    it('imagem bloqueada não ocupa vaga do limite de imagens', function () {
        $exams = [examWithImage(1, fakeJpeg()), examWithImage(), examWithImage()]; // limite 2

        expect($this->service->build($exams))->toHaveCount(2);
    });
});
