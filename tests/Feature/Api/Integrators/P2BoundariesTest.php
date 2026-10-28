<?php
use App\Jobs\GenerateExamDerivatives;
use App\Models\{IntegratorUpdate,Patient,PatientExam};
use App\Services\{BoundedExamArchive, BoundedPdfProcess};
use App\Services\{IntegratorUpdateManifest,IntegratorUpdatePublisher};
use App\Services\RasterMemoryBudget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Schema, Storage};
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Storage::fake('s3');
    $this->ctx    = setupIntegrator();
    $kp           = sodium_crypto_sign_seed_keypair(str_repeat('P', 32));
    $this->secret = sodium_crypto_sign_secretkey($kp);
    config(['services.integrator_updates.public_key' => bin2hex(sodium_crypto_sign_publickey($kp))]);
});

class P2EmptyArchiveStream
{
    public $context;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_read($count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option($option, $arg1, $arg2): bool
    {
        return true;
    }
}

it('bounds stalled archive streams instead of spinning indefinitely', function () {
    stream_wrapper_register('p2empty', P2EmptyArchiveStream::class);

    try {
        $stream = fopen('p2empty://synthetic', 'r');
        $disk   = Mockery::mock(Storage::disk('s3'))->makePartial();
        $disk->shouldReceive('readStream')->andReturn($stream);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
        expect(fn () => app(BoundedExamArchive::class)->read('synthetic'))->toThrow(RuntimeException::class, 'archive_read_stalled');
    } finally {
        stream_wrapper_unregister('p2empty');
    }
});

it('checks the complete raster memory budget before allocating decoder surfaces', function () {
    $patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    $file    = UploadedFile::fake()->image('synthetic.png', 32, 32);
    $raw     = file_get_contents($file->getRealPath());
    $raw     = substr_replace($raw, pack('N', 8000) . pack('N', 5000), 16, 8);
    Storage::disk('s3')->put('oversized-surfaces.png', $raw);
    $exam = PatientExam::factory()->create(['patient_id' => $patient->id, 'archive' => 'oversized-surfaces.png']);
    expect(fn () => (new GenerateExamDerivatives($exam->id))->handle())->toThrow(RuntimeException::class, 'raster_memory_limit');
    expect($exam->refresh()->derivative_status)->toBe('failed')->and($exam->display_archive)->toBeNull()->and(Storage::disk('s3')->get($exam->archive))->toBe($raw);
});

it('renders a tiny valid raster in a long-lived worker while preserving per-operation and PHP headroom limits', function () {
    $budget = app(RasterMemoryBudget::class);
    expect($budget->available('-1', 536870912))->toBe(268435456)
        ->and($budget->available('512M', 314572800))->toBe(205520896)
        ->and($budget->available('128M', 125829120))->toBe(0);
    $measured = Mockery::mock(RasterMemoryBudget::class)->makePartial();
    $measured->shouldReceive('available')->once()->andReturn($budget->available('-1', 536870912));
    app()->instance(RasterMemoryBudget::class, $measured);
    $patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    $file    = UploadedFile::fake()->image('tiny.jpg', 32, 32);
    $raw     = file_get_contents($file->getRealPath());
    Storage::disk('s3')->put('tiny.jpg', $raw);
    $exam = PatientExam::factory()->create(['patient_id' => $patient->id, 'archive' => 'tiny.jpg']);
    (new GenerateExamDerivatives($exam->id))->handle();
    expect($exam->refresh()->derivative_status)->toBe('ready')->and(Storage::disk('s3')->get($exam->archive))->toBe($raw);
    Storage::disk('s3')->assertExists($exam->display_archive);
});
function p2BoundaryInstaller(): string
{
    $path = tempnam(sys_get_temp_dir(), 'p2-install-');
    file_put_contents($path, 'synthetic signed installer');

    return $path;
}
function p2PdfFixture(int $pages = 1, string $text = 'Synthetic native PDF report', bool $compressed = false): string
{
    $objects = [];
    $kids    = [];

    for ($i = 0; $i < $pages; $i++) {
        $kids[] = (3 + $i * 2) . ' 0 R';
    }
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pages . ' >>';

    for ($i = 0; $i < $pages; $i++) {
        $stream = 'BT /F1 12 Tf 30 700 Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ') Tj ET';

        if ($compressed) {
            $stream = gzcompress($stream);
        }$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 ' . (3 + $pages * 2) . ' 0 R >> >> /Contents ' . (4 + $i * 2) . ' 0 R >>';
        $objects[]  = '<< ' . ($compressed ? '/Filter /FlateDecode ' : '') . '/Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $pdf       = "%PDF-1.4\n";
    $offset    = [0];

    foreach ($objects as $i => $object) {
        $offset[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }$xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

    foreach (array_slice($offset, 1) as $value) {
        $pdf .= sprintf('%010d 00000 n ', $value) . "\n";
    }$pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

    return $pdf;
}
it('retains the active previous release when storage returns false', function () {
    $old     = IntegratorUpdate::forceCreate(['version' => '0.9.0', 'platform' => 'windows', 'arch' => 'x86', 'archive' => 'old', 'sha256' => str_repeat('a', 64), 'signature' => 'old', 'active' => true]);
    $path    = p2BoundaryInstaller();
    $fixture = p2ManifestFixture($path, '1.0.0', 'windows', 'x86', $this->secret);
    $disk    = Mockery::mock(Storage::disk('s3'))->makePartial();
    $disk->shouldReceive('put')->andReturn(false);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    expect(fn () => app(IntegratorUpdatePublisher::class)->publish($path, 'setup.msi', '1.0.0', 'windows', 'x86', $fixture['metadata']['asset_signature'], false, $fixture['metadata'], $fixture['manifestSignature']))->toThrow(InvalidArgumentException::class);
    expect($old->refresh()->active)->toBeTrue()->and(IntegratorUpdate::count())->toBe(1);
    @unlink($path);
});
it('retains the previous release when readback exposes wrong or truncated stored bytes', function () {
    $old     = IntegratorUpdate::forceCreate(['version' => '0.9.0', 'platform' => 'windows', 'arch' => 'x86', 'archive' => 'old', 'sha256' => str_repeat('a', 64), 'signature' => 'old', 'active' => true]);
    $path    = p2BoundaryInstaller();
    $fixture = p2ManifestFixture($path, '1.0.0', 'windows', 'x86', $this->secret);
    $object  = 'integrator-updates/windows/x86/1.0.0/' . $fixture['metadata']['sha256'] . '/setup.msi';
    Storage::disk('s3')->put($object, 'wrong');
    expect(fn () => app(IntegratorUpdatePublisher::class)->publish($path, 'setup.msi', '1.0.0', 'windows', 'x86', $fixture['metadata']['asset_signature'], false, $fixture['metadata'], $fixture['manifestSignature']))->toThrow(InvalidArgumentException::class);
    expect($old->refresh()->active)->toBeTrue()->and(IntegratorUpdate::count())->toBe(1);
    @unlink($path);
});
it('keeps same version and basename immutable across different architectures', function () {
    $service = app(IntegratorUpdatePublisher::class);
    $a       = p2BoundaryInstaller();
    $fa      = p2ManifestFixture($a, '1.0.0', 'windows', 'x86', $this->secret);
    $ra      = $service->publish($a, 'setup.msi', '1.0.0', 'windows', 'x86', $fa['metadata']['asset_signature'], false, $fa['metadata'], $fa['manifestSignature']);
    $b       = p2BoundaryInstaller();
    file_put_contents($b, 'different synthetic binary');
    $fb = p2ManifestFixture($b, '1.0.0', 'windows', 'x86_64', $this->secret);
    $rb = $service->publish($b, 'setup.msi', '1.0.0', 'windows', 'x86_64', $fb['metadata']['asset_signature'], false, $fb['metadata'], $fb['manifestSignature']);
    expect($ra->archive)->not->toBe($rb->archive)->and(hash('sha256', Storage::disk('s3')->get($ra->archive)))->toBe($ra->sha256)->and(hash('sha256', Storage::disk('s3')->get($rb->archive)))->toBe($rb->sha256);
    @unlink($a);
    @unlink($b);
});
it('rejects tampering with every signed metadata field and a stale sequence', function () {
    $path    = p2BoundaryInstaller();
    $fixture = p2ManifestFixture($path, '1.0.0', 'windows', 'x86', $this->secret);
    $service = app(IntegratorUpdateManifest::class);

    foreach (IntegratorUpdateManifest::FIELDS as $field) {
        $changed         = $fixture['metadata'];
        $changed[$field] = is_int($changed[$field]) ? $changed[$field] + 1 : ($changed[$field] === null ? '1.0.0' : $changed[$field] . 'x');
        expect(fn () => $service->validate($changed, $fixture['manifestSignature']))->toThrow(InvalidArgumentException::class);
    }
    $publisher = app(IntegratorUpdatePublisher::class);
    $publisher->publish($path, 'setup.msi', '1.0.0', 'windows', 'x86', $fixture['metadata']['asset_signature'], false, $fixture['metadata'], $fixture['manifestSignature']);
    $next = p2ManifestFixture($path, '1.1.0', 'windows', 'x86', $this->secret, ['sequence' => $fixture['metadata']['sequence']]);
    expect(fn () => $publisher->publish($path, 'setup.msi', '1.1.0', 'windows', 'x86', $next['metadata']['asset_signature'], false, $next['metadata'], $next['manifestSignature']))->toThrow(InvalidArgumentException::class);
    @unlink($path);
});
it('binds pilot distribution to the authenticated cohort and refuses query-based promotion', function () {
    $path    = p2BoundaryInstaller();
    $fixture = p2ManifestFixture($path, '1.0.0', 'windows', 'x86', $this->secret, ['channel' => 'pilot', 'cohort' => 'oculus-pilot']);
    app(IntegratorUpdatePublisher::class)->publish($path, 'setup.msi', '1.0.0', 'windows', 'x86', $fixture['metadata']['asset_signature'], false, $fixture['metadata'], $fixture['manifestSignature']);
    $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86&os_version=6.1.7601&channel=pilot&cohort=oculus-pilot', $this->ctx['headers'])->assertOk()->assertJsonPath('data', null);
    $this->ctx['integrator']->update(['update_channel' => 'pilot', 'update_cohort' => 'oculus-pilot']);
    $this->getJson('/api/integrators/v1/updates?platform=windows&arch=x86&os_version=6.1.7601', $this->ctx['headers'])->assertOk()->assertJsonPath('data.metadata.cohort', 'oculus-pilot');
    @unlink($path);
});
it('never marks derivatives ready when a write returns false and leaves original bytes intact', function () {
    $patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id, 'active' => true]);
    $file    = UploadedFile::fake()->image('synthetic.png', 32, 32);
    $raw     = file_get_contents($file->getRealPath());
    Storage::disk('s3')->put('original.png', $raw);
    $exam = PatientExam::factory()->create(['patient_id' => $patient->id, 'archive' => 'original.png']);
    $disk = Mockery::mock(Storage::disk('s3'))->makePartial();
    $disk->shouldReceive('put')->andReturn(false);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    expect(fn () => (new GenerateExamDerivatives($exam->id, 'original.png'))->handle())->toThrow(RuntimeException::class);
    expect($exam->refresh()->derivative_status)->toBe('failed')->and($exam->display_archive)->toBeNull()->and(Storage::disk('s3')->get('original.png'))->toBe($raw);
});
it('keeps PDF decoding in bounded subprocesses and extracts native literal text', function () {
    $path = tempnam(sys_get_temp_dir(), 'p2-pdf-');
    file_put_contents($path, p2PdfFixture());
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('scripts/exam-pdf-worker.php'), 'text', $path]);
    $process->setTimeout(10);
    $process->mustRun();
    expect($process->getOutput())->toContain('Synthetic native PDF report');
    @unlink($path);
});
it('rejects excessive PDF pages and text without raster OCR', function () {
    foreach ([p2PdfFixture(65), p2PdfFixture(1, str_repeat('synthetic literal ', 18000)), str_replace('/Type /Catalog', '/Encrypt 1 0 R /Type /Catalog', p2PdfFixture())] as $pdf) {
        $path = tempnam(sys_get_temp_dir(), 'p2-pdf-');
        file_put_contents($path, $pdf);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('scripts/exam-pdf-worker.php'), 'text', $path]);
        $process->setTimeout(10);
        $process->run();
        expect($process->isSuccessful())->toBeFalse();
        @unlink($path);
    }
});
it('contains compressed PDF expansion within a subprocess memory and time cap', function () {
    $path = tempnam(sys_get_temp_dir(), 'p2-pdf-bomb-');
    file_put_contents($path, p2PdfFixture(1, str_repeat('Q', 16 * 1024 * 1024), true));
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=32M', base_path('scripts/exam-pdf-worker.php'), 'text', $path]);
    $process->setTimeout(10);
    $process->run();
    expect($process->isSuccessful())->toBeFalse();
    expect(strlen($process->getOutput()))->toBeLessThan(262145);
    @unlink($path);
});

