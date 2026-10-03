<?php
declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class IntegratorUpdateManifest
{
    public const FIELDS = ['release_id', 'sequence', 'version', 'platform', 'arch', 'sha256', 'size_bytes', 'issued_at', 'expires_at', 'channel', 'cohort', 'min_client_version', 'min_contract_version', 'min_os_version', 'max_os_version', 'asset_signature', 'key_id', 'rollback_from'];

    public function validate(array $metadata, string $signature): array
    {
        foreach (['schema_version', 'sequence', 'size_bytes', 'min_contract_version'] as $field) {
            if (! isset($metadata[$field]) || ! is_int($metadata[$field])) {
                throw new InvalidArgumentException('manifest_type_invalid');
            }
        }
        $semver = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[A-Za-z0-9.-]+)?$/';
        $rules  = ['schema_version' => ['required', 'integer', 'in:2'], 'release_id' => ['required', 'uuid'], 'sequence' => ['required', 'integer', 'between:1,9223372036854775807'], 'version' => ['required', 'regex:' . $semver], 'platform' => ['required', 'in:windows,linux,macos'], 'arch' => ['required', 'in:x86,x86_64,aarch64'], 'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'size_bytes' => ['required', 'integer', 'between:1,268435456'], 'issued_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'], 'expires_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:issued_at'], 'channel' => ['required', 'in:pilot,stable'], 'cohort' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'], 'min_client_version' => ['required', 'regex:' . $semver], 'min_contract_version' => ['required', 'integer', 'between:1,100'], 'min_os_version' => ['required', 'regex:/^\d{1,2}\.\d{1,2}(?:\.\d{1,5})?$/'], 'max_os_version' => ['present', 'nullable', 'regex:/^\d{1,2}\.\d{1,2}(?:\.\d{1,5})?$/'], 'asset_signature' => ['required', 'string', 'max:128'], 'key_id' => ['required', 'in:release-v1'], 'rollback_from' => ['present', 'nullable', 'regex:' . $semver]];

        if (array_diff(array_keys($metadata), array_keys($rules))) {
            throw new InvalidArgumentException('manifest_unknown_field');
        }
        $v = Validator::make($metadata, $rules);

        if ($v->fails()) {
            throw new InvalidArgumentException('manifest_invalid: ' . implode(' ', $v->errors()->all()));
        }

        if ($metadata['channel'] === 'pilot' && $metadata['cohort'] === 'all') {
            throw new InvalidArgumentException('manifest_pilot_cohort_required');
        }

        if ($metadata['channel'] === 'stable' && $metadata['cohort'] !== 'all') {
            throw new InvalidArgumentException('manifest_stable_cohort_invalid');
        }

        if (Carbon::parse($metadata['issued_at'])->isAfter(now()->addMinutes(5)) || Carbon::parse($metadata['expires_at'])->isPast()) {
            throw new InvalidArgumentException('manifest_time_invalid');
        }

        if ($metadata['max_os_version'] !== null && version_compare($metadata['max_os_version'], $metadata['min_os_version'], '<')) {
            throw new InvalidArgumentException('manifest_os_range_invalid');
        }
        $sig = base64_decode($signature, true);
        $key = @hex2bin(trim((string) config('services.integrator_updates.public_key')));

        if ($sig === false || strlen($sig) !== 64 || $key === false || strlen($key) !== 32 || $key === str_repeat("\0", 32) || ! sodium_crypto_sign_verify_detached($sig, $this->canonical($metadata), $key)) {
            throw new InvalidArgumentException('manifest_signature_invalid');
        }

        return $metadata;
    }

    public function canonical(array $metadata): string
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $metadata)) {
                throw new InvalidArgumentException('manifest_missing_field');
            }$value = $metadata[$field];

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('manifest_type_invalid');
            }$string = $value === null ? '' : (string) $value;

            if (preg_match('/[^\x20-\x7E]/', $string)) {
                throw new InvalidArgumentException('manifest_ascii_required');
            }$values[] = $string;
        }

        return "easyeye-integrator-update-v2\n" . implode("\n", $values) . "\n";
    }

    public function trustedAssetUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || str_contains($host, ':')) {
            return false;
        }
        $api = parse_url((string) config('app.url'));

        if (is_array($api) && ($api['scheme'] ?? '') === 'https'
            && (! isset($api['port']) || $api['port'] === 443) && $host === strtolower($api['host'] ?? '')) {
            return true;
        }

        // AWS controls these finite S3 DNS namespaces. No suffix/substring matching.
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,61}[a-z0-9])?\.)?s3(?:\.[a-z0-9-]+)?\.amazonaws\.com$/D', $host) === 1;
    }
}
