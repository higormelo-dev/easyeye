<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Convite de clínica para médico que JÁ tem login no EasyEye (atende em outra
 * clínica). A clínica nunca vincula o login de ninguém: ela convida, o convite
 * vai para o e-mail do login existente e só o próprio médico, logado, aceita.
 *
 * payload = dados que a clínica digitou (dados pessoais + CRM etc.), gravados
 * CRIPTOGRAFADOS (cast encrypted:array), fora da auditoria, e apagados ao
 * aceitar/recusar/cancelar/expirar (clinic-invitations:expire, diário) —
 * viram o cadastro PRÓPRIO desta clínica; o de outras clínicas não é lido.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('payload')->nullable();
            $table->string('status', 20)->default('pending')
                ->comment('pending | accepted | declined | cancelled | expired');
            $table->timestamp('expires_at');
            // Último e-mail enviado ao médico (reenvio com intervalo mínimo).
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'status']);
            $table->index('user_id');
        });

        // Um convite PENDENTE por (clínica, médico): reenviar atualiza o mesmo.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX doctor_invitations_pending_unique ON doctor_invitations (entity_id, user_id) WHERE status = 'pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_invitations');
    }
};
