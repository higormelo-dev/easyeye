<?php

declare(strict_types=1);

/**
 * LGPD arts. 33–36: a Inter é servida pelo próprio EasyEye (public/fonts).
 * Carregar fontes do Google (@import no CSS, <link> na view ou JS) volta a
 * mandar o IP de quem usa o site, o painel e os portais para fora do Brasil.
 */
it('nenhuma view, CSS ou JS carrega fontes do Google', function (): void {
    $root  = dirname(__DIR__, 3);
    $hosts = ['fonts.googleapis.com', 'fonts.gstatic.com'];
    $hits  = [];

    foreach (['resources/views', 'resources/css', 'resources/js'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $content = (string) file_get_contents($file->getPathname());

            foreach ($hosts as $host) {
                if (str_contains($content, $host)) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1) . " → {$host}";
                }
            }
        }
    }

    expect($hits)->toBe([]);
});

it('os 4 layouts incluem a Inter local e os arquivos existem', function (): void {
    $root = dirname(__DIR__, 3);

    foreach (['app', 'panel-app', 'guest-app', 'portal-app'] as $layout) {
        expect((string) file_get_contents("{$root}/resources/views/{$layout}.blade.php"))
            ->toContain("@include('partials.font-inter')");
    }

    foreach (['InterVariable.woff2', 'InterVariable-Italic.woff2', 'Inter-LICENSE.txt'] as $file) {
        expect(is_file("{$root}/public/fonts/{$file}"))->toBeTrue();
    }

    expect((string) file_get_contents("{$root}/resources/views/partials/font-inter.blade.php"))
        ->toContain("asset('fonts/InterVariable.woff2')")
        ->toContain("asset('fonts/InterVariable-Italic.woff2')");
});
