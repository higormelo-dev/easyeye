<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads trazidos por parceiros/revendedores.
 * Rastreia o funil: lead gerado → contactado → em trial → convertido.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('partner_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();

            // Clínica criada a partir deste lead (preenchido quando entity é criada)
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();

            // Dados do lead coletados pelo parceiro
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 2)->nullable();

            $table->string('status'); // PartnerLeadStatus enum
            $table->text('notes')->nullable();

            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_leads');
    }
};
