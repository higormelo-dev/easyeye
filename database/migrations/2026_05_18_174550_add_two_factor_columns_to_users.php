<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening Manager SaaS — 2FA (TOTP) para usuários admins do SaaS.
 *
 * Por que TOTP e não SMS/email:
 *  - SMS/email são phishable (SIM swap, email compromise).
 *  - TOTP (Google Authenticator, Authy, 1Password) é o padrão da indústria
 *    e não depende de canal externo.
 *
 * Política:
 *  - Obrigatório para qualquer rota protegida por `saas.admin`.
 *  - Usuários comuns (clínicas) podem habilitar opcionalmente, mas não
 *    sofrem enforcement (controle do admin da clínica).
 *  - Secret armazenado com cast `encrypted` (defesa em profundidade, mesmo
 *    com DB comprometido o segredo TOTP ainda exige Laravel APP_KEY).
 *  - Recovery codes: 10 códigos de uso único; hash bcrypt antes de
 *    armazenar (igual a senha — atacante com DB não recupera os códigos).
 *
 * Schema:
 *  - two_factor_secret           : TEXT encrypted (nulo = não habilitado)
 *  - two_factor_recovery_codes   : TEXT encrypted JSON array (códigos de
 *                                  recuperação, hashed). Nulo se não setup.
 *  - two_factor_confirmed_at     : datetime. Marca o momento em que o
 *                                  usuário confirmou o setup digitando um
 *                                  código válido. NULL = setup pendente
 *                                  (não pode ser usado como prova de 2FA).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
