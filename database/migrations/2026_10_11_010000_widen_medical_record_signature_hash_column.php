<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * signature_hash era varchar(64) — cabia só o sha256 hex puro (64 chars).
 * O esquema de versionamento do hash (Signable::SIGNATURE_HASH_VERSION,
 * achado via auditoria de encerramento EE-03) grava "N:" + 64 hex (66+
 * chars) e truncava no banco (erro 22001 do PostgreSQL). 80 cobre versões
 * de 1 a 2 dígitos com folga; valores existentes (sem prefixo) continuam
 * cabendo sem qualquer rewrite.
 */
return new class() extends Migration {
    public function up(): void
    {
        // Sem ->unique() aqui: a constraint já existe (migration original) e
        // change() tenta CRIAR de novo o que for declarado — repetir unique()
        // colide com "medical_records_signature_hash_unique" já existente.
        // change() só altera o tipo/tamanho da coluna; a constraint physical
        // não é tocada por quem não a menciona.
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('signature_hash', 80)->nullable()->change();
        });
    }

    /**
     * Não estreita a coluna de volta para 64 — de propósito. Desde o deploy o
     * Signable grava "N:" + sha256 (66 chars) em todo prontuário que se assina,
     * e o PostgreSQL recusa ALTER ... TYPE varchar(64) assim que existe uma
     * dessas assinaturas (SQLSTATE 22001): o rollback quebrava depois do uso
     * normal e deixava as migrations mais novas já revertidas (rollback pela
     * metade). Truncar ou apagar assinatura pra caber fere a integridade do
     * prontuário assinado (CFM Res. 2.227/2018). A coluna larga comporta os
     * valores antigos de 64 chars, então manter 80 não custa nada ao código
     * anterior (achado via revisão adversarial Codex).
     */
    public function down(): void
    {
        // Intencionalmente vazio.
    }
};
