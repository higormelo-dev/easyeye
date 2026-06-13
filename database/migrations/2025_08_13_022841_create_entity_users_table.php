<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Roles disponíveis (App\Enums\EntityUserRole — criar):
     *   owner  : criador da empresa; único por empresa; não removível
     *   admin  : gerencia usuários e configurações
     *   member : acesso operacional completo
     *   viewer : somente leitura
     */
    public function up(): void
    {
        Schema::create('entity_users', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Quem convidou (null para o owner fundador)
            $table->foreignUuid('invited_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Identificação
            $table->string('code', 30)->unique()
                ->comment('Código gerado automaticamente. Ex: EU-0000000001.');

            // Papel na empresa
            $table->string('rule', 20)->default('member')
                ->comment('owner | admin | member | viewer');

            // Flags
            $table->boolean('is_owner')->default(false)
                ->comment('true somente para o criador. Um único owner por empresa.');
            $table->boolean('active')->default(true)
                ->comment('false = acesso do usuário à empresa suspenso pelo admin.');

            // Rastreabilidade
            $table->timestamp('joined_at')->nullable()
                ->comment('Quando o usuário aceitou o convite ou foi registrado.');

            $table->softDeletes();
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────────────
            $table->unique(['entity_id', 'user_id']);

            // ── Índices ───────────────────────────────────────────────────────
            $table->index(['entity_id', 'rule']);
            $table->index(['entity_id', 'active']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_users');
    }
};
