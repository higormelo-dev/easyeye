<?php

declare(strict_types=1);

namespace App\Http\Requests\ClinicalReview;

use App\Domains\ClinicalReview\Enums\ReviewDecision;
use App\Domains\ClinicalReview\Models\ClinicalReviewInvitation;
use App\Domains\ClinicalReview\Support\ReviewerIdentity;
use App\Http\Middleware\EnsureClinicalReviewSession;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O parecer do Revisor Clínico (POST único). A forma é validada aqui; as regras que dependem
 * do estado (convite aberto, conjunto inalterado, declaração vigente) são do serviço, sob
 * trava. Erros de campo voltam por caminho (`decisions.<id>.comment`, `attestation.crm_uf`).
 */
class SubmitClinicalReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->invitation() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decisions'                          => ['required', 'array', 'min:1'],
            'decisions.*.decision'               => ['required', Rule::in(ReviewDecision::values())],
            'decisions.*.comment'                => ['nullable', 'string', 'max:3000'],
            'decisions.*.proposed_changes'       => ['nullable', 'string', 'max:6000'],
            'attestation'                        => ['required', 'array'],
            'attestation.name'                   => ['required', 'string', 'min:3', 'max:160'],
            'attestation.email'                  => ['required', 'string', 'email:rfc', 'max:190'],
            'attestation.crm_number'             => ['required', 'string', 'regex:/^[\d.\-\s]{1,15}$/'],
            'attestation.crm_uf'                 => ['required', 'string', Rule::in(ReviewerIdentity::UFS)],
            'attestation.rqe'                    => ['required', 'string', 'regex:/^[\d.\-\s]{1,15}$/'],
            'attestation.specialty'              => ['required', 'string', 'max:80'],
            'attestation.has_conflict'           => ['required', 'boolean'],
            'attestation.conflict_description'   => ['nullable', 'string', 'max:2000'],
            'attestation.allow_identity_display' => ['required', 'boolean'],
            'attestation.agreed'                 => ['accepted'],
            'statement_term_version_id'          => ['required', 'uuid'],
            'statement_sha256'                   => ['required', 'string', 'size:64'],
        ];
    }

    /**
     * Nomes dos campos nas mensagens ("O campo CRM (UF) …", não "attestation.crm uf").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'decisions'                    => __('clinical_review.public.fields.decisions'),
            'decisions.*.decision'         => __('clinical_review.public.fields.decision'),
            'decisions.*.comment'          => __('clinical_review.public.fields.comment'),
            'decisions.*.proposed_changes' => __('clinical_review.public.fields.proposed_changes'),
            'attestation.agreed'           => __('clinical_review.public.fields.agreed'),
            'statement_term_version_id'    => __('clinical_review.public.fields.statement'),
            'statement_sha256'             => __('clinical_review.public.fields.statement'),
        ];

        foreach (['name', 'email', 'crm_number', 'crm_uf', 'rqe', 'specialty', 'has_conflict', 'conflict_description', 'allow_identity_display'] as $field) {
            $attributes["attestation.{$field}"] = __("clinical_review.public.fields.{$field}");
        }

        return $attributes;
    }

    /**
     * Regras que a forma não diz (justificativa quando não aprova, descrição do conflito,
     * número que sobra vazio depois de tirar os zeros). Rodam SEMPRE — o Revisor vê todos os
     * problemas de uma vez, não um por tentativa —, pulando o campo que já tem erro de forma.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = $validator->errors();

                foreach ((array) $this->input('decisions', []) as $id => $row) {
                    if (! is_array($row) || $errors->has("decisions.{$id}.decision") || $errors->has("decisions.{$id}.comment")) {
                        continue;
                    }

                    $decision = ReviewDecision::tryFrom((string) ($row['decision'] ?? ''));

                    if ($decision?->requiresComment() && mb_strlen($this->text($row['comment'] ?? null)) < 10) {
                        $errors->add("decisions.{$id}.comment", __('clinical_review.public.errors.comment_required'));
                    }
                }

                if (! $errors->has('attestation.has_conflict') && ! $errors->has('attestation.conflict_description')
                    && $this->boolean('attestation.has_conflict') && $this->text($this->input('attestation.conflict_description')) === '') {
                    $errors->add('attestation.conflict_description', __('clinical_review.public.errors.conflict_description_required'));
                }

                foreach (['crm_number', 'rqe'] as $field) {
                    if (! $errors->has("attestation.{$field}") && ReviewerIdentity::number($this->text($this->input("attestation.{$field}"))) === '') {
                        $errors->add("attestation.{$field}", __('clinical_review.public.errors.number_required'));
                    }
                }
            },
        ];
    }

    /** Texto digitado, ou vazio se vier algo que não é texto (a regra de forma já recusou). */
    private function text(mixed $value): string
    {
        return is_string($value) ? ReviewerIdentity::text($value) : '';
    }

    public function invitation(): ?ClinicalReviewInvitation
    {
        $invitation = $this->attributes->get(EnsureClinicalReviewSession::ATTRIBUTE);

        return $invitation instanceof ClinicalReviewInvitation ? $invitation : null;
    }
}
