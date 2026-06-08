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

function examWithImage(int $laterality = 1): PatientExam
{
    $exam = PatientExam::factory()->create(['laterality' => $laterality]);
    Storage::disk('s3')->put($exam->archive, fakeJpeg());

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
