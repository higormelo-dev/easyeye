<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        // Entidades e usuários
        'entities',
        'users',
        'entity_users',
        'entity_integrators',
        'entity_integrator_equipments',
        'entity_user_integrators',

        // Clínico — registros principais
        'people',
        'patients',
        'doctors',
        'schedules',
        'schedule_situation_logs',
        'medical_records',
        'patient_exams',

        // Clínico — tabelas de lookup
        'covenants',
        'visit_types',
        'skin_types',
        'iris_types',
        'cover_test_types',
        'color_vision_types',
        'visual_acuity_types',
        'near_point_convergences',
        'addition_types',
        'lenses',
        'exam_types',
        'surgery_types',
        'procedures',

        // Assinaturas e planos
        'plans',
        'plan_features',
        'subscriptions',
        'feature_usages',

        // Outros
        'tv_displays',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignUuid('created_by')->nullable()->after('id')
                    ->constrained('users')->nullOnDelete();
                $table->foreignUuid('updated_by')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
                $table->foreignUuid('deleted_by')->nullable()->after('updated_by')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeignIdFor(\App\Models\User::class, 'created_by');
                $table->dropForeignIdFor(\App\Models\User::class, 'updated_by');
                $table->dropForeignIdFor(\App\Models\User::class, 'deleted_by');
                $table->dropColumn(['created_by', 'updated_by', 'deleted_by']);
            });
        }
    }
};
