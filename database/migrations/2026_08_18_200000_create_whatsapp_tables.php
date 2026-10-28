<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration {
    public function up(): void
    {
        // Configuração Z-API por clínica (instância própria de WhatsApp).
        // Credenciais no bag `credentials` com cast encrypted:array — mesmo
        // padrão de gateway_credentials (nunca colunas *_encrypted manuais).
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->unique()->constrained('entities')->cascadeOnDelete();
            // {instance_id, instance_token, client_token} — criptografado at rest.
            $table->text('credentials')->nullable();
            // instance_id em claro (indexável) para o webhook cruzar o payload
            // Z-API (instanceId) com a clínica. NÃO é segredo — os tokens são.
            $table->string('instance_id')->nullable()->index();
            // Token aleatório que compõe a URL do webhook desta clínica —
            // identifica o tenant sem expor id interno e sem depender só do
            // instanceId do payload (forjável por terceiro que o conheça).
            $table->string('webhook_token', 64)->unique();
            $table->boolean('active')->default(false);
            $table->boolean('confirmation_enabled')->default(true);
            $table->unsignedSmallInteger('confirmation_hours_before')->default(24);
            $table->boolean('survey_enabled')->default(true);
            $table->unsignedSmallInteger('survey_delay_hours')->default(2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Log de mensagens (saída E entrada) — trilha completa por consulta.
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->cascadeOnDelete();
            $table->string('direction', 3); // out | in
            // confirmation | survey | ack (saída) · reply (entrada)
            $table->string('kind', 20);
            $table->string('phone', 20);
            $table->text('body');
            // pending → sent → answered | failed  (saída) · received (entrada)
            $table->string('status', 12)->default('pending');
            $table->string('zapi_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('survey_score')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'phone', 'direction', 'status']);
            $table->index(['schedule_id', 'kind']);
        });

        // Idempotência sem race:
        //  - 1 confirmação e 1 pesquisa POR CONSULTA (saída) — o comando pode
        //    rodar concorrente; o unique parcial garante linha única.
        //  - webhook Z-API pode reentregar o mesmo messageId — entrada única.
        DB::statement("CREATE UNIQUE INDEX whatsapp_messages_outbound_once ON whatsapp_messages (schedule_id, kind) WHERE direction = 'out' AND kind IN ('confirmation', 'survey')");
        DB::statement("CREATE UNIQUE INDEX whatsapp_messages_inbound_once ON whatsapp_messages (zapi_message_id) WHERE direction = 'in' AND zapi_message_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_settings');
    }
};
