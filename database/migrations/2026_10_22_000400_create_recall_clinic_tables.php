<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Recall por clínica (Fase 6, ADR 0002): o que a CLÍNICA decide sobre os protocolos validados.
 *
 *  - `recall_clinic_settings`: a configuração geral (uma linha por clínica) — retorno pedido pelo médico,
 *    convite por WhatsApp (desligado até a clínica ativar), janela de envio, intervalo mínimo entre convites,
 *    teto diário com ramp-up.
 *  - `recall_clinic_protocols`: a ADOÇÃO de um protocolo — qual VERSÃO (com o hash que a clínica viu), os
 *    parâmetros ajustados DENTRO da faixa validada e quem ativou (o médico). Uma adoção ativa por protocolo.
 *  - `recall_exam_mappings`: quais tipos de exame da clínica contam como "campimetria", "OCT de mácula"...
 *    (o protocolo fala em chaves canônicas; a clínica diz quais dos seus exames são essas).
 *
 * `canonical_key` em `exam_types` sugere o mapeamento dos tipos de exame GLOBAIS (da plataforma).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('recall_clinic_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->unique()->constrained('entities')->cascadeOnDelete();
            // O retorno que o médico pediu em follow_up_days vira item da lista (não depende de protocolo validado).
            $table->boolean('doctor_order_enabled')->default(true);
            // Convite automático por WhatsApp: nasce DESLIGADO (consentimento, template aprovado na Meta, piloto).
            $table->boolean('whatsapp_invites_enabled')->default(false);
            // Janela de envio no horário da clínica ("HH:MM") e dias da semana (ISO 1=segunda … 7=domingo).
            $table->string('invite_window_start', 5)->default('09:00');
            $table->string('invite_window_end', 5)->default('18:00');
            $table->json('invite_weekdays');
            $table->unsignedSmallInteger('min_days_between_invites')->default(30);
            $table->unsignedTinyInteger('max_auto_invites_per_cycle')->default(3);
            $table->unsignedSmallInteger('daily_invite_cap')->default(20);
            // Dia em que o ramp-up começou (o teto diário sobe devagar para não queimar o número do WhatsApp).
            $table->date('ramp_started_on')->nullable();
            // Retorno vencido há mais que isto sai do convite automático (vai para a busca ativa).
            $table->unsignedSmallInteger('auto_invite_max_overdue_days')->default(180);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recall_clinic_protocols', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('protocol_id')->constrained('recall_protocols')->restrictOnDelete();
            // A versão adotada e o hash que a clínica viu: a adoção nunca troca o conteúdo por baixo dela.
            $table->foreignUuid('version_id')->constrained('recall_protocol_versions')->restrictOnDelete();
            $table->string('adopted_content_hash', 66);
            // {"tasks": {"<chave>": {"interval": 6, "enabled": true}}} — validado contra a faixa da versão.
            $table->json('parameters');
            $table->boolean('auto_contact')->default(false);
            // Quem ativou (ato clínico: o médico) e o retrato do que ele viu — nome/CRM e a prova da validação.
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('activated_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->json('activation_snapshot')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deactivation_reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_id', 'version_id']);
        });

        DB::statement('CREATE UNIQUE INDEX recall_clinic_protocols_one_active ON recall_clinic_protocols (entity_id, protocol_id) WHERE deactivated_at IS NULL');

        Schema::create('recall_exam_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            // Chave canônica do protocolo (RecallExamKey): visual_field, oct_macula...
            $table->string('exam_key', 40);
            $table->foreignUuid('exam_type_id')->constrained('exam_types')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entity_id', 'exam_key', 'exam_type_id']);
            $table->index(['entity_id', 'exam_type_id']);
        });

        Schema::table('exam_types', function (Blueprint $table) {
            // Sugestão de mapeamento dos tipos GLOBAIS (entity_id nulo) para a chave canônica do recall.
            $table->string('canonical_key', 40)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('exam_types', function (Blueprint $table) {
            $table->dropColumn('canonical_key');
        });

        Schema::dropIfExists('recall_exam_mappings');
        Schema::dropIfExists('recall_clinic_protocols');
        Schema::dropIfExists('recall_clinic_settings');
    }
};
