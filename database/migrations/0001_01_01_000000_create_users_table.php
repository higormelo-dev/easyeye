<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // ── users ────────────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Contato & perfil
            $table->string('phone', 30)->nullable();
            $table->string('avatar', 500)->nullable();

            // Preferências
            $table->string('locale', 10)->nullable()
                ->comment('Idioma preferido; sobrepõe o locale da empresa. Nulo = usar o locale da empresa.');

            // Estado
            $table->boolean('active')->default(true)
                ->comment('false = acesso bloqueado pelo admin do sistema.');
            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index(['active', 'deleted_at']);
            $table->index('last_login_at');
        });

        // ── password_reset_tokens ────────────────────────────────────────────
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // ── sessions ─────────────────────────────────────────────────────────
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
