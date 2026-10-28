<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration {
    public function up(): void
    {
        if (DB::table('patient_exams')->select('patient_id', 'code')->whereNotNull('code')->groupBy('patient_id', 'code')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('EXM scoped duplicates require explicit historical reconciliation before P2 migration.');
        }
        Schema::table('patient_exams', fn (Blueprint $t) => $t->unique(['patient_id', 'code'], 'patient_exams_patient_code_unique'));
        // Historical support telemetry may contain clinical references/free text.
        // Counters and the aggregate trend remain intact; raw problem payload is discarded.
        DB::table('integrator_queue_health')->update(['problems' => '[]']);

        DB::table('entity_activations')->where('step', 'integrator_connected')->update(['step' => 'integrator_registered']);
        Schema::table('entity_integrators', function (Blueprint $t) {
            $t->string('token_profile', 32)->default('capture');
            $t->string('update_channel', 16)->default('stable');
            $t->string('update_cohort', 64)->default('all');
        });

        foreach (['data_access_logs', 'audit_logs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('actor_type', 32)->nullable();
                $t->uuid('entity_user_integrator_id')->nullable()->index();
                $t->uuid('integrator_id')->nullable()->index();
                $t->unsignedBigInteger('token_id')->nullable();
                $t->uuid('installation_id')->nullable();
            });
        }
        Schema::table('data_access_logs', fn (Blueprint $t) => $t->json('access_summary')->nullable());
        Schema::table('integrator_commands', function (Blueprint $t) {
            $t->timestampTz('expires_at')->nullable()->index();
            $t->uuid('requested_by')->nullable();
        });
        Schema::table('entity_integrator_equipments', fn (Blueprint $t) => $t->unsignedBigInteger('config_generation')->default(1));
        Schema::create('integrator_equipment_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('integrator_id')->index();
            $t->uuid('actor_id');
            $t->uuid('installation_id');
            $t->uuid('operation_id');
            $t->string('fingerprint', 64);
            $t->text('response');
            $t->unsignedSmallInteger('status');
            $t->timestampsTz();
            $t->unique(['integrator_id', 'operation_id']);
        });
        Schema::table('integrator_updates', function (Blueprint $t) {
            $t->dropUnique(['platform', 'arch', 'version']);
            $t->uuid('release_id')->nullable()->unique();
            $t->unsignedBigInteger('sequence')->nullable();
            $t->json('metadata')->nullable();
            $t->string('manifest_signature', 128)->nullable();
            $t->string('channel', 16)->default('stable');
            $t->string('cohort', 64)->default('all');
            $t->unique(['platform', 'arch', 'version', 'channel', 'cohort'], 'integrator_update_target_cohort_unique');
        });
        Schema::table('patient_exams', function (Blueprint $t) {
            $t->string('derivative_status', 16)->default('pending');
            $t->string('derivative_error_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('integrator_updates')->select('platform', 'arch', 'version')->groupBy('platform', 'arch', 'version')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('P2 rollback reconciliation required: promoted same-version cohorts must be reconciled explicitly before restoring legacy uniqueness. No data changed.');
        }
        Schema::table('patient_exams', fn (Blueprint $t) => $t->dropUnique('patient_exams_patient_code_unique'));

        Schema::dropIfExists('integrator_equipment_operations');
        Schema::table('entity_integrators', fn (Blueprint $t) => $t->dropColumn(['token_profile', 'update_channel', 'update_cohort']));

        foreach (['data_access_logs', 'audit_logs'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['actor_type', 'entity_user_integrator_id', 'integrator_id', 'token_id', 'installation_id']));
        }
        Schema::table('data_access_logs', fn (Blueprint $t) => $t->dropColumn('access_summary'));
        Schema::table('integrator_commands', fn (Blueprint $t) => $t->dropColumn(['expires_at', 'requested_by']));
        Schema::table('entity_integrator_equipments', fn (Blueprint $t) => $t->dropColumn('config_generation'));
        Schema::table('integrator_updates', function (Blueprint $t) {
            $t->dropUnique('integrator_update_target_cohort_unique');
            $t->dropColumn(['release_id', 'sequence', 'metadata', 'manifest_signature', 'channel', 'cohort']);
            $t->unique(['platform', 'arch', 'version']);
        });
        Schema::table('patient_exams', fn (Blueprint $t) => $t->dropColumn(['derivative_status', 'derivative_error_code']));
    }
};
