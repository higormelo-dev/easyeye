<?php
declare(strict_types=1);

namespace App\Rules;

use App\Services\EmrReport;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class IntegratorExamArchive implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getSize() > 10485760) {
            $fail('archive_size_or_upload_invalid');

            return;
        }
        $ext = strtolower($value->getClientOriginalExtension());

        if ($ext === 'emr') {
            try {
                app(EmrReport::class)->parse(file_get_contents($value->getRealPath()));
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }

            return;
        }
        $mime = $value->getMimeType();
        $map  = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'bmp' => ['image/bmp', 'image/x-ms-bmp'], 'pdf' => ['application/pdf']];

        if (! isset($map[$ext]) || ! in_array($mime, $map[$ext], true)) {
            $fail('archive_format_unsupported');

            return;
        }

        if ($ext !== 'pdf') {
            $size = @getimagesize($value->getRealPath());

            if (! $size || $size[0] > 16000 || $size[1] > 16000 || $size[0] * $size[1] > 40000000) {
                $fail('archive_pixel_limit');
            }
        } elseif (! str_starts_with(file_get_contents($value->getRealPath(), false, null, 0, 8), '%PDF-')) {
            $fail('pdf_structure_invalid');
        }
    }
}
