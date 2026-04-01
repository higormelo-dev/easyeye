<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\LockedMedicalRecordException;
use App\Models\EntityUser;
use App\Services\AuditService;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assinatura eletrônica de prontuários.
 *
 * CFM Resolução 2.227/2018 — o prontuário eletrônico deve garantir:
 *   - Autoria: identificação do médico que assinou
 *   - Integridade: hash do conteúdo no momento da assinatura
 *   - Imutabilidade: registro bloqueado após assinatura
 *
 * Requer que a tabela possua: signed_by, signed_at, signature_hash, is_locked.
 * Aplicar apenas em MedicalRecord.
 */
trait Signable
{
    public static function bootSignable(): void
    {
        static::updating(function (self $model): void {
            if ($model->is_locked) {
                throw new LockedMedicalRecordException(
                    "Prontuário {$model->code} está assinado e não pode ser alterado. " .
                    'Para retificação, crie um novo registro com referência a este.'
                );
            }
        });

        static::deleting(function (self $model): void {
            if ($model->is_locked) {
                throw new LockedMedicalRecordException(
                    "Prontuário {$model->code} está assinado e não pode ser excluído."
                );
            }
        });
    }

    /**
     * Assina o prontuário digitalmente.
     * Gera hash SHA-256 do conteúdo clínico + metadados do ato médico.
     * Após a assinatura o registro é bloqueado para edição.
     *
     * @throws LockedMedicalRecordException se já estiver assinado
     */
    public function sign(EntityUser $entityUser): void
    {
        if ($this->is_locked) {
            throw new LockedMedicalRecordException(
                "Prontuário {$this->code} já foi assinado em {$this->signed_at->format('d/m/Y H:i')}."
            );
        }

        $signedAt = now();

        // Hash garante integridade: exclui os campos de assinatura para que
        // o hash seja reproduzível na verificação, independente de quando é calculado.
        $contentHash = hash('sha256', serialize($this->clinicalContent()));
        $signatureHash = hash('sha256', implode('|', [
            $this->getKey(),
            $entityUser->getKey(),
            $signedAt->toIso8601String(),
            $contentHash,
        ]));

        $newAttributes = [
            'signed_by'      => $entityUser->getKey(),
            'signed_at'      => $signedAt,
            'signature_hash' => $signatureHash,
            'is_locked'      => true,
        ];

        // saveQuietly para não disparar o guard do updating novamente
        $this->saveQuietly($newAttributes);

        // Atributos precisam ser sincronizados no model em memória
        $this->setRawAttributes(array_merge($this->getAttributes(), $newAttributes));

        // Audit manual: saveQuietly bypassa todos os eventos Eloquent, incluindo
        // o hook do Auditable. A assinatura é o ato médico mais importante
        // juridicamente — precisa constar no audit_log obrigatoriamente.
        app(AuditService::class)->log(
            event: 'signed',
            model: $this,
            old: ['signed_by' => null, 'signed_at' => null, 'signature_hash' => null, 'is_locked' => false],
            new: ['signed_by' => $entityUser->getKey(), 'signed_at' => $signedAt->toIso8601String(), 'signature_hash' => $signatureHash, 'is_locked' => true],
        );
    }

    /**
     * Verifica a integridade do prontuário.
     * Compara o hash armazenado com o hash recalculado do conteúdo clínico atual.
     * Retorna false se o conteúdo foi adulterado diretamente no banco.
     */
    public function verifyIntegrity(): bool
    {
        if (! $this->is_locked || ! $this->signature_hash) {
            return false;
        }

        $contentHash = hash('sha256', serialize($this->clinicalContent()));

        return hash_equals(
            $this->signature_hash,
            hash('sha256', implode('|', [
                $this->getKey(),
                $this->signed_by,
                $this->signed_at->toIso8601String(),
                $contentHash,
            ]))
        );
    }

    /**
     * Retorna apenas os atributos clínicos para composição do hash.
     * Exclui os campos de assinatura para que o hash seja reproduzível
     * tanto no momento da assinatura quanto na verificação posterior.
     *
     * @return array<string, mixed>
     */
    private function clinicalContent(): array
    {
        return array_diff_key(
            $this->getAttributes(),
            array_flip(['signed_by', 'signed_at', 'signature_hash', 'is_locked'])
        );
    }

    public function isLocked(): bool
    {
        return (bool) $this->is_locked;
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null && $this->signature_hash !== null;
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'signed_by');
    }
}
