<?php

use Database\Seeders\Cid10CodesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista completa da CID-10 (DATASUS) no catálogo global. Antes só havia a
 * seleção oftalmológica (~230 códigos) do Cid10CodesSeeder.
 *
 * - description: a descrição oficial mais longa tem 264 caracteres (passava
 *   do varchar(255)) → text.
 * - category: recebe o grupo da CID-10 (até 171 caracteres) → varchar(255).
 * - Carga idempotente (Cid10CodesSeeder → Cid10CatalogImporter): acrescenta
 *   os códigos que faltam, grava a descrição oficial/capítulo/grupo (colunas
 *   da 2026_10_08_900000) e corrige a descrição exibida que diverge da
 *   oficial — exceto a editada pelo manager. A categoria curada de
 *   oftalmologia fica como está.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('cid10_codes', function (Blueprint $table) {
            $table->text('description')->change();
            $table->string('category', 255)->nullable()->change();
        });

        // Seleção oftalmológica curada PRIMEIRO, depois a lista oficial: a
        // carga não sobrescreve, então a ordem garante as descrições curadas
        // também em instalação nova (migrations rodam antes dos seeders).
        app(Cid10CodesSeeder::class)->run();
    }

    public function down(): void
    {
        // Não remove códigos: podem já estar em prontuários, exames e guias.
        // As colunas continuam mais largas (encolher truncaria descrições).
    }
};
