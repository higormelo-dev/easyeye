<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services\Credentials;

use App\Domains\Tiss\Actions\ResolveTissOperatorForCovenantAction;
use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use App\Domains\Tiss\Services\Transport\TissHttpClientFactory;
use App\Models\{Covenant, Entity};
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Support\Facades\{Crypt, DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Grava e remove as credenciais TISS da clínica (Configurações > Credenciais TISS).
 *
 *  - Uma credencial por clínica + operadora + ambiente (homologação ou produção). A linha provisória que o 1º
 *    faturamento cria sozinha (ResolveTissOperatorForCovenantAction) é aproveitada, não duplicada.
 *  - Senha, token e senha do certificado: cifrados (Crypt) e escritos só quando informados. Campo vazio mantém o
 *    que está salvo; "remover" apaga. Nenhum valor salvo volta para a tela.
 *  - Certificado: lido com a senha ANTES de gravar (arquivo ou senha errados = erro de validação, nada salvo) e
 *    guardado no disco privado `tiss-certificates`, em {clínica}/{credencial}/. Validade e titular vão para metadata.
 *  - Auditoria: o que mudou (endereço, se há senha/token/certificado), nunca o valor de um segredo.
 */
final class TissCredentialManager
{
    private const MAX_CERTIFICATE_BYTES = 65536;

    public function __construct(
        private readonly ResolveTissOperatorForCovenantAction $operators,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $data campos validados por TissCredentialRequest
     */
    public function save(
        Entity $entity,
        ?TissEntityOperatorCredential $credential,
        array $data,
        ?UploadedFile $certificate,
        ?UploadedFile $privateKey,
        Request $request,
    ): TissEntityOperatorCredential {
        $creating = $credential === null;
        $credential ??= $this->newOrAdopted($entity, (string) $data['covenant_id'], (string) $data['environment']);
        $before        = $this->summary($credential);
        $originsBefore = self::origins($credential);

        $credential->fill([
            'endpoint_submission' => self::nullableTrim($data['endpoint_submission'] ?? null),
            'endpoint_status'     => self::nullableTrim($data['endpoint_status'] ?? null),
            'username'            => self::nullableTrim($data['username'] ?? null),
            'ssl_enabled'         => (bool) ($data['ssl_enabled'] ?? true),
            'active'              => (bool) ($data['active'] ?? true),
        ]);

        $this->assertSecretsFollowNewOrigin($credential, $originsBefore, $data);
        $this->applySecret($credential, 'password_encrypted', $data['password'] ?? null, (bool) ($data['remove_password'] ?? false));
        $this->applySecret($credential, 'token_encrypted', $data['token'] ?? null, (bool) ($data['remove_token'] ?? false));

        $metadata = (array) ($credential->metadata ?? []);
        unset($metadata['provisioned_by'], $metadata['note']);

        // Certificado: arquivos novos só entram no disco depois de lidos com a senha; os antigos saem depois do commit.
        [$written, $obsolete] = $this->applyCertificate($credential, $metadata, $data, $certificate, $privateKey);
        $credential->metadata = $metadata;

        try {
            DB::transaction(fn () => $credential->save());
        } catch (Throwable $e) {
            $this->deleteFiles($written);

            // Dois cadastros simultâneos da mesma operadora e ambiente: a chave única barra o segundo.
            if ($e instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['covenant_id' => __('tiss_credentials.errors.duplicate')]);
            }

            throw $e;
        }

        $this->deleteFiles($obsolete);

        $this->audit->recordAdminAction(
            event: $creating ? 'tiss.credential.created' : 'tiss.credential.updated',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: TissEntityOperatorCredential::class,
            auditableId: (string) $credential->id,
            reason: 'Credencial TISS da clínica (Configurações > Credenciais TISS)',
            newValues: $this->summary($credential->fresh(['operator'])),
            request: $request,
            oldValues: $creating ? null : $before,
        );

        return $credential;
    }

    public function delete(Entity $entity, TissEntityOperatorCredential $credential, Request $request): void
    {
        $summary = $this->summary($credential);

        // Exclusão definitiva: libera a chave única (clínica + operadora + ambiente) para um novo cadastro.
        // Contratos que apontavam para ela ficam sem credencial (FK nullOnDelete) e o envio passa a usar a do ambiente.
        $credential->forceDelete();

        // A pasta inteira da credencial: inclui sobras de uma gravação que falhou no meio.
        Storage::disk(TissHttpClientFactory::DISK)->deleteDirectory($credential->entity_id . '/' . $credential->id);

        $this->audit->recordAdminAction(
            event: 'tiss.credential.deleted',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: TissEntityOperatorCredential::class,
            auditableId: (string) $credential->id,
            reason: 'Credencial TISS da clínica removida (Configurações > Credenciais TISS)',
            newValues: ['deleted' => true],
            request: $request,
            oldValues: $summary,
        );
    }

    /**
     * Retrato da credencial para a auditoria: o que está configurado, sem nenhum valor de segredo.
     *
     * @return array<string, mixed>
     */
    public function summary(TissEntityOperatorCredential $credential): array
    {
        return [
            'operator_ans'         => $credential->operator?->ans_code,
            'environment'          => $credential->environment,
            'endpoint_submission'  => self::host($credential->endpoint_submission),
            'endpoint_status'      => self::host($credential->endpoint_status),
            'username_set'         => filled($credential->username),
            'password_set'         => filled($credential->password_encrypted),
            'token_set'            => filled($credential->token_encrypted),
            'certificate_set'      => filled($credential->certificate_path),
            'certificate_valid_to' => $credential->metadata['certificate']['valid_to'] ?? null,
            'ssl_enabled'          => (bool) $credential->ssl_enabled,
            'active'               => (bool) $credential->active,
        ];
    }

    private function newOrAdopted(Entity $entity, string $covenantId, string $environment): TissEntityOperatorCredential
    {
        $covenant = Covenant::query()
            ->where(fn ($query) => $query->where('entity_id', $entity->id)->orWhereNull('entity_id'))
            ->find($covenantId);

        if (! $covenant) {
            throw ValidationException::withMessages(['covenant_id' => __('tiss_credentials.errors.covenant_not_found')]);
        }

        if (! $this->operators->isEligible($covenant)) {
            throw ValidationException::withMessages(['covenant_id' => __('tiss_credentials.errors.covenant_without_ans')]);
        }

        $operator = $this->operators->operatorFor($covenant);

        $existing = TissEntityOperatorCredential::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('entity_id', $entity->id)
            ->where('operator_id', $operator->id)
            ->where('environment', $environment)
            ->first();

        if ($existing && ! $existing->trashed() && ! $existing->isPlaceholder()) {
            throw ValidationException::withMessages(['covenant_id' => __('tiss_credentials.errors.duplicate')]);
        }

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing->setRelation('operator', $operator);
        }

        $credential = new TissEntityOperatorCredential([
            'entity_id'   => $entity->id,
            'operator_id' => $operator->id,
            'environment' => $environment,
        ]);
        // Id já conhecido: o certificado vai para a pasta da credencial antes do INSERT.
        $credential->id = (string) Str::uuid();

        return $credential->setRelation('operator', $operator);
    }

    /** "Apagar" vence o que foi digitado no mesmo envio: é o que a tela mostra (campo desabilitado). */
    private function applySecret(TissEntityOperatorCredential $credential, string $column, mixed $value, bool $remove): void
    {
        if ($remove) {
            $credential->{$column} = null;
        } elseif (filled($value)) {
            $credential->{$column} = Crypt::encryptString((string) $value);
        }
    }

    /**
     * Senha e token vão no cabeçalho para o endereço. Com destino novo, os salvos só seguem se digitados de novo: sem
     * isso, apontar o endereço para um servidor próprio e testar a conexão revelaria o segredo que a tela não mostra.
     *
     * @param list<string>         $originsBefore
     * @param array<string, mixed> $data
     */
    private function assertSecretsFollowNewOrigin(TissEntityOperatorCredential $credential, array $originsBefore, array $data): void
    {
        if (array_diff(self::origins($credential), $originsBefore) === []) {
            return;
        }

        $errors = [];

        foreach (['password' => 'password_encrypted', 'token' => 'token_encrypted'] as $field => $column) {
            if (filled($credential->{$column}) && blank($data[$field] ?? null) && ! ($data['remove_' . $field] ?? false)) {
                $errors[$field] = __('tiss_credentials.errors.secret_reentry');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return list<string> esquema://host:porta de cada endereço que recebe a autenticação (envio e teste) */
    private static function origins(TissEntityOperatorCredential $credential): array
    {
        $submission = $credential->submissionEndpoint();

        return array_values(array_unique(array_filter(array_map(function (?string $url): ?string {
            $parts = parse_url((string) $url);

            if (! isset($parts['host'])) {
                return null;
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));

            return $scheme . '://' . strtolower((string) $parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        }, [$submission, filled($credential->endpoint_status) ? (string) $credential->endpoint_status : $submission]))));
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $data
     *
     * @return array{0: list<string>, 1: list<string>} arquivos gravados agora, arquivos a apagar depois do commit
     */
    private function applyCertificate(
        TissEntityOperatorCredential $credential,
        array &$metadata,
        array $data,
        ?UploadedFile $certificate,
        ?UploadedFile $privateKey,
    ): array {
        $disk        = Storage::disk(TissHttpClientFactory::DISK);
        $newPassword = self::nullableString($data['certificate_password'] ?? null);
        $oldFiles    = array_values(array_filter([$credential->certificate_path, $credential->private_key_path]));

        // "Apagar" vence um arquivo escolhido no mesmo envio (a tela desabilita os campos).
        if ((bool) ($data['remove_certificate'] ?? false)) {
            $credential->forceFill(['certificate_path' => null, 'private_key_path' => null, 'certificate_password_encrypted' => null]);
            unset($metadata['certificate']);

            return [[], $oldFiles];
        }

        if (! $certificate) {
            if ($newPassword !== null && filled($credential->certificate_path)) {
                // Só a senha mudou: ela precisa abrir o certificado (e a chave) já salvos.
                $this->readCertificate(
                    (string) $disk->get((string) $credential->certificate_path),
                    (string) $credential->certificate_path,
                    filled($credential->private_key_path) ? (string) $disk->get((string) $credential->private_key_path) : null,
                    $newPassword,
                    passwordOnly: true,
                );
                $credential->certificate_password_encrypted = Crypt::encryptString($newPassword);
            }

            return [[], []];
        }

        $password = $newPassword ?? $this->savedCertificatePassword($credential) ?? '';
        $keyPem   = $privateKey ? (string) file_get_contents($privateKey->getRealPath()) : null;
        $info     = $this->readCertificate((string) file_get_contents($certificate->getRealPath()), $certificate->getClientOriginalName(), $keyPem, $password);

        $folder   = $credential->entity_id . '/' . $credential->id . '/';
        $certPath = $folder . 'certificate-' . now()->format('YmdHis') . '.' . $info['extension'];
        $keyPath  = $info['type'] === 'pem' && $keyPem !== null ? $folder . 'private-key-' . now()->format('YmdHis') . '.pem' : null;

        $files   = $keyPath !== null ? [$certPath => $info['contents'], $keyPath => $keyPem] : [$certPath => $info['contents']];
        $written = [];

        foreach ($files as $path => $contents) {
            // O disco não lança erro (throw => false): sem conferir, o banco apontaria para um arquivo que não existe.
            if (! $disk->put($path, (string) $contents, 'private')) {
                $this->deleteFiles($written);

                throw ValidationException::withMessages(['certificate' => __('tiss_credentials.errors.certificate_store_failed')]);
            }

            $written[] = $path;
        }

        $credential->forceFill([
            'certificate_path'               => $certPath,
            'private_key_path'               => $keyPath,
            'certificate_password_encrypted' => $password !== '' ? Crypt::encryptString($password) : null,
        ]);

        $metadata['certificate'] = [
            'type'        => $info['type'],
            'subject'     => $info['subject'],
            'valid_to'    => $info['valid_to'],
            'uploaded_at' => now()->toIso8601String(),
        ];

        return [$written, array_values(array_diff($oldFiles, $written))];
    }

    /**
     * Lê o certificado do cliente com a senha: PKCS#12 (.pfx/.p12, o padrão do ICP-Brasil A1) ou PEM/CRT/CER com a
     * chave privada à parte (ou no mesmo arquivo PEM).
     *
     * @return array{type: 'pkcs12'|'pem', extension: string, contents: string, subject: ?string, valid_to: ?string}
     */
    private function readCertificate(string $contents, string $name, ?string $keyPem, string $password, bool $passwordOnly = false): array
    {
        // Só a senha mudou: o erro aparece no campo da senha, o único que a pessoa mexeu.
        $certificateField = $passwordOnly ? 'certificate_password' : 'certificate';
        $keyField         = $passwordOnly ? 'certificate_password' : 'private_key';
        $fail             = fn (string $field, string $key) => ValidationException::withMessages([$field => __('tiss_credentials.errors.' . $key)]);

        if ($contents === '' || strlen($contents) > self::MAX_CERTIFICATE_BYTES) {
            throw $fail($certificateField, 'certificate_unreadable');
        }

        if (TissHttpClientFactory::isPkcs12($name)) {
            $parts = [];
            self::opensslErrors(); // descarta erros antigos da fila do OpenSSL

            if (! openssl_pkcs12_read($contents, $parts, $password)) {
                // .pfx antigo (RC2/3DES): o OpenSSL 3 do servidor não abre sem o provedor legado.
                throw $fail($certificateField, str_contains(strtolower(self::opensslErrors()), 'unsupported') ? 'certificate_legacy' : 'certificate_unreadable');
            }

            // Exportado sem a chave: o PHP lê, mas o certificado vai para extracerts e não há pkey.
            if (empty($parts['pkey'])) {
                throw $fail($certificateField, 'certificate_without_key');
            }

            if (empty($parts['cert'])) {
                throw $fail($certificateField, 'certificate_unreadable');
            }

            return ['type' => 'pkcs12', 'extension' => 'p12', 'contents' => $contents, ...$this->describe((string) $parts['cert'])];
        }

        // PEM, ou DER (.cer binário) convertido para PEM.
        $pem = str_contains($contents, '-----BEGIN CERTIFICATE-----')
            ? $contents
            : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($contents), 64, "\n") . "-----END CERTIFICATE-----\n";

        $x509 = @openssl_x509_read($pem);

        if ($x509 === false) {
            throw $fail($certificateField, 'certificate_unreadable');
        }

        $keySource = $keyPem ?? (str_contains($contents, 'PRIVATE KEY-----') ? $contents : null);

        if ($keySource === null) {
            throw $fail($keyField, 'private_key_required');
        }

        $key = @openssl_pkey_get_private($keySource, $password);

        if ($key === false || ! openssl_x509_check_private_key($x509, $key)) {
            throw $fail($keyField, 'private_key_mismatch');
        }

        return ['type' => 'pem', 'extension' => 'pem', 'contents' => $pem, ...$this->describe($pem)];
    }

    private static function opensslErrors(): string
    {
        $errors = [];

        while (($message = openssl_error_string()) !== false) {
            $errors[] = $message;
        }

        return implode(' ', $errors);
    }

    /** @return array{subject: ?string, valid_to: ?string} */
    private function describe(string $certificatePem): array
    {
        $parsed = openssl_x509_parse($certificatePem) ?: [];
        $until  = isset($parsed['validTo_time_t']) ? now()->setTimestamp((int) $parsed['validTo_time_t'])->toDateString() : null;

        return [
            'subject'  => isset($parsed['subject']['CN']) ? mb_substr((string) (is_array($parsed['subject']['CN']) ? $parsed['subject']['CN'][0] : $parsed['subject']['CN']), 0, 200) : null,
            'valid_to' => $until,
        ];
    }

    private function savedCertificatePassword(TissEntityOperatorCredential $credential): ?string
    {
        if (blank($credential->certificate_password_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $credential->certificate_password_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $paths */
    private function deleteFiles(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(TissHttpClientFactory::DISK)->delete($paths);
        }
    }

    private static function host(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url((string) $url);

        return isset($parts['host']) ? ($parts['scheme'] ?? 'https') . '://' . $parts['host'] : null;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
