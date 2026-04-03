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
        Schema::create('medical_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()
                ->constrained('schedules')->cascadeOnDelete();
            $table->foreignUuid('visual_acuity_type_id')->nullable()
                ->constrained('visual_acuity_types')->cascadeOnDelete();
            $table->foreignUuid('near_point_convergence_id')->nullable()
                ->constrained('near_point_convergences')->cascadeOnDelete();
            $table->foreignUuid('cover_test_type_id')->nullable()
                ->constrained('cover_test_types')->cascadeOnDelete();
            $table->foreignUuid('visual_acuity_without_correction_right_id')->nullable()
                ->constrained('visual_acuity_types')->cascadeOnDelete();
            $table->foreignUuid('visual_acuity_without_correction_left_id')->nullable()
                ->constrained('visual_acuity_types')->cascadeOnDelete();
            $table->foreignUuid('visual_acuity_with_correction_right_id')->nullable()
                ->constrained('visual_acuity_types')->cascadeOnDelete();
            $table->foreignUuid('visual_acuity_with_correction_left_id')->nullable()
                ->constrained('visual_acuity_types')->cascadeOnDelete();
            $table->foreignUuid('addition_type_id')->nullable()
                ->constrained('addition_types')->cascadeOnDelete();
            $table->foreignUuid('lens_away_id')->nullable()
                ->constrained('lenses')->cascadeOnDelete();
            $table->foreignUuid('lens_near_id')->nullable()
                ->constrained('lenses')->cascadeOnDelete();
            $table->string('code');
            $table->text('main_complaint')->nullable();
            $table->boolean('diabetic')->default(false)->nullable();
            $table->boolean('diabetic_family')->default(false)->nullable();
            $table->boolean('hypertensive')->default(false)->nullable();
            $table->boolean('hypertensive_family')->default(false)->nullable();
            $table->boolean('glaucomatous')->default(false)->nullable();
            $table->boolean('glaucomatous_family')->default(false)->nullable();
            $table->integer('tonometer_right')->nullable();
            $table->integer('tonometer_left')->nullable();
            $table->time('tonometer_time')->nullable();
            $table->string('dynamic_spherical_right')->nullable();
            $table->string('dynamic_spherical_left')->nullable();
            $table->string('dynamic_cylindrical_right')->nullable();
            $table->string('dynamic_cylindrical_left')->nullable();
            $table->string('dynamic_axis_right')->nullable();
            $table->string('dynamic_axis_left')->nullable();
            $table->string('static_spherical_right')->nullable();
            $table->string('static_spherical_left')->nullable();
            $table->string('static_cylindrical_right')->nullable();
            $table->string('static_cylindrical_left')->nullable();
            $table->string('static_axis_right')->nullable();
            $table->string('static_axis_left')->nullable();
            $table->text('biomicroscopy_right')->nullable();
            $table->text('biomicroscopy_left')->nullable();
            $table->text('fundoscopy_right')->nullable();
            $table->text('fundoscopy_left')->nullable();
            $table->text('observation_general')->nullable();
            $table->text('observation_of_lenses')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};
