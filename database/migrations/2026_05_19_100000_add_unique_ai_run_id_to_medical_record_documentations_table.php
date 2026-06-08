<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration {
    public function up(): void
    {
        // Dedupe defensivo: em cenários antigos de corrida pode haver mais de uma
        // documentação com o mesmo ai_run_id. Mantemos a primeira e limpamos as
        // demais para permitir o índice único sem perda de documento.
        $duplicates = DB::table('medical_record_documentations')
            ->select('ai_run_id')
            ->whereNotNull('ai_run_id')
            ->groupBy('ai_run_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ai_run_id');

        foreach ($duplicates as $aiRunId) {
            $ids = DB::table('medical_record_documentations')
                ->where('ai_run_id', $aiRunId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            $keepId = $ids->shift();

            if (! $keepId || $ids->isEmpty()) {
                continue;
            }

            DB::table('medical_record_documentations')
                ->whereIn('id', $ids->all())
                ->update(['ai_run_id' => null]);
        }

        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->unique('ai_run_id', 'mrd_ai_run_unique');
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->dropUnique('mrd_ai_run_unique');
        });
    }
};
