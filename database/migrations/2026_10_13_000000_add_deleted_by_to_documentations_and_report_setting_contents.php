<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MedicalRecordDocumentation e ReportSettingContent usam SoftDeletes +
 * HasAuditColumns, que grava `deleted_by` no evento `deleting` — mas as duas
 * tabelas nasceram sem a coluna: toda exclusão lógica estourava SQLSTATE 42703
 * (500). Mesmo padrão das demais tabelas auditadas: uuid nullable, FK users,
 * nullOnDelete. Reversível.
 */
return new class() extends Migration {
    /** @var list<string> */
    private array $tables = ['medical_record_documentations', 'report_setting_contents'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'deleted_by')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'deleted_by')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('deleted_by');
            });
        }
    }
};
