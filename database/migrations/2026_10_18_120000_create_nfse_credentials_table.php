<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciais da Focus NFe (NFS-e do SaaS), uma linha por ambiente
 * (homologacao | producao). Só a dona do SaaS emite nota, então a credencial é
 * global — sem entity_id.
 *
 * token / master_token / webhook_token / webhook_secret são CIFRADOS pelo model
 * (casts `encrypted`) e nunca saem em JSON, log nem auditoria:
 *  - token: token da empresa (emite, consulta e cancela);
 *  - master_token: token master, só para as consultas acessórias
 *    (GET /v2/municipios*);
 *  - webhook_token: vai no caminho da URL de callback (roteia, não autentica);
 *  - webhook_secret: valor esperado no cabeçalho do callback (autentica).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('nfse_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('environment', 20)->unique();
            $table->text('token')->nullable();
            $table->text('master_token')->nullable();
            $table->text('webhook_token');
            $table->text('webhook_secret');
            // Hook registrado na Focus (POST /v2/hooks) e o evento usado no registro
            // (persistido porque o padrão configurado pode mudar depois).
            $table->string('hook_id', 64)->nullable();
            $table->string('hook_event', 20)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_credentials');
    }
};
