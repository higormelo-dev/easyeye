<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\LockedMedicalRecordException;
use App\Models\MedicalRecord;
use Illuminate\Events\NullDispatcher;

/**
 * Conteúdo clínico filho do prontuário (MedicalRecordDocumentation,
 * MedicalRecordFile, MedicalRecordEvolution, MedicalRecordProcedure) não é
 * criado, alterado nem excluído quando o prontuário dono está ASSINADO (CFM
 * 2.227/2018 — Signable). Antes só a tela desabilitava; a trava no model cobre
 * todos os caminhos (quick actions, documentação, tonometria, laudo do
 * Gerenciador de Imagens, laudo de IA, anexos, evolução, solicitação de
 * procedimento). O LockedMedicalRecordException vira 403 traduzido
 * (bootstrap/app.php) quando o caller não trata.
 *
 * save()/delete() leem is_locked com lockForUpdate() NA MESMA transação da
 * escrita — não só nos eventos creating/updating/deleting (que continuam
 * disparando normalmente dentro de save(), sem mudança de comportamento
 * pra quem já escuta eles). Sem o lock, a checagem era uma leitura solta: um
 * sign() concorrente (que trava a linha do prontuário com FOR UPDATE em
 * Signable::whileUnlocked()) podia comitar ENTRE esta leitura (ainda via
 * is_locked=false) e a escrita do filho, que então ia pro banco contra um
 * prontuário que terminou assinado — a assinatura continua verificando certo
 * porque o hash cobre só as colunas do prontuário, nunca os filhos
 * relacionados (achado via revisão adversarial Codex). lockForUpdate() na
 * MESMA transação faz esta escrita ESPERAR o sign() concorrente terminar e
 * então ver o is_locked=true já comitado.
 *
 * clinical_revision do prontuário dono sobe a cada escrita de conteúdo
 * (criar, alterar ou excluir um filho), na MESMA transação e sob o MESMO lock
 * acima — achado via revisão adversarial Codex: o token só subia quando a
 * linha do PRÓPRIO prontuário era atualizada, então outro médico podia emitir
 * ou excluir um laudo, registrar uma evolução ou solicitar um procedimento
 * depois de a gaveta de quem vai assinar carregar e Signable::sign() ainda
 * aceitava a revisão antiga, travando (e liberando pro paciente) um conjunto
 * de conteúdo que o signatário nunca revisou. sign() trava a mesma linha:
 * quem chega depois enxerga o resultado do outro (revisão nova → assinatura
 * recusada; ou prontuário já assinado → escrita recusada). Bookkeeping de
 * entrega (saveQuietly) não passa por aqui e, portanto, não invalida a
 * revisão.
 *
 * Exceção deliberada, opt-in por model (signedRecordWritableAttributes()): o
 * CICLO DE VIDA de um filho que é registro do PACIENTE e atravessa
 * prontuários — hoje só o procedimento: pedido num atendimento, executado ou
 * cancelado em outro, quase sempre com o de origem já assinado — segue
 * possível mesmo com o prontuário de origem travado, mas SÓ nas colunas
 * listadas (status/execução). Qualquer outra coluna, criação ou exclusão
 * continua recusada. Essa escrita não sobe clinical_revision: a revisão de um
 * prontuário assinado está congelada, e o que ela protege (o conjunto que o
 * médico revisou antes de assinar) não mudou.
 */
trait GuardedBySignedRecord
{
    public function save(array $options = []): bool
    {
        return $this->runGuardedBySignedRecordWrite(fn () => parent::save($options), updatesExistingRow: $this->exists);
    }

    public function delete(): ?bool
    {
        return $this->runGuardedBySignedRecordWrite(fn () => parent::delete(), updatesExistingRow: false);
    }

    /**
     * Colunas que ainda podem mudar (UPDATE de uma linha existente) com o
     * prontuário dono assinado. Vazio por padrão: nenhuma escrita passa.
     *
     * @return list<string>
     */
    protected function signedRecordWritableAttributes(): array
    {
        return [];
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     *
     * @throws LockedMedicalRecordException
     */
    private function runGuardedBySignedRecordWrite(callable $write, bool $updatesExistingRow): mixed
    {
        $recordId = $this->medical_record_id ?? $this->getOriginal('medical_record_id');

        // saveQuietly()/deleteQuietly() (Model::withoutEvents()) são o canal
        // JÁ usado de propósito pra bookkeeping pós-assinatura que não é
        // conteúdo clínico — ex.: EyeImageReportResendController gravando
        // requesting_doctor_sent_at num documento de prontuário ASSINADO (o
        // envio ao médico solicitante só existe com o prontuário assinado).
        // O guard antigo (via eventos creating/updating/deleting)
        // já nunca disparava nesse caminho — withoutEvents() desliga os
        // eventos do model inteiro, não só o Auditable. Preservar o mesmo
        // comportamento aqui: só quem dispara evento (escrita de conteúdo
        // clínico de verdade) passa pelo lock.
        if ($recordId === null || static::getEventDispatcher() instanceof NullDispatcher) {
            return $write();
        }

        return $this->getConnection()->transaction(function () use ($write, $recordId, $updatesExistingRow) {
            if (MedicalRecord::lockAndCheckSigned($recordId)) {
                // Ciclo de vida do filho (ver doc da trait): passa, sem bump.
                if ($updatesExistingRow && $this->changesOnlySignedRecordWritableAttributes()) {
                    return $write();
                }

                throw new LockedMedicalRecordException(__('actions.medical_records.documentation_locked'));
            }

            $result = $write();

            // save()/delete() devolvem false/null quando nada foi escrito
            // (evento cancelou, modelo inexistente) — sem escrita, sem bump.
            if ($result !== false && $result !== null) {
                $this->bumpClinicalRevision($recordId);
            }

            return $result;
        });
    }

    /**
     * Tudo que está pendente de gravação cabe na lista de colunas liberadas
     * pro ciclo de vida? (Lista vazia = model sem essa exceção.).
     */
    private function changesOnlySignedRecordWritableAttributes(): bool
    {
        $writable = $this->signedRecordWritableAttributes();

        return $writable !== [] && array_diff(array_keys($this->getDirty()), $writable) === [];
    }

    /**
     * Incrementa clinical_revision DIRETO no banco (query builder): sem eventos
     * do prontuário (nem o updating que também incrementa, nem Versionable,
     * Auditable ou o updated_at) — o filho já tem a própria trilha —, e
     * relativo ao valor GRAVADO (`revisão + 1`), nunca a uma instância
     * carregada que pode estar defasada.
     */
    private function bumpClinicalRevision(mixed $recordId): void
    {
        MedicalRecord::query()
            ->withoutGlobalScopes()
            ->whereKey($recordId)
            ->toBase()
            ->increment('clinical_revision');
    }
}
