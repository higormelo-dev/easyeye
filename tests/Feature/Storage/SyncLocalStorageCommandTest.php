<?php

use Illuminate\Support\Facades\{File, Storage};

/**
 * storage:sync-local — migração única dos arquivos que ficaram no disco
 * local para o disco padrão quando FILESYSTEM_DISK passa a ser s3.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/easyeye_sync_' . uniqid();
    File::ensureDirectoryExists($this->dir . '/private/tiss');
    File::put($this->dir . '/private/tiss/lote.xml', '<xml/>');
    File::put($this->dir . '/existente.csv', 'local');
});

afterEach(fn () => File::deleteDirectory($this->dir));

function fakeDefaultDiskAsS3(): void
{
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');
}

it('copia arquivos locais mantendo o caminho e sem sobrescrever o destino', function () {
    fakeDefaultDiskAsS3();
    Storage::disk()->put('existente.csv', 'remoto');

    $this->artisan('storage:sync-local', ['--from' => $this->dir])->assertSuccessful();

    expect(Storage::disk('s3')->get('private/tiss/lote.xml'))->toBe('<xml/>')
        ->and(Storage::disk('s3')->get('existente.csv'))->toBe('remoto')
        ->and(File::exists($this->dir . '/private/tiss/lote.xml'))->toBeTrue();
});

it('dry-run não copia nada', function () {
    fakeDefaultDiskAsS3();

    $this->artisan('storage:sync-local', ['--from' => $this->dir, '--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('s3')->exists('private/tiss/lote.xml'))->toBeFalse();
});

it('não faz nada quando o disco padrão já é local', function () {
    $this->artisan('storage:sync-local', ['--from' => $this->dir])
        ->expectsOutputToContain('nada a sincronizar')
        ->assertSuccessful();
});
