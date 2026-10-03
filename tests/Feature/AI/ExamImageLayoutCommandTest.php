<?php

/**
 * ai:exam-image-layout — conferência de amostras e cadastro de layouts de
 * tarja (só arquivos locais; nenhum conteúdo da imagem é impresso).
 */
it('diz qual layout reconhece a amostra, gera a impressão digital e grava a imagem tarjada', function () {
    $fixture = base_path('tests/Fixtures/exam-image-layouts/tomey_em3000.png');
    $out     = sys_get_temp_dir() . '/exam-layout-' . uniqid();
    mkdir($out);

    try {
        $this->artisan('ai:exam-image-layout', ['paths' => [$fixture], '--anchor' => ['184,8,66,34'], '--out' => $out])
            ->expectsOutputToContain('tomey_em3000')
            ->expectsOutputToContain('anchor [184, 8, 66, 34]')
            ->assertSuccessful();

        expect(file_exists("{$out}/tomey_em3000.tarjada.png"))->toBeTrue();
    } finally {
        array_map('unlink', glob("{$out}/*") ?: []);
        rmdir($out);
    }
});

it('amostra desconhecida: avisa que a imagem fica fora da IA', function () {
    $path = sys_get_temp_dir() . '/exam-layout-' . uniqid() . '.png';
    imagepng(imagecreatetruecolor(320, 240), $path);

    try {
        $this->artisan('ai:exam-image-layout', ['paths' => [$path]])
            ->expectsOutputToContain('não reconhecido')
            ->assertSuccessful();
    } finally {
        unlink($path);
    }
});

it('arquivo que não é imagem ou região inválida falha com mensagem', function () {
    $this->artisan('ai:exam-image-layout', ['paths' => [base_path('composer.json')]])->assertFailed();
    $this->artisan('ai:exam-image-layout', ['paths' => [base_path('tests/Fixtures/exam-image-layouts/tomey_em3000.png')], '--anchor' => ['1,2,3']])->assertFailed();
});
