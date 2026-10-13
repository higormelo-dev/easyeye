<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\{LockedMedicalRecordException, StaleMedicalRecordRevisionException};
use App\Models\EntityUser;
use App\Services\AuditService;
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
    /**
     * Versão do algoritmo de hash — NUNCA mudar silenciosamente: uma
     * assinatura já existente foi calculada com a fórmula que estava ativa
     * no momento, e verifyIntegrity() precisa saber QUAL fórmula reaplicar
     * pra cada uma. Hash sem o prefixo "N:" é da versão 1 (antes desta
     * correção existir) — ver legacyClinicalContent() (achado via auditoria
     * de encerramento, EE-03).
     */
    private const SIGNATURE_HASH_VERSION = 2;

    public static function bootSignable(): void
    {
        static::updating(function (self $model): void {
            if ($model->is_locked) {
                throw new LockedMedicalRecordException(
                    "Prontuário {$model->code} está assinado e não pode ser alterado. " .
                    'Para retificação, crie um novo registro com referência a este.',
                );
            }
        });

        static::deleting(function (self $model): void {
            if ($model->is_locked) {
                throw new LockedMedicalRecordException(
                    "Prontuário {$model->code} está assinado e não pode ser excluído.",
                );
            }
        });
    }

    /**
     * Assina o prontuário digitalmente.
     * Gera hash SHA-256 do conteúdo clínico + metadados do ato médico.
     * Após a assinatura o registro é bloqueado para edição.
     *
     * $expectedRevision (clinical_revision) é o que a tela mostrou pro
     * médico — comparado SOB O MESMO LOCK que whileUnlocked() já tira,
     * contra o valor FRESCO no banco. null pula a checagem (callers
     * internos/testes que não passam pela tela, ex.: nenhum endpoint HTTP
     * chama sign() sem revisão — achado via revisão adversarial Codex: o
     * endpoint de assinatura aceitava qualquer corpo, inclusive vazio, e
     * assinava o que estivesse no banco na hora do clique, não o que o
     * médico efetivamente revisou).
     *
     * @throws LockedMedicalRecordException        se já estiver assinado
     * @throws StaleMedicalRecordRevisionException se $expectedRevision não bate com o clinical_revision atual
     */
    public function sign(EntityUser $entityUser, ?int $expectedRevision = null): void
    {
        $this->whileUnlocked(function (self $locked) use ($entityUser, $expectedRevision): void {
            if ($expectedRevision !== null && $locked->clinical_revision !== $expectedRevision) {
                throw new StaleMedicalRecordRevisionException(
                    "Prontuário {$locked->code} (ou seus documentos/anexos) foi alterado por outra sessão depois que esta tela carregou. " .
                    'Recarregue e revise o conteúdo atual antes de assinar.',
                );
            }

            $signedAt = now();

            // Hash garante integridade: exclui os campos de assinatura para que
            // o hash seja reproduzível na verificação, independente de quando é calculado.
            $contentHash   = hash('sha256', serialize($locked->clinicalContent()));
            $signatureHash = self::SIGNATURE_HASH_VERSION . ':' . hash('sha256', implode('|', [
                self::SIGNATURE_HASH_VERSION,
                $locked->getKey(),
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

            // BUGFIX (achado durante a Fase 2 do Portal do Paciente — este
            // método nunca tinha caller nem teste até agora): save()/saveQuietly()
            // recebem OPÇÕES de save (ex.: ['touch' => false]), não atributos.
            // `$locked->saveQuietly($newAttributes)` não seta nada em $locked —
            // sem atributo dirty, isDirty() é false e NENHUM UPDATE chega a ir
            // pro banco. forceFill() (campos de assinatura não são $fillable de
            // propósito) marca os atributos como dirty ANTES do save, pra
            // saveQuietly() realmente persistir. saveQuietly() (sem args)
            // continua suprimindo os eventos Eloquent — evita disparar de novo o
            // guard `updating` do próprio Signable e duplicar o audit_log
            // genérico do Auditable (o evento 'signed' abaixo já cobre a trilha).
            $locked->forceFill($newAttributes)->saveQuietly();

            // Audit manual: saveQuietly bypassa todos os eventos Eloquent, incluindo
            // o hook do Auditable. A assinatura é o ato médico mais importante
            // juridicamente — precisa constar no audit_log obrigatoriamente. Mesma
            // transação do lock (whileUnlocked): se isto falhar, a assinatura
            // inteira desfaz — nunca fica persistida sem o registro de auditoria
            // (achado via auditoria de encerramento, EE-04).
            app(AuditService::class)->log(
                event: 'signed',
                model: $locked,
                old: ['signed_by' => null, 'signed_at' => null, 'signature_hash' => null, 'is_locked' => false],
                new: ['signed_by' => $entityUser->getKey(), 'signed_at' => $signedAt->toIso8601String(), 'signature_hash' => $signatureHash, 'is_locked' => true],
            );

            // $this (a instância que o caller tem em mãos) passa a refletir o
            // que foi gravado — mesmo contrato de antes (sign() mutava $this
            // direto), só que agora a gravação em si rodou sobre $locked.
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Roda $change com a linha travada no banco (FOR UPDATE) e só se o
     * prontuário ainda NÃO estiver assinado — outra aba/requisição pode ter
     * assinado, editado ou excluído entre o carregamento desta instância e
     * agora. Os hooks updating/deleting (bootSignable) checam is_locked só
     * em memória: suficiente pra o caminho feliz, mas uma instância antiga
     * (is_locked=false em memória) passava por eles mesmo com o registro já
     * assinado no banco por outra conexão — nenhuma transação serializava
     * assinar × editar × excluir (achado via auditoria de encerramento,
     * EE-04). $change recebe a linha FRESCA e travada, nunca $this.
     *
     * @template T
     *
     * @param callable(static): T $change
     *
     * @return T
     *
     * @throws LockedMedicalRecordException
     */
    private function whileUnlocked(callable $change): mixed
    {
        // $this->getConnection() (não a facade DB::, que é sempre a conexão
        // DEFAULT) — uma instância explicitamente ligada a outra conexão
        // (Model::on('outra')->find(...), ex.: teste de concorrência com
        // duas sessões PostgreSQL reais) precisa travar E transacionar
        // NELA, não na default.
        return $this->getConnection()->transaction(function () use ($change) {
            /** @var static|null $locked */
            $locked = static::on($this->getConnectionName())->whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new LockedMedicalRecordException("Prontuário {$this->code} não foi encontrado.");
            }

            if ($locked->is_locked) {
                throw new LockedMedicalRecordException(
                    "Prontuário {$locked->code} já foi assinado em {$locked->signed_at->format('d/m/Y H:i')}.",
                );
            }

            return $change($locked);
        });
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

        [$version, $storedHash] = $this->parseSignatureHash((string) $this->signature_hash);

        // v1 (legado, sem prefixo — achado via auditoria de encerramento,
        // EE-03): fórmula ORIGINAL, sabidamente instável (updated_at
        // entrava no hash; ordem de getAttributes() não é determinística),
        // mantida só pra continuar verificando o que já foi assinado assim
        // — sign() só grava v2+ a partir de agora, nunca reescreve/
        // recalcula uma assinatura existente pra "virar" v2.
        $expected = $version === 1
            ? hash('sha256', implode('|', [
                $this->getKey(),
                $this->signed_by,
                $this->signed_at->toIso8601String(),
                hash('sha256', serialize($this->legacyClinicalContent())),
            ]))
            : hash('sha256', implode('|', [
                $version,
                $this->getKey(),
                $this->signed_by,
                $this->signed_at->toIso8601String(),
                hash('sha256', serialize($this->clinicalContent())),
            ]));

        return hash_equals($storedHash, $expected);
    }

    /**
     * @return array{0: int, 1: string} [versão do algoritmo, hash sem o prefixo]
     */
    private function parseSignatureHash(string $stored): array
    {
        if (preg_match('/^(\d+):(.+)$/', $stored, $matches) === 1) {
            return [(int) $matches[1], $matches[2]];
        }

        // Sem prefixo "N:" — assinado antes de SIGNATURE_HASH_VERSION existir.
        return [1, $stored];
    }

    /**
     * Retorna apenas os atributos clínicos para composição do hash (v2+).
     * Exclui os campos de assinatura E updated_at/updated_by — o PRÓPRIO
     * save() da assinatura (forceFill+saveQuietly() em sign()) toca
     * updated_at automaticamente, DEPOIS do hash já calculado; sem excluir,
     * a verificação após recarregar do banco nunca batia (achado via
     * auditoria de encerramento, EE-03; Signable já bloqueia qualquer
     * update posterior via is_locked, então nenhum dos dois muda depois de
     * assinado por outro motivo). ksort(): getAttributes() vem em ORDEM
     * DIFERENTE pra um model recém-criado em memória vs recarregado do
     * banco (mesmo conteúdo, chaves em ordem diferente) — sem ordenar,
     * serialize() produzia bytes diferentes pra conteúdo logicamente igual.
     * sign() só chama isto sobre a instância FRESCA e travada que
     * whileUnlocked() entrega (nunca sobre $this, que pode estar
     * desatualizado) — por isso já se pode contar com getAttributes()
     * completo e correto aqui.
     *
     * @return array<string, mixed>
     */
    private function clinicalContent(): array
    {
        $content = array_diff_key(
            $this->getAttributes(),
            array_flip([
                'signed_by', 'signed_at', 'signature_hash', 'is_locked', 'updated_at', 'updated_by',
                ...$this->signatureExcludedAttributes(),
            ]),
        );

        // Coluna criada depois de já haver assinaturas: vazia, fica fora do
        // hash — senão a chave nova (null) invalidaria toda assinatura
        // anterior à coluna (o hash serializa todos os atributos).
        foreach ($this->signatureOptionalAttributes() as $column) {
            if (array_key_exists($column, $content) && $content[$column] === null) {
                unset($content[$column]);
            }
        }

        ksort($content);

        return $content;
    }

    /**
     * Fórmula ORIGINAL (v1, antes desta correção) — NUNCA alterar: é o que
     * assinaturas legadas (signature_hash sem prefixo "N:") de fato usaram.
     * Sabidamente instável (updated_at entra no hash; ordem de
     * getAttributes() não é determinística) — mantida só pra continuar
     * verificando o que já foi assinado assim, nunca pra assinar de novo
     * (achado via auditoria de encerramento, EE-03).
     *
     * @return array<string, mixed>
     */
    private function legacyClinicalContent(): array
    {
        $content = array_diff_key(
            $this->getAttributes(),
            array_flip([
                'signed_by', 'signed_at', 'signature_hash', 'is_locked',
                ...$this->signatureExcludedAttributes(),
            ]),
        );

        foreach ($this->signatureOptionalAttributes() as $column) {
            if (array_key_exists($column, $content) && $content[$column] === null) {
                unset($content[$column]);
            }
        }

        return $content;
    }

    /**
     * Colunas adicionadas depois de existirem prontuários assinados: quando
     * vazias, não entram no hash (o que foi assinado antes segue conferindo).
     *
     * @return list<string>
     */
    protected function signatureOptionalAttributes(): array
    {
        return [];
    }

    /**
     * Colunas que NUNCA entram no hash de integridade, mesmo com valor —
     * diferente de signatureOptionalAttributes() (só exclui quando vazia,
     * p/ compatibilidade com assinaturas anteriores à coluna). Uso: contador
     * de concorrência (ex.: clinical_revision em MedicalRecord) que já nasce
     * com valor (default no banco) mesmo pra registros assinados ANTES da
     * coluna existir — incluir mudaria o hash de uma assinatura antiga sem
     * o conteúdo clínico em si ter mudado.
     *
     * @return list<string>
     */
    protected function signatureExcludedAttributes(): array
    {
        return [];
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
