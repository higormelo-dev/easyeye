<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Catálogo de modelos/preços de IA sincronizado (Manager → Provedores de IA):
 *
 * - source: seed (padrão do sistema), manual (cadastrado no painel) ou sync
 *   (descoberto pela sincronização com a API do provedor).
 * - price_locked: preço editado à mão — a sincronização não sobrescreve.
 * - synced_at: última vez que a sincronização conferiu o preço.
 * - unlisted_at: o provedor deixou de oferecer o modelo (aviso na tela).
 *
 * Linhas já cadastradas ou editadas pelo painel (trilha de auditoria) entram
 * travadas: o preço negociado/corrigido à mão não muda sozinho.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table) {
            $table->string('source', 10)->default('seed')->after('active');
            $table->boolean('price_locked')->default(false)->after('source');
            $table->timestamp('synced_at')->nullable()->after('price_locked');
            $table->timestamp('unlisted_at')->nullable()->after('synced_at');
        });

        $edited = DB::table('audit_logs')
            ->where('auditable_type', 'ai_model_price')
            ->whereIn('event', ['manager.ai_model_prices.store', 'manager.ai_model_prices.update'])
            ->select('auditable_id', 'event')
            ->get();

        $created = $edited->where('event', 'manager.ai_model_prices.store')->pluck('auditable_id')->unique()->values();

        if ($created->isNotEmpty()) {
            DB::table('ai_model_prices')->whereIn('id', $created)->update(['source' => 'manual']);
        }

        if ($edited->isNotEmpty()) {
            DB::table('ai_model_prices')->whereIn('id', $edited->pluck('auditable_id')->unique()->values())
                ->update(['price_locked' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table) {
            $table->dropColumn(['source', 'price_locked', 'synced_at', 'unlisted_at']);
        });
    }
};
