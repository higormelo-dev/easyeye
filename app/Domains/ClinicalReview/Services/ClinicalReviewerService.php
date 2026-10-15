<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Services;

use App\Domains\ClinicalReview\Models\ClinicalReviewer;
use App\Domains\ClinicalReview\Support\ReviewerIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro dos Revisores Clínicos. Só a EasyEye cadastra (o Revisor não tem usuário).
 * Desativar um Revisor revoga os convites pendentes dele na mesma transação — um link já
 * enviado não sobrevive ao desligamento da pessoa.
 */
class ClinicalReviewerService
{
    public function __construct(
        private readonly GovernanceLog $log,
        private readonly ReviewInvitationService $invitations,
    ) {
    }

    /**
     * @param array<string, mixed> $data campos do formulário (normalizados aqui)
     */
    public function create(array $data, ?User $actor = null): ClinicalReviewer
    {
        $normalized = ReviewerIdentity::normalize($data);

        return DB::transaction(function () use ($normalized, $actor): ClinicalReviewer {
            $reviewer = new ClinicalReviewer($normalized + ['active' => true]);
            // O ator é explícito: não depende de quem está logado no momento (comando, job).
            $reviewer->forceFill(['created_by' => $actor?->getKey(), 'updated_by' => $actor?->getKey()])->save();

            $this->log->record(
                GovernanceLog::SUBJECT_REVIEWER,
                (string) $reviewer->id,
                'created',
                null,
                'active',
                $actor,
                null,
                ['reviewer' => $reviewer->crmLabel()],
            );

            return $reviewer;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(ClinicalReviewer $reviewer, array $data, ?User $actor = null): ClinicalReviewer
    {
        $normalized = ReviewerIdentity::normalize($data);

        return DB::transaction(function () use ($reviewer, $normalized, $actor): ClinicalReviewer {
            $locked = ClinicalReviewer::query()->whereKey($reviewer->id)->lockForUpdate()->firstOrFail();

            $locked->fill($normalized);
            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return $locked;
            }

            $locked->save();

            // Só os NOMES dos campos: o log mostra o que mudou sem repetir dado pessoal.
            $this->log->record(GovernanceLog::SUBJECT_REVIEWER, (string) $locked->id, 'updated', null, null, $actor, null, ['fields' => $changed]);

            return $locked;
        });
    }

    public function setActive(ClinicalReviewer $reviewer, bool $active, ?User $actor = null): ClinicalReviewer
    {
        return DB::transaction(function () use ($reviewer, $active, $actor): ClinicalReviewer {
            $locked = ClinicalReviewer::query()->whereKey($reviewer->id)->lockForUpdate()->firstOrFail();

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->forceFill(['active' => $active])->save();

            $revoked = $active ? 0 : $this->invitations->revokePendingFor($locked, 'Revisor desativado.', $actor);

            $this->log->record(
                GovernanceLog::SUBJECT_REVIEWER,
                (string) $locked->id,
                $active ? 'activated' : 'deactivated',
                $active ? 'inactive' : 'active',
                $active ? 'active' : 'inactive',
                $actor,
                null,
                $active ? [] : ['invitations_revoked' => $revoked],
            );

            return $locked;
        });
    }
}
