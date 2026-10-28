<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelamento de guia/lote do faturamento (só em rascunho) com motivo: a
 * própria linha guarda quem cancelou, quando e por quê — o histórico
 * campo a campo continua no audit_logs (Auditable). Aditiva: guias e lotes já
 * cancelados antes ficam com os campos nulos.
 */
return new class() extends Migration {
    /** @var list<string> */
    private array $tables = ['billing_claims', 'billing_batches'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('cancel_reason')->nullable()->after('notes');
                $table->foreignUuid('cancelled_by')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('cancelled_by');
                $table->dropColumn(['cancel_reason', 'cancelled_at']);
            });
        }
    }
};