it('keeps artifact bytes immutable across pilot and stable cohorts while allowing signed promotion', function () {
    $path      = p2BoundaryInstaller();
    $publisher = app(IntegratorUpdatePublisher::class);
    $pilot     = p2ManifestFixture($path, '1.0.0', 'windows', 'x86', $this->secret, ['channel' => 'pilot', 'cohort' => 'oculus-pilot', 'sequence' => 1]);
    $old       = $publisher->publish($path, 'setup.msi', '1.0.0', 'windows', 'x86', $pilot['metadata']['asset_signature'], false, $pilot['metadata'], $pilot['manifestSignature']);
    $different = p2BoundaryInstaller();
    file_put_contents($different, 'different same-version binary');
    $bad = p2ManifestFixture($different, '1.0.0', 'windows', 'x86', $this->secret, ['sequence' => 2]);
    expect(fn () => $publisher->publish($different, 'setup.msi', '1.0.0', 'windows', 'x86', $bad['metadata']['asset_signature'], false, $bad['metadata'], $bad['manifestSignature']))->toThrow(InvalidArgumentException::class);
    $stable   = p2ManifestFixture($path, '1.0.0', 'windows', 'x86', $this->secret, ['sequence' => 2]);
    $promoted = $publisher->publish($path, 'setup.msi', '1.0.0', 'windows', 'x86', $stable['metadata']['asset_signature'], false, $stable['metadata'], $stable['manifestSignature']);
    expect($promoted->archive)->toBe($old->archive)->and(hash('sha256', Storage::disk('s3')->get($old->archive)))->toBe($old->sha256)->and($old->refresh()->active)->toBeTrue();
    @unlink($path);
    @unlink($different);
});

