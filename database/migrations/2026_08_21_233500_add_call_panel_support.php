<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Painel de chamadas de paciente (TV da sala de espera) — opcional por
 * clínica: toggle + token público do painel na entity, e trilha de chamadas
 * emitidas pelos médicos ("Paciente João, consultório da Dra. Ana").
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->boolean('call_panel_enabled')->default(false);
            // Token da URL pública da TV (/call-panel/{token}) — aleatório,
            // não enumerável; gerado ao ativar o recurso.
            $table->string('call_panel_token', 64)->nullable()->unique();
        });

        Schema::create('patient_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            // Snapshots: painel público NUNCA consulta cadastro — só exibe o
            // que foi congelado aqui na hora da chamada.
            $table->string('patient_name');
            $table->string('doctor_name')->nullable();
            $table->foreignUuid('called_by_entity_user_id')->nullable()->constrained('entity_users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_calls');
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['call_panel_enabled', 'call_panel_token']);
        });
    }
};
