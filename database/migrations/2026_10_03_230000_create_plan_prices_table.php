<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Preço de cada ciclo de cobrança que o plano oferece (mensal, trimestral,
 * semestral, anual). O cliente escolhe o ciclo no site e no cadastro; o
 * `plans.price` + `plans.billing_cycle` continuam como o preço de referência
 * (ciclo padrão) para quem ainda lê o plano direto. Vitalício não é vendido.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('billing_cycle', 20);
            $table->decimal('price', 10, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'billing_cycle']);
        });

        // Os planos existentes continuam oferecendo exatamente o ciclo e o
        // preço de hoje — nada muda no site até o admin cadastrar outro ciclo.
        $now = now();

        DB::table('plans')
            ->whereIn('billing_cycle', ['monthly', 'quarterly', 'semiannual', 'yearly'])
            ->orderBy('id')
            ->get(['id', 'billing_cycle', 'price'])
            ->each(function (object $plan) use ($now): void {
                DB::table('plan_prices')->insert([
                    'id'            => (string) Str::uuid(),
                    'plan_id'       => $plan->id,
                    'billing_cycle' => $plan->billing_cycle,
                    'price'         => $plan->price,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
