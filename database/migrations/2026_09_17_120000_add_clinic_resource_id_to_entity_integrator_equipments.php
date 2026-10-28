<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo opcional entre um equipamento do integrador e um recurso de
 * agenda (`clinic_resources`, tipo 'equipment') — permite que a Modality
 * Worklist do integrador (`GET /schedules?clinic_resource_id=...`) peça só
 * os agendamentos reservados para ESTE equipamento em vez da agenda ativa
 * inteira do dia. `NULL` (o padrão) preserva o comportamento de sempre.
 *
 * Aditivo, sem efeito em nenhuma clínica hoje: `clinic_resources` não tem
 * nenhuma linha em uso ainda (recurso de agenda nunca chegou a ser
 * adotado por nenhuma clínica real), então este campo fica inerte até a
 * tela de agendamento passar a exigir/oferecer escolha de recurso — decisão
 * de produto separada, fora desta mudança.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entity_integrator_equipments', function (Blueprint $table) {
            $table->foreignUuid('clinic_resource_id')
                ->nullable()
                ->after('serial_number')
                ->constrained('clinic_resources')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entity_integrator_equipments', function (Blueprint $table) {
            $table->dropForeign(['clinic_resource_id']);
            $table->dropColumn('clinic_resource_id');
        });
    }
};
