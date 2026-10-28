<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('integrator_api_receipts', function (Blueprint $t) {
            $t->char('scope_key', 64)->primary();
            $t->char('fingerprint', 64);
            $t->uuid('integrator_id');
            $t->uuid('capture_id')->nullable();
            $t->index(['integrator_id', 'capture_id'], 'integrator_receipts_capture_index');
            $t->unsignedSmallInteger('status')->nullable();
            $t->text('response')->nullable();
            $t->string('content_type')->nullable();
            $t->timestamps();
        });
        Schema::create('integrator_exam_outbox', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('patient_exam_id');
            $t->string('operation', 32);
            $t->text('archive');
            $t->timestamps();
        });
        Schema::table('patient_exams', function (Blueprint $t) {
            $t->uuid('capture_id')->nullable();
            $t->uuid('capture_integrator_id')->nullable();
            $t->char('content_sha256', 64)->nullable();
            $t->unsignedBigInteger('content_bytes')->nullable();
            $t->unique(['capture_integrator_id', 'capture_id'], 'patient_exams_capture_unique');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $t) {
            $t->dropUnique('patient_exams_capture_unique');
            $t->dropColumn(['capture_id', 'capture_integrator_id', 'content_sha256', 'content_bytes']);
        });
        Schema::dropIfExists('integrator_exam_outbox');
        Schema::dropIfExists('integrator_api_receipts');
    }
};
