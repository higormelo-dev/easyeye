<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Instância Z-API GLOBAL do SaaS: uma única conta/número contratado pela
 * empresa dona serve de padrão para todas as clínicas; clínica pode plugar
 * número próprio (linha com entity_id) que tem precedência.
 *
 * A linha global é whatsapp_settings com entity_id NULL — singleton garantido
 * por unique parcial. Mensagens inbound chegadas pelo webhook global nascem
 * com entity_id NULL e são atribuídas à clínica ao casar com a mensagem
 * outbound pendente (telefone), por isso whatsapp_messages.entity_id também
 * passa a ser nullable.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE whatsapp_settings ALTER COLUMN entity_id DROP NOT NULL');
        DB::statement('ALTER TABLE whatsapp_messages ALTER COLUMN entity_id DROP NOT NULL');

        // Singleton: no máximo UMA linha global (entity_id NULL).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX whatsapp_settings_global_once
            ON whatsapp_settings ((TRUE))
            WHERE entity_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS whatsapp_settings_global_once');
        DB::statement('DELETE FROM whatsapp_settings WHERE entity_id IS NULL');
        DB::statement('DELETE FROM whatsapp_messages WHERE entity_id IS NULL');
        DB::statement('ALTER TABLE whatsapp_settings ALTER COLUMN entity_id SET NOT NULL');
        DB::statement('ALTER TABLE whatsapp_messages ALTER COLUMN entity_id SET NOT NULL');
    }
};
