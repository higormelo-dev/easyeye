<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IP e MAC deixam de ser obrigatórios no equipamento do integrador.
 *
 * O integrador funciona por PASTA monitorada — IP/MAC nunca participaram do
 * pipeline de captura/envio (o upload sai do PC da clínica por HTTPS). Boa
 * parte dos aparelhos reais nem tem placa de rede: o primeiro equipamento de
 * clínica piloto (Topcon TRC-50DX, retinógrafo com DSLR acoplada) captura
 * por câmera ligada ao laptop. Exigir IP/MAC só criava atrito no cadastro e
 * dados inventados. Ficam como metadados opcionais de inventário.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE entity_integrator_equipments ALTER COLUMN ip DROP NOT NULL');
        DB::statement('ALTER TABLE entity_integrator_equipments ALTER COLUMN mac DROP NOT NULL');
    }

    public function down(): void
    {
        // Linhas com NULL impediriam o SET NOT NULL; preenche antes com
        // marcadores claramente sintéticos.
        DB::statement("UPDATE entity_integrator_equipments SET ip = '0.0.0.0' WHERE ip IS NULL");
        DB::statement("UPDATE entity_integrator_equipments SET mac = '00:00:00:00:00:00' WHERE mac IS NULL");
        DB::statement('ALTER TABLE entity_integrator_equipments ALTER COLUMN ip SET NOT NULL');
        DB::statement('ALTER TABLE entity_integrator_equipments ALTER COLUMN mac SET NOT NULL');
    }
};
