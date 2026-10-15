<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M0 — onde se opera.
 *
 *  - `surgery_facilities`: clínica própria, hospital ou centro cirúrgico, com
 *    CNES e o código do local na operadora (vão para a solicitação de
 *    internação TISS).
 *  - `surgery_rooms`: sala de um local. Sala da própria clínica pode apontar
 *    para um `clinic_resources` (reaproveita escala semanal e bloqueios);
 *    sala de hospital existe só para a cirurgia.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('surgery_facilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->string('cnes', 7)->nullable();
            $table->string('operator_code', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('zip_code', 9)->nullable();
            $table->string('address', 150)->nullable();
            $table->string('address_number', 20)->nullable();
            $table->string('district', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entity_id', 'active'], 'surgery_facilities_entity_active_idx');
        });

        Schema::create('surgery_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgery_facility_id')->constrained('surgery_facilities')->cascadeOnDelete();
            $table->foreignUuid('clinic_resource_id')->nullable()->constrained('clinic_resources')->nullOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entity_id', 'surgery_facility_id'], 'surgery_rooms_entity_facility_idx');
        });

        // Nome de sala único por local (ignorando maiúsculas e salas apagadas).
        DB::statement('
            CREATE UNIQUE INDEX surgery_rooms_facility_name_unique
                ON surgery_rooms (surgery_facility_id, lower(name))
             WHERE deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_rooms');
        Schema::dropIfExists('surgery_facilities');
    }
};
