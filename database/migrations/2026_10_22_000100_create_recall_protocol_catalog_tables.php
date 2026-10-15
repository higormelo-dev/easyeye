<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Catálogo de protocolos de retorno (recall) — Fase 6, ADR 0002.
 *
 *  - `recall_protocols`: o protocolo de uma condição (glaucoma, DMRI…). GLOBAL.
 *  - `recall_protocol_versions`: as VERSÕES. O conteúdo é JSON canônico em coluna
 *    `text` (não `jsonb`, que reordena chaves e normaliza números e quebraria o hash);
 *    `content_hash` = CanonicalJson::hash(...) do texto. Só `draft` muda o conteúdo: o
 *    trigger do PostgreSQL recusa alterar conteúdo/hash/versão/protocolo de qualquer
 *    outro estado, recusa voltar a `draft` e recusa apagar versão congelada.
 *  - `clinical_governance_events`: log append-only (trigger recusa UPDATE/DELETE) das
 *    decisões sobre versões e convites de revisão. Sem FK no ator: o log sobrevive à
 *    remoção do usuário.
 *
 * Índices únicos parciais: no máximo UMA versão `released` por protocolo e UMA em
 * andamento (`draft`/`in_review`).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('recall_protocols', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 60)->unique();
            // {"pt_BR": {"title": "...", "summary": "..."}, "en": {...}} — metadado de exibição, FORA do hash.
            $table->json('translations');
            $table->string('status', 12)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recall_protocol_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('protocol_id')->constrained('recall_protocols')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->uuid('parent_version_id')->nullable();
            // JSON canônico (App\Support\CanonicalJson) do conteúdo; o hash é sobre estes bytes.
            $table->text('content');
            $table->string('content_hash', 66);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('state', 12)->default('draft');
            // Controle otimista de edição do rascunho (duas abas não se sobrescrevem).
            $table->unsignedInteger('lock_version')->default(0);

            $table->timestamp('frozen_at')->nullable();
            $table->foreignUuid('frozen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            // FK para clinical_review_acceptances entra com as tabelas de revisão.
            $table->uuid('validated_acceptance_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignUuid('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspended_reason')->nullable();
            // Estado a que a retomada volta (released ou superseded).
            $table->string('suspended_from_state', 12)->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->foreignUuid('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retired_reason')->nullable();
            // Quando a versão liberada precisa ser revalidada (aviso; não bloqueia).
            $table->date('review_due_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['protocol_id', 'version']);
            $table->index(['protocol_id', 'state']);
        });

        Schema::table('recall_protocol_versions', function (Blueprint $table) {
            $table->foreign('parent_version_id')->references('id')->on('recall_protocol_versions')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE recall_protocol_versions ADD CONSTRAINT recall_protocol_versions_state_check CHECK (state IN ('draft', 'in_review', 'validated', 'released', 'superseded', 'suspended', 'declined', 'withdrawn', 'retired'))");
        DB::statement("ALTER TABLE recall_protocol_versions ADD CONSTRAINT recall_protocol_versions_hash_check CHECK (length(content_hash) = 66 AND substr(content_hash, 1, 2) = '1:')");
        DB::statement("ALTER TABLE recall_protocol_versions ADD CONSTRAINT recall_protocol_versions_suspended_from_check CHECK (suspended_from_state IS NULL OR suspended_from_state IN ('released', 'superseded'))");

        DB::statement("CREATE UNIQUE INDEX recall_protocol_versions_one_released ON recall_protocol_versions (protocol_id) WHERE state = 'released'");
        DB::statement("CREATE UNIQUE INDEX recall_protocol_versions_one_open ON recall_protocol_versions (protocol_id) WHERE state IN ('draft', 'in_review')");

        Schema::create('clinical_governance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 'recall_protocol_version', 'clinical_review_invitation', 'clinical_reviewer'…
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->string('event', 40);
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20)->nullable();
            $table->uuid('actor_user_id')->nullable();
            // Nome do ator no momento (ou 'system', 'reviewer:<id>') — o log se explica sozinho.
            $table->string('actor_label', 160)->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'clinical_governance_events_subject_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->createGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS clinical_governance_events_append_only ON clinical_governance_events');
            DB::unprepared('DROP TRIGGER IF EXISTS recall_protocol_versions_guard ON recall_protocol_versions');
            DB::unprepared('DROP FUNCTION IF EXISTS clinical_governance_events_append_only()');
            DB::unprepared('DROP FUNCTION IF EXISTS recall_protocol_versions_guard()');
        }

        Schema::dropIfExists('clinical_governance_events');
        Schema::dropIfExists('recall_protocol_versions');
        Schema::dropIfExists('recall_protocols');
    }

    /**
     * Imutabilidade no próprio banco: quem tem acesso ao SQL ainda não reescreve o que o
     * revisor viu. (Quem é DONO da tabela pode desligar o trigger — por isso o comando
     * noturno recall:verify-integrity refaz o hash e suspende a versão que divergir.).
     */
    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION recall_protocol_versions_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.state <> 'draft' THEN
            RAISE EXCEPTION 'recall_protocol_versions: versão % (estado %) congelada não pode ser apagada', OLD.id, OLD.state;
        END IF;

        RETURN OLD;
    END IF;

    IF OLD.state <> 'draft' THEN
        IF NEW.state = 'draft' THEN
            RAISE EXCEPTION 'recall_protocol_versions: versão % congelada não volta a rascunho', OLD.id;
        END IF;

        IF NEW.content IS DISTINCT FROM OLD.content
            OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
            OR NEW.schema_version IS DISTINCT FROM OLD.schema_version
            OR NEW.version IS DISTINCT FROM OLD.version
            OR NEW.protocol_id IS DISTINCT FROM OLD.protocol_id
            OR NEW.parent_version_id IS DISTINCT FROM OLD.parent_version_id THEN
            RAISE EXCEPTION 'recall_protocol_versions: conteúdo da versão % está congelado (estado %)', OLD.id, OLD.state;
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER recall_protocol_versions_guard BEFORE UPDATE OR DELETE ON recall_protocol_versions FOR EACH ROW EXECUTE FUNCTION recall_protocol_versions_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_governance_events_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'clinical_governance_events é append-only (% recusado)', TG_OP;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER clinical_governance_events_append_only BEFORE UPDATE OR DELETE ON clinical_governance_events FOR EACH ROW EXECUTE FUNCTION clinical_governance_events_append_only()');
    }
};
