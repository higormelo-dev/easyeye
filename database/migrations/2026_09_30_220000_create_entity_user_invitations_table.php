<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Convite de clínica para usuário (secretária, financeiro, admin…) que já tem
 * login no EasyEye. A clínica informa e-mail + perfil; a resposta e esta
 * lista são as mesmas exista conta ou não (user_id fica null quando não há
 * conta elegível — convite inerte, ninguém aceita): nada revela quem tem
 * acesso ao EasyEye. Só o dono do login, logado, aceita.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('entity_user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->comment('E-mail digitado pela clínica (minúsculo).');
            $table->string('rule', 20)->comment('Perfil na clínica: admin | financial | secretary | user');
            $table->string('status', 20)->default('pending')
                ->comment('pending | accepted | declined | cancelled | expired');
            $table->timestamp('expires_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'status']);
            $table->index('user_id');
        });

        // Um convite PENDENTE por (clínica, e-mail): reenviar atualiza o mesmo.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX entity_user_invitations_pending_unique ON entity_user_invitations (entity_id, email) WHERE status = 'pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_user_invitations');
    }
};
