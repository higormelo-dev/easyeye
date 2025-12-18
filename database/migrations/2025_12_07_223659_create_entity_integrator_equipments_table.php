<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('entity_integrator_equipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integrator_id')->constrained('entity_integrators')->cascadeOnDelete();
            $table->string('name');
            $table->ipAddress('ip')->unique();
            $table->macAddress('mac')->unique();
            $table->string('serial_number')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_integrator_equipments');
    }
};
