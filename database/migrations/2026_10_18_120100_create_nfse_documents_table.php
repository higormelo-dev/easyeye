<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * NFS-e do SaaS (Focus NFe): uma linha por tentativa de emissão de uma fatura
 * paga. Fatura 1:N notas — reemissão depois de erro/cancelamento é uma NOVA
 * linha, com nova `ref`; a nota emitida nunca é reescrita.
 *
 *  - ref: determinística (id da linha sem hífens, maiúsculo), nasce uma vez e é
 *    a mesma no envio, na consulta, no cancelamento e no e-mail. A Focus só
 *    deixa reutilizar a ref enquanto a nota NÃO foi autorizada;
 *  - amount/currency e payer_snapshot ficam CONGELADOS na criação (valor pago
 *    e dados fiscais do tomador naquele momento — registro legal da emissão);
 *  - dps_series/dps_number: série e número da DPS do padrão nacional, também
 *    congelados (a Focus exige os dois; o contador mora em system_settings);
 *  - request_payload/response_payload/errors: SANEADOS, nunca com token.
 *
 * Fiscal não se apaga: nada de soft delete nem cascade — fatura e clínica com
 * nota emitida não podem ser removidas (guarda de 5 anos).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('nfse_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->restrictOnDelete();
            $table->string('environment', 20);
            $table->string('standard', 20);
            $table->string('ref', 40)->unique();
            // pending_send | send_failed | processando_autorizacao | autorizado | erro_autorizacao | cancelado
            $table->string('status', 30);
            $table->string('numero', 30)->nullable();
            $table->string('codigo_verificacao', 60)->nullable();
            $table->unsignedSmallInteger('dps_series')->nullable();
            $table->unsignedBigInteger('dps_number')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('BRL');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // "Cancelei na prefeitura": município sem cancelamento por webservice.
            $table->boolean('cancelled_externally')->default(false);
            // Estorno total/chargeback sem cancelamento automático possível: pendência para o humano.
            $table->boolean('cancel_required')->default(false);
            $table->string('cancel_required_reason', 40)->nullable();
            $table->text('url')->nullable();
            $table->text('pdf_url')->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->string('cancel_xml_path', 255)->nullable();
            $table->jsonb('payer_snapshot')->nullable();
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->jsonb('errors')->nullable();
            $table->string('justification', 255)->nullable();
            $table->string('correlation_id', 64)->nullable();
            // auto (InvoicePaid) | manual (ação no Manager) | batch (fiscal:nfse-reissue)
            $table->string('source', 20)->default('auto');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index(['entity_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        // Uma nota "ocupando" por fatura: a que está a caminho ou valendo. Índice único parcial (o schema builder não
        // cobre WHERE) — é o backstop do lock da fatura: duas ações simultâneas nunca geram duas notas vivas.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX nfse_documents_invoice_occupying_unique
            ON nfse_documents (invoice_id)
            WHERE status IN ('pending_send', 'processando_autorizacao', 'autorizado')
        SQL);

        // Backstop do contador da DPS: a mesma série/número nunca vai duas vezes ao mesmo ambiente.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX nfse_documents_dps_unique
            ON nfse_documents (environment, dps_series, dps_number)
            WHERE dps_number IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS nfse_documents_dps_unique');
        DB::statement('DROP INDEX IF EXISTS nfse_documents_invoice_occupying_unique');
        Schema::dropIfExists('nfse_documents');
    }
};
