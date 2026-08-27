<?php

declare(strict_types=1);

use App\Models\SystemProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Perfis FIXOS da plataforma (system_profiles), pré-definidos pelo dono do
 * SaaS — apresentação (label/descrição) dos papéis de entity_users.rule.
 *
 * Cria a tabela E seeda o catálogo na mesma migration (mesmo racional da
 * 2026_08_24_120000_sync_permissions_catalog_from_enum: seeder não roda em
 * deploy de ambiente já provisionado; sem as linhas, as telas caem no
 * fallback hardcoded e a Fase 4 — edição pelo manager — não teria dados).
 *
 * Idempotente: insere apenas (context, key) ausentes; nunca sobrescreve
 * labels/descrições já personalizados pelo dono do SaaS.
 *
 * As KEYS espelham SaasRule::values()/ClientRule::values() — os enums
 * continuam sendo a âncora de autorização (Gates/middlewares); esta tabela
 * é só apresentação/composição. Ver App\Models\SystemProfile.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('system_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 'saas' (dono do SaaS) | 'client' (clínica)
            $table->string('context', 16);
            // espelha o value do enum correspondente (SaasRule/ClientRule)
            $table->string('key', 32);

            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['context', 'key'], 'system_profiles_context_key_unique');
        });

        // Guard: migrate rodado fora do contexto do app (classe movida/removida
        // no futuro) não pode quebrar a cadeia — fresh installs ainda recebem
        // as linhas via SystemProfilesSeeder.
        if (! class_exists(SystemProfile::class)) {
            return;
        }

        foreach (SystemProfile::CATALOG as $context => $profiles) {
            $sort = 0;

            foreach ($profiles as $key => $row) {
                $sort++;

                $exists = DB::table('system_profiles')
                    ->where('context', $context)
                    ->where('key', $key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('system_profiles')->insert([
                    'id'          => (string) Str::uuid(),
                    'context'     => $context,
                    'key'         => $key,
                    'label'       => $row['label'],
                    'description' => $row['description'],
                    'sort_order'  => $sort,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_profiles');
    }
};
