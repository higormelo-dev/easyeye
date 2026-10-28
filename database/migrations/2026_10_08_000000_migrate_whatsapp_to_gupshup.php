<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * WhatsApp: Z-API → Gupshup (API oficial da Meta).
 *
 * - whatsapp_settings: app Gupshup (app_id em claro — roteia o webhook, não é
 *   segredo), segredo do webhook cifrado, id da assinatura. As credenciais
 *   da Z-API saem do banco (coluna apagada: nenhum token velho fica) e os
 *   tokens de URL do webhook são trocados (os antigos vazaram na auditoria).
 * - audit_logs: tira webhook_token/credenciais já gravados da trilha do
 *   WhatsAppSetting (o model agora os exclui).
 * - whatsapp_messages: zapi_message_id → provider_message_id (dados
 *   preservados) + template, app de saída, status de entrega e erro. Saídas
 *   pendentes da Z-API viram skipped (o comando as reenvia por template).
 *   Respostas a mensagens `sent` da era Z-API não casam (sem
 *   whatsapp_setting_id) — limitação documentada.
 * - whatsapp_opt_outs: quem respondeu SAIR, por número que envia.
 *
 * Bancos: tokens e limpeza da auditoria em PHP (portável). Os índices
 * parciais (CREATE INDEX ... WHERE) exigem PostgreSQL (produção) ou SQLite —
 * MySQL/MariaDB não têm índice parcial e o módulo WhatsApp já depende disso
 * desde a 2026_08_18_200000 (que cria as tabelas): MySQL/MariaDB/SQL Server
 * NÃO são suportados por estas migrations.
 */
return new class() extends Migration {
    private const SETTING_CLASS = 'App\\Models\\WhatsApp\\WhatsAppSetting';

    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->string('provider', 20)->default('gupshup')->after('entity_id');
            $table->string('app_id', 100)->nullable()->after('provider');
            $table->text('webhook_secret')->nullable()->after('webhook_token');
            $table->string('subscription_id', 100)->nullable()->after('webhook_secret');
            $table->timestamp('webhook_subscribed_at')->nullable()->after('subscription_id');
        });

        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'instance_id']);
        });

        // Um app atende uma configuração só (o webhook é roteado por ele).
        DB::statement('CREATE UNIQUE INDEX whatsapp_settings_app_once ON whatsapp_settings (app_id) WHERE app_id IS NOT NULL');

        // Tokens de URL novos (64 hex, CSPRNG): os antigos estavam em audit_logs.
        foreach (DB::table('whatsapp_settings')->pluck('id') as $id) {
            DB::table('whatsapp_settings')->where('id', $id)->update(['webhook_token' => bin2hex(random_bytes(32))]);
        }

        $this->scrubAudit(self::SETTING_CLASS, ['webhook_token', 'webhook_secret', 'credentials']);

        DB::statement('DROP INDEX IF EXISTS whatsapp_messages_inbound_once');

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->renameColumn('zapi_message_id', 'provider_message_id');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->foreignUuid('whatsapp_setting_id')->nullable()->after('schedule_id')->constrained('whatsapp_settings')->nullOnDelete();
            $table->string('sender_app_id', 100)->nullable()->after('whatsapp_setting_id');
            $table->string('template', 80)->nullable()->after('kind');
            $table->string('wa_message_id')->nullable()->after('provider_message_id');
            $table->string('error_code', 40)->nullable()->after('error');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->timestamp('failed_at')->nullable()->after('read_at');
        });

        // Saídas da Z-API ainda pendentes (texto livre, sem template): no
        // envio pela Gupshup falhariam (sem payload.template_key). Viram
        // skipped — o comando de confirmação/pesquisa reaproveita a mesma
        // linha com o template, se a consulta ainda estiver na janela.
        DB::table('whatsapp_messages')
            ->where('direction', 'out')
            ->where('status', 'pending')
            ->update([
                'status'     => 'skipped',
                'error_code' => 'zapi_legacy',
                'error'      => 'zapi_legacy: pendente da Z-API — reenfileirada com o template da Gupshup pelo comando.',
            ]);

        // Entrada única por id do provedor (reentrega do webhook) e busca do
        // status/resposta pela mensagem enviada.
        DB::statement("CREATE UNIQUE INDEX whatsapp_messages_inbound_once ON whatsapp_messages (provider_message_id) WHERE direction = 'in' AND provider_message_id IS NOT NULL");
        DB::statement("CREATE INDEX whatsapp_messages_outbound_provider_id ON whatsapp_messages (provider_message_id) WHERE direction = 'out' AND provider_message_id IS NOT NULL");
        DB::statement("CREATE INDEX whatsapp_messages_outbound_wa_id ON whatsapp_messages (wa_message_id) WHERE direction = 'out' AND wa_message_id IS NOT NULL");

        Schema::create('whatsapp_opt_outs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Número que envia (app global do EasyEye ou o próprio da clínica).
            $table->foreignUuid('whatsapp_setting_id')->constrained('whatsapp_settings')->cascadeOnDelete();
            $table->string('phone', 20);
            // keyword = respondeu SAIR/PARAR/STOP · provider = a Gupshup/Meta recusou (número descadastrado)
            $table->string('source', 20)->default('keyword');
            $table->timestamp('opted_out_at');
            $table->timestamps();

            $table->unique(['whatsapp_setting_id', 'phone']);
        });
    }

    /**
     * Tira $keys de old_values/new_values da trilha de $class (em PHP, sem
     * operador JSON de um banco específico).
     *
     * @param list<string> $keys
     */
    private function scrubAudit(string $class, array $keys): void
    {
        DB::table('audit_logs')
            ->where('auditable_type', $class)
            ->select(['id', 'old_values', 'new_values'])
            ->chunkById(500, function ($rows) use ($keys) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach (['old_values', 'new_values'] as $column) {
                        $values = is_string($row->{$column}) ? json_decode($row->{$column}, true) : null;

                        if (is_array($values) && array_intersect_key($values, array_flip($keys)) !== []) {
                            $changes[$column] = json_encode(array_diff_key($values, array_flip($keys)));
                        }
                    }

                    if ($changes !== []) {
                        DB::table('audit_logs')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_opt_outs');

        DB::statement('DROP INDEX IF EXISTS whatsapp_messages_outbound_wa_id');
        DB::statement('DROP INDEX IF EXISTS whatsapp_messages_outbound_provider_id');
        DB::statement('DROP INDEX IF EXISTS whatsapp_messages_inbound_once');

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_setting_id');
            $table->dropColumn(['sender_app_id', 'template', 'wa_message_id', 'error_code', 'delivered_at', 'read_at', 'failed_at']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->renameColumn('provider_message_id', 'zapi_message_id');
        });

        DB::statement("CREATE UNIQUE INDEX whatsapp_messages_inbound_once ON whatsapp_messages (zapi_message_id) WHERE direction = 'in' AND zapi_message_id IS NOT NULL");

        DB::statement('DROP INDEX IF EXISTS whatsapp_settings_app_once');

        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn(['provider', 'app_id', 'webhook_secret', 'subscription_id', 'webhook_subscribed_at']);
        });

        // Volta a estrutura da Z-API, vazia: as credenciais apagadas não voltam.
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->text('credentials')->nullable()->after('entity_id');
            $table->string('instance_id')->nullable()->index()->after('credentials');
        });
    }
};
