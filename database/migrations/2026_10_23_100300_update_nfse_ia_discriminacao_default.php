<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * A nota do pacote de IA deixa de falar em "créditos" (o pacote é vendido em reais): o modelo padrão passa a usar {valor}.
 * Só troca quando a linha ainda é EXATAMENTE o padrão antigo — modelo customizado no manager nunca é sobrescrito (revise-o:
 * {creditos} não é mais preenchido e some do texto).
 */
return new class() extends Migration {
    private const OLD = 'Pacote de créditos de IA ({creditos} créditos) — fatura {fatura}';

    private const NEW = 'Pacote de saldo de IA ({valor}) — fatura {fatura}';

    public function up(): void
    {
        $updated = DB::table('system_settings')
            ->where('key', 'nfse.discriminacao.ia')
            ->where('value', self::OLD)
            ->update(['value' => self::NEW]);

        if ($updated > 0) {
            Cache::forget('subscription_setting:nfse.discriminacao.ia');
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'nfse.discriminacao.ia')
            ->where('value', self::NEW)
            ->update(['value' => self::OLD]);

        Cache::forget('subscription_setting:nfse.discriminacao.ia');
    }
};
