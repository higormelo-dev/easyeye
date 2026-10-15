<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Validação clínica por Revisor externo — Fase 6, ADR 0002.
 *
 *  - `clinical_reviewers`: o oftalmologista que valida (SEM usuário no sistema: age por
 *    convite). Dados de identificação como a EasyEye os conferiu.
 *  - `clinical_review_invitations`: o convite pessoal e temporário. O token NUNCA é
 *    gravado em claro (só o sha256); `set_hash` identifica o conjunto de versões que o
 *    revisor vai ver. `draft_payload` (cifrado) guarda o rascunho do parecer no servidor.
 *  - `clinical_review_items`: uma linha por versão do convite, com a decisão do revisor,
 *    gravada UMA vez no envio (trigger recusa mudar depois).
 *  - `clinical_review_attestations`: o atestado (1:1 com o convite) — identidade
 *    declarada, texto exato da declaração, IP e navegador, hash do envio. Append-only.
 *  - `clinical_review_acceptances`: o aceite da EasyEye (conferência do CRM/RQE no portal
 *    do CFM). Append-only.
 *
 * É a prova de "quem validou o quê, quando e qual versão": nada aqui é reescrito depois.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('clinical_reviewers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('email', 190);
            $table->string('crm_number', 12);
            $table->char('crm_uf', 2);
            $table->string('rqe', 12);
            $table->string('specialty', 80)->default('Oftalmologia');
            $table->string('phone', 20)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['crm_uf', 'crm_number']);
        });

        DB::statement('CREATE UNIQUE INDEX clinical_reviewers_email_unique ON clinical_reviewers (lower(email))');

        Schema::create('clinical_review_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reviewer_id')->constrained('clinical_reviewers')->restrictOnDelete();
            // Retrato do revisor no momento do convite (o cadastro pode mudar depois).
            $table->string('reviewer_name', 160);
            $table->string('reviewer_email', 190);
            // sha256 do token (nunca o token). O link é assinado (APP_KEY) E leva o token.
            $table->char('token_hash', 64)->unique();
            // CanonicalJson::hash do conjunto de versões do convite.
            $table->string('set_hash', 66);
            $table->string('status', 12)->default('pending');
            $table->timestamp('expires_at');
            // Só depois do envio de verdade (NUNCA "enfileirado").
            $table->timestamp('sent_at')->nullable();
            $table->text('last_send_error')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            // PIN opcional, entregue por OUTRO canal; só o hash.
            $table->string('access_code_hash')->nullable();
            $table->unsignedSmallInteger('access_attempts')->default(0);
            $table->timestamp('access_locked_until')->nullable();
            // Rascunho do parecer (cifrado, apagado ao enviar/expirar/revogar).
            $table->text('draft_payload')->nullable();
            $table->timestamp('draft_saved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoked_reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('reviewer_id');
        });

        DB::statement("ALTER TABLE clinical_review_invitations ADD CONSTRAINT clinical_review_invitations_status_check CHECK (status IN ('pending', 'submitted', 'accepted', 'refused', 'expired', 'revoked'))");
        // O mesmo conjunto de versões não tem dois convites pendentes para o mesmo revisor.
        DB::statement("CREATE UNIQUE INDEX clinical_review_invitations_one_pending ON clinical_review_invitations (reviewer_id, set_hash) WHERE status = 'pending'");

        Schema::create('clinical_review_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invitation_id')->constrained('clinical_review_invitations')->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->string('subject_key', 80);
            $table->unsignedSmallInteger('subject_version');
            $table->string('content_hash', 66);
            $table->string('decision', 20)->nullable();
            $table->text('comment')->nullable();
            $table->text('proposed_changes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['invitation_id', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE clinical_review_items ADD CONSTRAINT clinical_review_items_decision_check CHECK (decision IS NULL OR decision IN ('approved', 'changes_requested', 'rejected'))");

        Schema::create('clinical_review_attestations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invitation_id')->unique()->constrained('clinical_review_invitations')->restrictOnDelete();
            // Identidade DECLARADA pelo revisor (a EasyEye confere no CFM — ver acceptances).
            $table->string('declared_name', 160);
            $table->string('declared_email', 190);
            $table->string('declared_crm_number', 12);
            $table->char('declared_crm_uf', 2);
            $table->string('declared_rqe', 12);
            $table->string('declared_specialty', 80);
            // A declaração: versão do termo + texto EXATO + hash (a prova não depende do termo atual).
            $table->foreignUuid('statement_term_version_id')->nullable()->constrained('term_versions')->nullOnDelete();
            $table->char('statement_sha256', 64);
            $table->text('statement_text');
            $table->boolean('conflict_of_interest')->default(false);
            $table->text('conflict_description')->nullable();
            // Autoriza mostrar nome + CRM/UF às clínicas que adotarem a regra.
            $table->boolean('allow_identity_display')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('signed_at');
            $table->string('set_hash', 66);
            // CanonicalJson::hash de decisões + atestado + signed_at (UTC).
            $table->string('submission_hash', 66);
            $table->timestamp('receipt_sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('clinical_review_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invitation_id')->unique()->constrained('clinical_review_invitations')->restrictOnDelete();
            $table->string('decision', 10);
            // Sem FK: o registro sobrevive à remoção do usuário.
            $table->uuid('accepted_by')->nullable();
            $table->string('accepted_by_label', 160)->nullable();
            $table->string('verification_method', 20);
            $table->boolean('crm_checked')->default(false);
            $table->boolean('rqe_checked')->default(false);
            $table->timestamp('checked_at');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE clinical_review_acceptances ADD CONSTRAINT clinical_review_acceptances_decision_check CHECK (decision IN ('accepted', 'refused'))");

        // A versão validada aponta o aceite que a validou.
        Schema::table('recall_protocol_versions', function (Blueprint $table) {
            $table->foreign('validated_acceptance_id')->references('id')->on('clinical_review_acceptances')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->createGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS clinical_review_acceptances_append_only ON clinical_review_acceptances');
            DB::unprepared('DROP TRIGGER IF EXISTS clinical_review_attestations_guard ON clinical_review_attestations');
            DB::unprepared('DROP TRIGGER IF EXISTS clinical_review_items_decision_once ON clinical_review_items');
            DB::unprepared('DROP FUNCTION IF EXISTS clinical_review_acceptances_append_only()');
            DB::unprepared('DROP FUNCTION IF EXISTS clinical_review_attestations_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS clinical_review_items_decision_once()');
        }

        Schema::table('recall_protocol_versions', function (Blueprint $table) {
            $table->dropForeign(['validated_acceptance_id']);
        });

        Schema::dropIfExists('clinical_review_acceptances');
        Schema::dropIfExists('clinical_review_attestations');
        Schema::dropIfExists('clinical_review_items');
        Schema::dropIfExists('clinical_review_invitations');
        Schema::dropIfExists('clinical_reviewers');
    }

    /**
     * A prova é imutável no próprio banco: a decisão do revisor grava UMA vez; o atestado
     * só aceita marcar o recibo como enviado; o aceite não muda.
     */
    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_review_items_decision_once() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.decision IS NOT NULL THEN
            RAISE EXCEPTION 'clinical_review_items: item % já decidido não pode ser apagado', OLD.id;
        END IF;

        RETURN OLD;
    END IF;

    IF OLD.decision IS NOT NULL AND (
        NEW.decision IS DISTINCT FROM OLD.decision
        OR NEW.comment IS DISTINCT FROM OLD.comment
        OR NEW.proposed_changes IS DISTINCT FROM OLD.proposed_changes
        OR NEW.decided_at IS DISTINCT FROM OLD.decided_at
        OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
        OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
        OR NEW.invitation_id IS DISTINCT FROM OLD.invitation_id
    ) THEN
        RAISE EXCEPTION 'clinical_review_items: a decisão do item % já foi gravada e não muda', OLD.id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER clinical_review_items_decision_once BEFORE UPDATE OR DELETE ON clinical_review_items FOR EACH ROW EXECUTE FUNCTION clinical_review_items_decision_once()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_review_attestations_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'clinical_review_attestations é append-only (DELETE recusado)';
    END IF;

    IF (to_jsonb(NEW) - 'receipt_sent_at') IS DISTINCT FROM (to_jsonb(OLD) - 'receipt_sent_at') THEN
        RAISE EXCEPTION 'clinical_review_attestations é append-only: só receipt_sent_at pode ser gravado depois';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER clinical_review_attestations_guard BEFORE UPDATE OR DELETE ON clinical_review_attestations FOR EACH ROW EXECUTE FUNCTION clinical_review_attestations_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION clinical_review_acceptances_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'clinical_review_acceptances é append-only (% recusado)', TG_OP;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER clinical_review_acceptances_append_only BEFORE UPDATE OR DELETE ON clinical_review_acceptances FOR EACH ROW EXECUTE FUNCTION clinical_review_acceptances_append_only()');
    }
};
