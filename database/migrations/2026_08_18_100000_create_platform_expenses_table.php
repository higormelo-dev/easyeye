<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Despesa OPERACIONAL do próprio EasyEye (não da clínica) — lançamento
        // manual pelo dono/admin do SaaS (servidor, folha, marketing, imposto,
        // outros). Sem entity_id/BelongsToEntity de propósito: é dado da
        // plataforma como um todo, não de um tenant — ver EntityGate::
        // SaasOwnerFinancial (só admin/owner do SaaS acessa este módulo).
        Schema::create('platform_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('category', 30);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            // Data em que a despesa OCORREU (fatura/competência) — não
            // necessariamente a data do lançamento (created_at). Lançamentos
            // retroativos (ex.: fatura do mês passado registrada hoje) devem
            // aparecer no P&L do período certo.
            $table->date('effective_at');
            // Despesa recorrente (assinatura de servidor, salário fixo) vs.
            // pontual (campanha específica) — informativo pra UI/IA, não
            // afeta o cálculo (soma sempre por effective_at no período).
            $table->boolean('recurring')->default(false);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['category', 'effective_at']);
            $table->index('effective_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_expenses');
    }
};
