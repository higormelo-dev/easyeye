<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('report_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')
                ->nullable()
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('title');
            $table->string('paper_size', 10)->default('A4');    // A4, A5, Letter
            $table->string('font_family', 50)->default('Arial');
            $table->unsignedTinyInteger('font_size')->default(12);
            $table->decimal('margin_top', 4, 2)->default(2.0);
            $table->decimal('margin_right', 4, 2)->default(1.5);
            $table->decimal('margin_bottom', 4, 2)->default(2.0);
            $table->decimal('margin_left', 4, 2)->default(2.5);
            // Cabeçalho do paciente — posição e estilo
            $table->unsignedTinyInteger('patient_font_size')->default(14);
            $table->string('patient_alignment', 10)->default('left');
            $table->string('patient_color', 7)->default('#000000');
            // Assinatura eletrônica
            $table->boolean('show_signature')->default(true);
            $table->decimal('signature_bottom', 4, 2)->default(2.0);
            $table->string('signature_alignment', 10)->default('center');
            // Logo da clínica no cabeçalho
            $table->boolean('show_logo')->default(true);
            // Rodapé fixo (CRM, endereço da clínica, etc.)
            $table->boolean('show_footer')->default(true);
            $table->text('footer_text')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_settings');
    }
};
