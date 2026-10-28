<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Convite de retorno por WhatsApp (Fase 6, ADR 0002, item 9).
 *
 *  - `recall_invites`: a ONDA — o convite de UM paciente em um dia, que pode cobrir várias tarefas vencidas de
 *    uma vez (`recall_invite_items`). `wave_key` ("AAAA-MM-DD:paciente") com a clínica é único: duas rodadas
 *    simultâneas, ou o clique de uma pessoa junto com a rodada automática, nunca mandam dois convites no mesmo
 *    dia. A resposta do paciente (botões do template) fica aqui.
 *  - `whatsapp_messages.patient_id` / `recall_invite_id`: a mensagem de recall NÃO tem agendamento; ela é achada
 *    pelo paciente (exportação LGPD, eliminação em cascata) e pela onda (uma mensagem de saída por onda).
 *  - `whatsapp_settings.recall_enabled`: a chave do MANAGER por clínica (nasce desligada: o template precisa estar
 *    aprovado na Meta antes de ligar). A linha global do EasyEye nunca a liga.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->boolean('recall_enabled')->default(false);
        });

        Schema::create('recall_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            // "AAAA-MM-DD:<paciente>": uma onda por paciente por dia (idempotência entre rodadas e cliques).
            $table->string('wave_key', 80);
            // auto | manual
            $table->string('origin', 10);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            // wants_to_schedule | already_scheduled — botão que o paciente tocou.
            $table->string('response', 20)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'wave_key']);
            $table->index(['entity_id', 'patient_id', 'created_at']);
        });

        DB::statement("ALTER TABLE recall_invites ADD CONSTRAINT recall_invites_origin_check CHECK (origin IN ('auto', 'manual'))");
        DB::statement("ALTER TABLE recall_invites ADD CONSTRAINT recall_invites_response_check CHECK (response IS NULL OR response IN ('wants_to_schedule', 'already_scheduled'))");

        Schema::create('recall_invite_items', function (Blueprint $table) {
            $table->foreignUuid('recall_invite_id')->constrained('recall_invites')->cascadeOnDelete();
            $table->foreignUuid('patient_recall_id')->constrained('patient_recalls')->cascadeOnDelete();

            $table->primary(['recall_invite_id', 'patient_recall_id']);
            $table->index('patient_recall_id');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('recall_invite_id')->nullable()->constrained('recall_invites')->cascadeOnDelete();
        });

        // Uma mensagem de saída por onda; o webhook e o job nunca criam a 2ª.
        DB::statement("CREATE UNIQUE INDEX whatsapp_messages_recall_invite_once ON whatsapp_messages (recall_invite_id) WHERE direction = 'out' AND recall_invite_id IS NOT NULL");
        DB::statement('CREATE INDEX whatsapp_messages_patient_idx ON whatsapp_messages (patient_id) WHERE patient_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS whatsapp_messages_patient_idx');
        DB::statement('DROP INDEX IF EXISTS whatsapp_messages_recall_invite_once');

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recall_invite_id');
            $table->dropConstrainedForeignId('patient_id');
        });

        Schema::dropIfExists('recall_invite_items');
        Schema::dropIfExists('recall_invites');

        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn('recall_enabled');
        });
    }
};
