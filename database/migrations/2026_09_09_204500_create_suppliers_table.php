<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fornecedor DA CLÍNICA — App\Models\Supplier. Catálogo simples (mesmo
 * padrão de Covenant/ProductCategory), sem catálogo global (fornecedor não
 * é compartilhado entre clínicas, diferente de Procedure/IolLensModel).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();

            $table->string('code');
            $table->string('name');
            $table->string('document')->nullable(); // CNPJ/CPF
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'active']);
            $table->unique(['entity_id', 'document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
