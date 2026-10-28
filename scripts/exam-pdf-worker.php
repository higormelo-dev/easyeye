<?php
declare(strict_types=1);
use Smalot\PdfParser\Parser;

require dirname(__DIR__) . '/vendor/autoload.php';

if (! function_exists('posix_setrlimit') || ! defined('POSIX_RLIMIT_AS')
    || ! posix_setrlimit(POSIX_RLIMIT_AS, 536870912, 536870912)
    || ! posix_setrlimit(POSIX_RLIMIT_CPU, 12, 12)
    || ! posix_setrlimit(POSIX_RLIMIT_FSIZE, 16777216, 16777216)) {
    fwrite(STDERR, 'pdf_process_isolation_unavailable');

    exit(8);
}
$path = $argv[2] ?? '';

if (! is_file($path) || filesize($path) > 10485760) {
    exit(2);
}
set_error_handler(static function (int $level, string $message): never {
    throw new ErrorException('pdf_parser_warning', 0, $level);
});

try {
    $raw = file_get_contents($path);

    if (! str_starts_with($raw, '%PDF-')) {
        exit(2);
    }

    if (preg_match('/\/Encrypt\b/', $raw)) {
        fwrite(STDERR, 'pdf_encrypted');

        exit(3);
    }
    $parser   = new Parser();
    $document = $parser->parseContent($raw);

    if (count($document->getPages()) > 64) {
        fwrite(STDERR, 'pdf_page_limit');

        exit(4);
    }

    if (($argv[1] ?? '') === 'text') {
        $text = trim($document->getText());

        if (strlen($text) > 262144) {
            fwrite(STDERR, 'pdf_text_limit');

            exit(4);
        }echo $text;

        exit(0);
    }

    if (! extension_loaded('imagick')) {
        exit(5);
    }
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 134217728);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 134217728);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, 67108864);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 10);
    $image = new Imagick();
    $image->setResolution(100, 100);
    $image->readImage($path . '[0]');

    if ($image->getImageWidth() * $image->getImageHeight() > 40000000) {
        exit(6);
    }$image->setImageBackgroundColor('white');
    $image = $image->flattenImages();
    $image->setImageFormat('jpeg');
    $image->thumbnailImage(2560, 2560, true);
    $bytes = $image->getImageBlob();

    if (strlen($bytes) > 10485760) {
        exit(6);
    }echo $bytes;
} catch (Throwable) {
    exit(7);
}
