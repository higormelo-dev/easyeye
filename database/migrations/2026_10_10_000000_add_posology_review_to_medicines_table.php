<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Origem e revisão da posologia sugerida do catálogo global de medicamentos
 * (Manager → Medicamentos → "Gerar posologia com IA (lote)").
 *
 * - posology_source: manual (digitada/revisada pelo admin) | ai (gerada em
 *   lote, ainda NÃO revisada) | null (sem posologia).
 * - posology_ai_generated_at / posology_ai_batch_id: quando e em qual lote a
 *   IA gerou (fica como histórico mesmo depois da revisão).
 * - posology_reviewed_at / posology_reviewed_by: quem revisou pelo modal de
 *   edição (a posologia volta a ser "manual").
 *
 * Itens que já têm posologia viram "manual" (foram digitados pelo admin ou
 * pelos seeders curados).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('posology_source', 10)->nullable()->after('instructions');
            $table->timestamp('posology_ai_generated_at')->nullable()->after('posology_source');
            $table->uuid('posology_ai_batch_id')->nullable()->after('posology_ai_generated_at');
            $table->timestamp('posology_reviewed_at')->nullable()->after('posology_ai_batch_id');
            $table->foreignUuid('posology_reviewed_by')->nullable()->after('posology_reviewed_at')
                ->constrained('users')->nullOnDelete();

            // Filtro "Posologia: gerada por IA não revisada" na tela do manager.
            $table->index('posology_source');
        });

        DB::table('medicines')
            ->where(function ($query) {
                foreach (['dosage', 'frequency', 'duration', 'instructions'] as $field) {
                    $query->orWhereRaw("COALESCE(TRIM({$field}), '') <> ''");
                }
            })
            ->update(['posology_source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropIndex(['posology_source']);
            $table->dropConstrainedForeignId('posology_reviewed_by');
            $table->dropColumn(['posology_source', 'posology_ai_generated_at', 'posology_ai_batch_id', 'posology_reviewed_at']);
        });
    }
};
