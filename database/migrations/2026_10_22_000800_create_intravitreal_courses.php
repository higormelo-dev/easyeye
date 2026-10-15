<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Curso de injeções intravítreas (anti-VEGF) — Fase 6, M3 (ADR 0002, item 10).
 *
 *  - `procedures.clinical_kind`: marca o procedimento do catálogo que é injeção intravítrea; é o que liga a execução
 *    do procedimento (`markDone`) ao curso, sem nenhuma coluna nova em `medical_record_procedures` (prontuário
 *    assinado é imutável: o ciclo de vida do procedimento já é a única exceção).
 *  - `intravitreal_courses`: o tratamento de UM olho de um paciente (cada olho é um curso): droga, esquema, intervalo
 *    decidido pelo médico a cada visita ("trate e estenda"), próxima aplicação e situação. Um curso ativo/pausado por
 *    paciente e olho.
 *  - `intravitreal_applications`: cada aplicação (planejada → executada): lote e validade, autorização do convênio
 *    POR aplicação e o intervalo decidido ao final dela. Ligada ao procedimento do prontuário e ao agendamento.
 *  - `patient_recalls.source = 'course'`: a próxima aplicação do curso entra na lista de Retornos como o retorno que o
 *    médico decidiu (não depende de protocolo validado) — o paciente de anti-VEGF que some é o maior risco do tratamento.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->string('clinical_kind', 40)->nullable()->after('treatment');
        });

        Schema::create('intravitreal_courses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            // right | left — nunca "ambos": cada olho tem o seu curso.
            $table->string('eye', 5);
            // Chave da droga (config recall.intravitreal.drugs) e o rótulo gravado (o catálogo muda, o registro não).
            $table->string('drug_key', 40);
            $table->string('drug_label', 120);
            // Texto curto livre (ex.: "DMRI neovascular"); sem dado além do necessário.
            $table->string('indication', 120)->nullable();
            // loading_tae (ataque e depois trate e estenda) | prn | fixed
            $table->string('regimen', 20);
            $table->unsignedTinyInteger('loading_doses_planned')->default(3);
            $table->unsignedTinyInteger('min_interval_weeks')->default(4);
            $table->unsignedTinyInteger('max_interval_weeks')->default(16);
            // O intervalo que o médico decidiu na última aplicação.
            $table->unsignedTinyInteger('current_interval_weeks')->nullable();
            // active | paused | completed | discontinued | lost
            $table->string('status', 14)->default('active');
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->text('end_reason')->nullable();
            $table->date('next_due_on')->nullable();
            $table->date('last_applied_on')->nullable();
            $table->foreignUuid('responsible_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            // A série de agendamentos (aberta) das aplicações — nula até a primeira aplicação agendada.
            $table->uuid('series_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'status', 'next_due_on']);
            $table->index(['entity_id', 'patient_id']);
        });

        DB::statement("CREATE UNIQUE INDEX intravitreal_courses_one_open ON intravitreal_courses (patient_id, eye) WHERE status IN ('active', 'paused') AND deleted_at IS NULL");
        DB::statement("ALTER TABLE intravitreal_courses ADD CONSTRAINT intravitreal_courses_eye_check CHECK (eye IN ('right', 'left'))");
        DB::statement("ALTER TABLE intravitreal_courses ADD CONSTRAINT intravitreal_courses_status_check CHECK (status IN ('active', 'paused', 'completed', 'discontinued', 'lost'))");

        Schema::create('intravitreal_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('intravitreal_courses')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence_no');
            // loading | maintenance
            $table->string('phase', 12);
            // planned | performed | cancelled | missed
            $table->string('status', 10)->default('planned');
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignUuid('medical_record_procedure_id')->nullable()->constrained('medical_record_procedures')->nullOnDelete();
            $table->date('planned_for')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->foreignUuid('performed_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            // O que foi aplicado (cópia do curso na hora: o curso pode mudar de droga depois).
            $table->string('eye', 5);
            $table->string('drug_label', 120);
            // Lote e validade: texto/data gravados (rastreabilidade RDC 556/2021); `stock_lot_id` quando saiu do estoque.
            $table->string('lot_number', 60)->nullable();
            $table->date('lot_expiry')->nullable();
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            // Autorização do convênio POR aplicação.
            $table->boolean('authorization_required')->default(false);
            // not_required | pending | requested | approved | denied | expired
            $table->string('authorization_status', 12)->default('not_required');
            $table->string('authorization_number', 60)->nullable();
            $table->date('authorization_requested_on')->nullable();
            $table->date('authorization_valid_until')->nullable();
            $table->text('authorization_notes')->nullable();
            // O intervalo decidido AO FINAL desta aplicação (semanas até a próxima).
            $table->unsignedTinyInteger('decided_interval_weeks')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'sequence_no']);
            $table->index(['entity_id', 'patient_id']);
            $table->index(['entity_id', 'status', 'planned_for']);
        });

        DB::statement('CREATE UNIQUE INDEX intravitreal_applications_schedule_once ON intravitreal_applications (schedule_id) WHERE schedule_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX intravitreal_applications_procedure_once ON intravitreal_applications (medical_record_procedure_id) WHERE medical_record_procedure_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement("ALTER TABLE intravitreal_applications ADD CONSTRAINT intravitreal_applications_status_check CHECK (status IN ('planned', 'performed', 'cancelled', 'missed'))");

        // O retorno de um curso: uma linha por curso (task_key = "course:<id>").
        DB::statement('ALTER TABLE patient_recalls DROP CONSTRAINT IF EXISTS patient_recalls_source_check');
        DB::statement("ALTER TABLE patient_recalls ADD CONSTRAINT patient_recalls_source_check CHECK (source IN ('protocol', 'doctor_order', 'course'))");
        DB::statement("CREATE UNIQUE INDEX patient_recalls_course_unique ON patient_recalls (entity_id, task_key) WHERE source = 'course'");

        // Catálogo global: a injeção intravítrea (código interno — o código TUSS de faturamento é conferido com o faturamento).
        $exists = DB::table('procedures')->whereNull('entity_id')->where('clinical_kind', 'intravitreal_injection')->exists();

        if (! $exists) {
            DB::table('procedures')->insert([
                'id'            => (string) Str::uuid(),
                'entity_id'     => null,
                'code'          => 'EE-IVT',
                'name'          => 'Injeção intravítrea de antiangiogênico (por sessão)',
                'clinical_kind' => 'intravitreal_injection',
                'active'        => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS patient_recalls_course_unique');
        DB::table('patient_recalls')->where('source', 'course')->delete();
        DB::statement('ALTER TABLE patient_recalls DROP CONSTRAINT IF EXISTS patient_recalls_source_check');
        DB::statement("ALTER TABLE patient_recalls ADD CONSTRAINT patient_recalls_source_check CHECK (source IN ('protocol', 'doctor_order'))");

        Schema::dropIfExists('intravitreal_applications');
        Schema::dropIfExists('intravitreal_courses');

        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn('clinical_kind');
        });
    }
};
