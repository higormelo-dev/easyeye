<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manifesto de auto-atualização do EasyEye Integrator.
 *
 * O desktop chama GET /api/integrators/v1/updates?platform=…&arch=… e espera
 * o build mais recente publicado para a plataforma dele (ou data:null quando
 * não há nada). O binário fica no S3; a resposta entrega temporaryUrl +
 * sha256 + assinatura ed25519 (gerada offline — a chave privada NUNCA passa
 * pelo SaaS; ver scripts/sign-update.sh no repositório do integrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrator_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version', 32);
            // Valores que o cliente Rust envia: std::env::consts::OS
            // ('windows', 'linux', 'macos') e ::ARCH ('x86', 'x86_64', 'aarch64').
            $table->string('platform', 16);
            $table->string('arch', 16);
            $table->string('archive');
            $table->char('sha256', 64);
            $table->text('signature');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['platform', 'arch', 'version']);
            $table->index(['platform', 'arch', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrator_updates');
    }
};
