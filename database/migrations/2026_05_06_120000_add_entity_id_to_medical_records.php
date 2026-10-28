<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Adiciona coluna `entity_id` em `medical_records`. Multi-tenancy correto
 * exige FK direta — antes lookup era via `schedule.entity_id`, mas
 * prontuários podem nascer sem agenda (ex: paciente walk-in com admin SaaS).
 *
 * Sintomas pré-fix: códigos PMR duplicados quando record criado sem schedule
 * (boot::creating não encontrava `lastRecord` via whereHas('schedule')).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->foreignUuid('entity_id')
                ->nullable()
                ->after('schedule_id')
                ->constrained('entities')
                ->nullOnDelete();

            // Index composto p/ acelerar lookup do último code por entity.
            $table->index(['entity_id', 'code'], 'medical_records_entity_code_idx');
        });

        // Backfill: deriva entity_id de schedules quando record tem schedule_id.
        DB::statement('
            UPDATE medical_records mr
            SET entity_id = s.entity_id
            FROM schedules s
            WHERE mr.schedule_id = s.id
              AND mr.entity_id IS NULL
        ');

        // Backfill códigos duplicados/órfãos: regera com counter sequencial
        // por entity, ordenado por created_at. Records sem entity_id ficam
        // intactos (precisam intervenção manual — não há fonte de verdade).
        $entityIds = DB::table('medical_records')
            ->whereNotNull('entity_id')
            ->distinct()
            ->pluck('entity_id');

        foreach ($entityIds as $entityId) {
            $records = DB::table('medical_records')
                ->where('entity_id', $entityId)
                ->orderBy('created_at')
                ->get(['id', 'code']);

            $counter = 1;

            foreach ($records as $record) {
                $newCode = sprintf('%s-%010d', 'PMR', $counter++);

                if ($record->code !== $newCode) {
                    DB::table('medical_records')
                        ->where('id', $record->id)
                        ->update(['code' => $newCode]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropIndex('medical_records_entity_code_idx');
            $table->dropConstrainedForeignId('entity_id');
        });
    }
};
