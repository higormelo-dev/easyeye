<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das importações da CID-10 (DATASUS) feitas pela tela Manager →
 * CID-10: quem enviou, quais arquivos, quando e o que mudou (lidos, novos,
 * descrições corrigidas, erros) — carga que altera o catálogo de todas as
 * clínicas.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('cid10_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('phase', 20)->nullable();
            // Pasta (disco padrão) com os CSVs já validados e em UTF-8.
            $table->string('folder');
            $table->string('original_name', 1000);
            // [{name, kind, converted}] — arquivos reconhecidos no envio.
            $table->json('files');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('corrected_count')->default(0);
            $table->unsignedInteger('official_updated_count')->default(0);
            $table->unsignedInteger('kept_edited_count')->default(0);
            $table->unsignedInteger('skipped_invalid')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cid10_imports');
    }
};