it('kills the entire PDF delegate process group when a worker times out', function () {
    if (! BoundedPdfProcess::hasIsolation() || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Linux process isolation required');
    }
    $script  = tempnam(sys_get_temp_dir(), 'p2-pdf-child-');
    $pidFile = $script . '.pid';
    file_put_contents($script, '<?php $child=pcntl_fork();if($child===0){file_put_contents($argv[1],(string)getmypid());sleep(30);exit;}sleep(30);');

    try {
        expect(fn () => app(BoundedPdfProcess::class)->execute(['/usr/bin/setsid', '--', PHP_BINARY, $script, $pidFile], 1024, 0.5))->toThrow(ProcessTimedOutException::class);
        expect(is_file($pidFile))->toBeTrue();
        $child = (int) file_get_contents($pidFile);
        usleep(100000);
        $status = @file_get_contents('/proc/' . $child . '/status');
        expect($status === false || preg_match('/State:\s+Z/', $status) === 1)->toBeTrue();
    } finally {
        @unlink($script);
        @unlink($pidFile);
    }
});

it('rejects a rollback before any schema mutation when promoted cohorts collide with legacy uniqueness', function () {
    foreach (['pilot', 'stable'] as $channel) {
        IntegratorUpdate::forceCreate(['version' => '1.0.0', 'platform' => 'windows', 'arch' => 'x86', 'archive' => 'synthetic', 'sha256' => str_repeat('a', 64), 'signature' => 'synthetic', 'channel' => $channel, 'cohort' => $channel === 'pilot' ? 'oculus-pilot' : 'all', 'metadata' => ['schema_version' => 2]]);
    }
    $migration = require database_path('migrations/2026_10_03_000001_integrator_p2_contracts.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'P2 rollback reconciliation required');
    expect(Schema::hasColumn('integrator_updates', 'metadata'))->toBeTrue()->and(Schema::hasTable('integrator_equipment_operations'))->toBeTrue()->and(IntegratorUpdate::count())->toBe(2);
});

it('rejects signed numeric strings that Rust integer metadata cannot deserialize', function () {
    $path    = p2BoundaryInstaller();
    $fixture = p2ManifestFixture($path, '1.0.0', 'windows', 'x86', $this->secret);

    foreach (['schema_version', 'sequence', 'size_bytes', 'min_contract_version'] as $field) {
        $metadata         = $fixture['metadata'];
        $metadata[$field] = (string) $metadata[$field];
        expect(fn () => app(IntegratorUpdateManifest::class)->validate($metadata, $fixture['manifestSignature']))->toThrow(InvalidArgumentException::class, 'manifest_type_invalid');
    }
    @unlink($path);
});
