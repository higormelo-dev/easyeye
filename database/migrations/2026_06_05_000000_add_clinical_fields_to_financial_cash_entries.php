<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('financial_cash_entries', function (Blueprint $table) {
            $table->foreignUuid('patient_id')->nullable()->after('covenant_id')->constrained('patients')->nullOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->after('patient_id')->constrained('doctors')->nullOnDelete();
            $table->foreignUuid('procedure_id')->nullable()->after('doctor_id')->constrained('procedures')->nullOnDelete();

            // Detalhamento de pagamento (espelha CashierBook do smart_oftal)
            $table->decimal('amount_cash', 12, 2)->nullable()->after('amount');
            $table->decimal('amount_credit', 12, 2)->nullable()->after('amount_cash');
            $table->decimal('amount_debit', 12, 2)->nullable()->after('amount_credit');
            $table->unsignedSmallInteger('installments')->nullable()->after('amount_debit');

            $table->index(['reference_type', 'reference_id'], 'financial_cash_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('financial_cash_entries', function (Blueprint $table) {
            $table->dropIndex('financial_cash_reference_idx');
            $table->dropConstrainedForeignId('patient_id');
            $table->dropConstrainedForeignId('doctor_id');
            $table->dropConstrainedForeignId('procedure_id');
            $table->dropColumn(['amount_cash', 'amount_credit', 'amount_debit', 'installments']);
        });
    }
};
