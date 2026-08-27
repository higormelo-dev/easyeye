<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificação de WhatsApp do responsável no registro (/register).
 *
 * `users.phone` já existia; estas colunas guardam o estado da verificação
 * por código OTP enviado via instância global Z-API do SaaS:
 *  - phone_verified_at: quando o número foi confirmado (análogo de
 *    email_verified_at). Contato confirmado = lead qualificado para o time
 *    comercial entrar em contato via WhatsApp.
 *  - phone_verification_code: HASH (sha256) do código de 6 dígitos — nunca
 *    plaintext no banco.
 *  - phone_verification_expires_at: TTL do código (10 min).
 *  - phone_verification_attempts: tentativas erradas desde o último envio —
 *    5 erros invalidam o código (força reenvio), contra brute-force além do
 *    throttle de rota.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('phone_verification_code', 64)->nullable()->after('phone_verified_at');
            $table->timestamp('phone_verification_expires_at')->nullable()->after('phone_verification_code');
            $table->unsignedSmallInteger('phone_verification_attempts')->default(0)->after('phone_verification_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone_verified_at',
                'phone_verification_code',
                'phone_verification_expires_at',
                'phone_verification_attempts',
            ]);
        });
    }
};
