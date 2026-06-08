<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parceiros/revendedores externos que trazem clínicas para a plataforma.
 * Canal de distribuição indireta — distribuidores de equipamentos oftalmológicos,
 * associações médicas, consultores. Principal alavanca de redução de CAC em B2B médico.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('email')->unique();
            $table->string('document', 18)->nullable(); // CNPJ ou CPF

            $table->string('type'); // PartnerType enum

            // Taxa de comissão (%) sobre o valor do plano
            $table->decimal('commission_rate', 5, 2)->default(10.00);

            // Token único para atribuição de leads (parâmetro UTM-style: ?partner=TOKEN)
            $table->string('token', 32)->unique();

            $table->string('status')->default('active'); // active | inactive | suspended

            $table->text('notes')->nullable();

            // Quem cadastrou o parceiro
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
