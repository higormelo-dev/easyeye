<?php

use App\Services\IntegratorUpdateManifest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function p2ManifestFixture(string $path, string $version = '1.0.0', string $platform = 'windows', string $arch = 'x86', ?string $secret = null, array $changes = []): array
{
    // Synthetic object-storage URL: no request is made to this fixture host.
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path, $expires, array $options) => 'https://synthetic-only.s3.us-east-1.amazonaws.com/' . $path . '?expires=' . $expires->getTimestamp(),
    );
    $secret ??= function_exists('testUpdateKeypair') ? testUpdateKeypair()['secret'] : sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('P', 32)));
    $asset    = base64_encode(sodium_crypto_sign_detached(hex2bin(hash_file('sha256', $path)), $secret));
    $metadata = array_replace(['schema_version' => 2, 'release_id' => (string) Str::uuid(), 'sequence' => time(), 'version' => $version, 'platform' => $platform, 'arch' => $arch, 'sha256' => hash_file('sha256', $path), 'size_bytes' => filesize($path), 'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => now()->addDays(30)->utc()->format('Y-m-d\TH:i:s\Z'), 'channel' => 'stable', 'cohort' => 'all', 'min_client_version' => '0.1.0', 'min_contract_version' => 1, 'min_os_version' => '6.1.7601', 'max_os_version' => null, 'asset_signature' => $asset, 'key_id' => 'release-v1', 'rollback_from' => null], $changes);

    return ['metadata' => $metadata, 'manifestSignature' => base64_encode(sodium_crypto_sign_detached(app(IntegratorUpdateManifest::class)->canonical($metadata), $secret))];
}
function p2CliMetadata(string $path, string $version, string $platform, string $arch): array
{
    $fixture = p2ManifestFixture($path, $version, $platform, $arch);
    $json    = tempnam(sys_get_temp_dir(), 'manifest-p2-');
    file_put_contents($json, json_encode($fixture['metadata'], JSON_THROW_ON_ERROR));

    return ['--metadata' => $json, '--manifest-signature' => $fixture['manifestSignature']];
}

function p2WriteArtifact(string $name, string $content): void
{
    $directory = getenv('P2_ARTIFACT_DIR') ?: storage_path('framework/testing/p2');

    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }file_put_contents($directory . '/' . $name, $content);
}
