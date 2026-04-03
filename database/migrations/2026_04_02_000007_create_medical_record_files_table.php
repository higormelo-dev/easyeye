<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('medical_record_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medical_record_id')
                ->constrained('medical_records')
                ->cascadeOnDelete();
            $table->foreignUuid('patient_id')
                ->constrained('patients')
                ->cascadeOnDelete();
            // Arquivo armazenado em storage/app/private/medical/{entity_id}/{record_id}/
            $table->text('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();  // bytes
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_files');
    }
};
