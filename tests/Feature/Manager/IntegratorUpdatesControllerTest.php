<?php

use App\Enums\SaasRule;
use App\Http\Controllers\Manager\IntegratorUpdatesController;
use App\Models\{Entity, IntegratorUpdate, User};
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->saas       = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->saas, $this->user, SaasRule::Admin->value);
    Storage::fake('s3');
});

function validSignature(): string
{
    return base64_encode(str_repeat("\x02", 64));
}

function storeUpdate(array $payload, ?UploadedFile $file = null)
{
    test()->actingAs(test()->user);
    session(['selected_entity_id' => test()->saas->id]);

    $request = Request::create('/panel/manager/integrator-updates', 'POST', $payload);

    if ($file !== null) {
        $request->files->set('file', $file);
    }
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => test()->user);
    app()->instance('request', $request);

    return app(IntegratorUpdatesController::class)->store($request);
}

it('publishes an installer and deactivates previous builds of the same target', function () {
    // IntegratorUpdatePublisher::verifySignature() faz verificação criptográfica
    // real (ed25519) contra services.integrator_updates.public_key — a antiga
    // validSignature() (64 bytes fixos) só satisfazia o check de formato e não
    // verifica mais. Aqui geramos um par de chaves efêmero, apontamos a config
    // pra chave pública dele, e assinamos de verdade o SHA-256 do arquivo
    // enviado — mesma convenção de scripts/sign-update.sh (mensagem = bytes
    // crus do digest, não a string hex).
    $keyPair = sodium_crypto_sign_keypair();
    config(['services.integrator_updates.public_key' => bin2hex(sodium_crypto_sign_publickey($keyPair))]);
    $secretKey = sodium_crypto_sign_secretkey($keyPair);

    IntegratorUpdate::forceCreate([
        'version' => '0.1.0', 'platform' => 'windows', 'arch' => 'x86',
        'archive' => 'integrator-updates/0.1.0/old.msi',
        'sha256'  => str_repeat('cd', 32), 'signature' => validSignature(), 'active' => true,
    ]);

    $file      = UploadedFile::fake()->create('EasyEye-Integrator-0.2.0-x86.msi', 512);
    $digest    = hash_file('sha256', $file->getRealPath(), true);
    $signature = base64_encode(sodium_crypto_sign_detached($digest, $secretKey));

    storeUpdate([
        'version'   => '0.2.0',
        'platform'  => 'windows',
        'arch'      => 'x86',
        'signature' => $signature,
    ], $file);

    $published = IntegratorUpdate::where('version', '0.2.0')->first();
    expect($published)->not->toBeNull()
        ->and($published->active)->toBeTrue()
        ->and($published->archive)->toBe('integrator-updates/0.2.0/EasyEye-Integrator-0.2.0-x86.msi')
        ->and(strlen($published->sha256))->toBe(64)
        ->and(IntegratorUpdate::where('version', '0.1.0')->first()->active)->toBeFalse();

    Storage::disk('s3')->assertExists('integrator-updates/0.2.0/EasyEye-Integrator-0.2.0-x86.msi');
});

it('rejects a malformed signature without storing anything', function () {
    $file = UploadedFile::fake()->create('EasyEye-Integrator-0.2.0-x86.msi', 512);

    storeUpdate([
        'version'   => '0.2.0',
        'platform'  => 'windows',
        'arch'      => 'x86',
        'signature' => 'nao-e-base64-de-64-bytes',
    ], $file);

    expect(IntegratorUpdate::count())->toBe(0);
});

it('rejects a non-installer file extension', function () {
    $file = UploadedFile::fake()->create('malware.php', 10);

    storeUpdate([
        'version'   => '0.2.0',
        'platform'  => 'windows',
        'arch'      => 'x86',
        'signature' => validSignature(),
    ], $file);

    expect(IntegratorUpdate::count())->toBe(0);
});

it('toggles a build active flag without deleting history', function () {
    $update = IntegratorUpdate::forceCreate([
        'version' => '0.2.0', 'platform' => 'windows', 'arch' => 'x86',
        'archive' => 'integrator-updates/0.2.0/x.msi',
        'sha256'  => str_repeat('ab', 32), 'signature' => validSignature(), 'active' => true,
    ]);

    test()->actingAs(test()->user);
    session(['selected_entity_id' => test()->saas->id]);
    $request = Request::create("/panel/manager/integrator-updates/{$update->id}", 'PATCH', ['active' => false]);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => test()->user);

    app(IntegratorUpdatesController::class)->update($request, $update);

    expect($update->fresh()->active)->toBeFalse()
        ->and(IntegratorUpdate::count())->toBe(1);
});
