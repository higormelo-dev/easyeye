<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O formulário da agenda tinha "Tipo de consulta" (catálogo visit_types) e
 * "Tipo de atendimento" (enum ScheduleAttendanceType) com as mesmas opções;
 * o segundo saiu da tela. Agendamento que só tinha o tipo de atendimento
 * ganha o tipo de consulta global equivalente, para a informação continuar
 * visível e entrar em faturamento/repasse. Só preenche visit_id nulo — não
 * sobrescreve escolha existente nem apaga attendance_type.
 */
return new class() extends Migration {
    private const MAP = [
        1 => 'CONSULTA',
        2 => 'RETORNO',
        3 => 'URGÊNCIA',
        4 => 'AVALIAÇÃO PRÉ-OPERATÓRIA',
        5 => 'AVALIAÇÃO PÓS-OPERATÓRIA',
        6 => 'SEGUNDA OPINIÃO',
        7 => 'TELECONSULTA',
    ];

    public function up(): void
    {
        foreach (self::MAP as $attendanceType => $name) {
            $visitId = DB::table('visit_types')
                ->whereNull('entity_id')
                ->whereNull('deleted_at')
                ->where('name', $name)
                ->value('id');

            if (! $visitId) {
                continue;
            }

            DB::table('schedules')
                ->whereNull('visit_id')
                ->where('attendance_type', $attendanceType)
                ->update(['visit_id' => $visitId]);
        }
    }

    public function down(): void
    {
        // Sem volta: não dá pra distinguir visit_id preenchido aqui de um
        // escolhido depois pelo usuário.
    }
};
