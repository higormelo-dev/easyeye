<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo cirúrgico (Fase 4) / M3a — aviso neutro de autorização por WhatsApp.
 *
 *  - `surgery_clinic_settings`: configuração da clínica para o módulo (hoje só
 *    o kill-switch do aviso; DESLIGADO de fábrica).
 *  - `surgery_authorization_notices`: livro de avisos por evento de
 *    autorização. A chave única por evento garante "no máximo uma mensagem por
 *    evento", mesmo com reentrega do ouvinte. Não guarda texto nem dado do
 *    paciente — só o desfecho (enfileirado/pulado + motivo) e o vínculo com a
 *    linha de whatsapp_messages.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('surgery_clinic_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->unique()->constrained('entities')->cascadeOnDelete();
            $table->boolean('authorization_notice_enabled')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('surgery_authorization_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('surgical_authorization_event_id')->unique('surgery_auth_notices_event_unique')
                ->constrained('surgical_authorization_events')->cascadeOnDelete();
            $table->string('status', 12);
            $table->string('skip_reason', 40)->nullable();
            $table->foreignUuid('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_id', 'status'], 'surgery_auth_notices_entity_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_authorization_notices');
        Schema::dropIfExists('surgery_clinic_settings');
    }
};
