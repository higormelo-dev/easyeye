<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planos de saúde (produtos registrados na ANS) de cada convênio.
 *
 * - entity_id nulo: catálogo global — planos sincronizados com os dados
 *   abertos da ANS (Características dos Produtos da Saúde Suplementar) ou
 *   cadastrados no manager.
 * - entity_id preenchido: plano próprio da clínica (convênio próprio ou
 *   plano que ainda não está no catálogo).
 *
 * Só dados do produto — nada de beneficiário.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('covenant_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('covenant_id')->constrained('covenants')->cascadeOnDelete();

            $table->string('code')->nullable();
            $table->string('name');

            // Dados oficiais do produto (ANS).
            $table->string('ans_code', 30)->nullable();            // CD_PLANO: nº de registro do produto
            $table->unsignedBigInteger('ans_plan_id')->nullable(); // ID_PLANO: chave dos dados abertos
            $table->string('contracting', 80)->nullable();
            $table->string('segmentation', 120)->nullable();
            $table->string('coverage_area', 40)->nullable();
            $table->string('accommodation', 40)->nullable();
            $table->string('moderating_factor', 40)->nullable();
            $table->char('regulation', 1)->nullable(); // A = anterior à Lei 9.656/98; P = posterior
            $table->string('ans_status', 20)->nullable(); // active | suspended | cancelled | transferred
            $table->date('ans_status_at')->nullable();
            $table->date('ans_registered_at')->nullable();

            $table->string('source', 20)->default('manual'); // manual | ans
            // Assinatura dos dados oficiais: a sincronização só grava o que mudou.
            $table->char('sync_hash', 32)->nullable();

            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique('ans_plan_id');
            // Busca do cadastro do paciente: sempre dentro de um convênio.
            $table->index(['covenant_id', 'active']);
            $table->index(['entity_id', 'covenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('covenant_plans');
    }
};
