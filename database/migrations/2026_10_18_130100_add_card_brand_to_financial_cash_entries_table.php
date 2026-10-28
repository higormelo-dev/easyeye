<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeira do cartão no lançamento de caixa (App\Enums\CardBrand). Opcional e
 * só faz sentido quando a forma de pagamento usa cartão (PaymentMethod::usesCard);
 * lançamentos anteriores ficam sem bandeira.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('financial_cash_entries', function (Blueprint $table) {
            $table->string('card_brand', 20)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('financial_cash_entries', function (Blueprint $table) {
            $table->dropColumn('card_brand');
        });
    }
};
