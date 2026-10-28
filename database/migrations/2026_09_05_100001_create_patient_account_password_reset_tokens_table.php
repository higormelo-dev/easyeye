<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de tokens de reset de senha PRÓPRIA do Portal do Paciente.
 * NUNCA reusar `password_reset_tokens` (tabela de staff, guard "web") — ver
 * config/auth.php passwords.patients.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('patient_account_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_account_password_reset_tokens');
    }
};
