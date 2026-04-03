<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identificação
            $table->string('code', 30)->unique()
                ->comment('Código gerado automaticamente. Ex: CL-0000000001.');
            $table->string('name', 255);
            $table->string('subdomain', 100)->unique()
                ->comment('Slug usado para identificar a empresa na URL.');

            // Registro fiscal
            $table->string('national_registration', 20)->nullable()->unique()
                ->comment('CNPJ. Nullable para empresas em configuração.');
            $table->string('state_registration', 50)->nullable();
            $table->string('municipal_registration', 50)->nullable();

            // Endereço
            $table->string('zipcode', 10)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 5)->nullable()
                ->comment('Sigla da UF (ex: SP). varchar(5) aceita siglas internacionais.');
            $table->string('country', 5)->nullable()->default('BR');

            // Contato
            $table->string('telephone', 30)->nullable();
            $table->string('cellphone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('logo', 500)->nullable();

            // Flags
            $table->boolean('is_client')->default(true)
                ->comment('true = empresa cliente SaaS; false = entidade interna/parceiro.');
            $table->boolean('active')->default(false)
                ->comment('Ativada após o primeiro acesso ou aprovação manual.');
            $table->string('locale', 10)->default('pt_BR')
                ->comment('Idioma padrão da empresa; pode ser sobreposto pelo locale do usuário.');

            $table->softDeletes();
            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index(['active', 'deleted_at']);
            $table->index(['is_client', 'active']);
            $table->index(['city', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_displays'); // resíduo removido; evita FK block no PostgreSQL
        Schema::dropIfExists('entities');
    }
};
