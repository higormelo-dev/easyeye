<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{TermVersion, User, UserTermAcceptance};

class TermsService
{
    /**
     * Tipos de documentos que exigem aceite do usuário.
     */
    public const REQUIRED_TYPES = [
        'privacy_policy',
        'terms_of_service',
    ];

    /**
     * Registra o aceite de uma versão de documento pelo usuário.
     * LGPD Art. 8, §5 — ônus da prova do consentimento é do controlador.
     */
    public function accept(User $user, TermVersion $termVersion): UserTermAcceptance
    {
        return UserTermAcceptance::firstOrCreate(
            [
                'user_id'         => $user->id,
                'term_version_id' => $termVersion->id,
            ],
            [
                'accepted_at' => now(),
                'ip_address'  => request()->ip(),
                'user_agent'  => request()->userAgent(),
            ],
        );
    }

    /**
     * Verifica se o usuário aceitou TODOS os documentos obrigatórios na versão atual.
     */
    public function hasAcceptedAll(User $user): bool
    {
        foreach (self::REQUIRED_TYPES as $type) {
            $current = TermVersion::currentFor($type);

            if ($current === null) {
                continue;
            }

            $accepted = UserTermAcceptance::query()
                ->where('user_id', $user->id)
                ->where('term_version_id', $current->id)
                ->exists();

            if (! $accepted) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retorna os documentos obrigatórios que o usuário ainda não aceitou.
     *
     * @return TermVersion[]
     */
    public function pendingAcceptances(User $user): array
    {
        $pending = [];

        foreach (self::REQUIRED_TYPES as $type) {
            $current = TermVersion::currentFor($type);

            if ($current === null) {
                continue;
            }

            $accepted = UserTermAcceptance::query()
                ->where('user_id', $user->id)
                ->where('term_version_id', $current->id)
                ->exists();

            if (! $accepted) {
                $pending[] = $current;
            }
        }

        return $pending;
    }

    /**
     * Publica uma nova versão de documento e desativa as anteriores do mesmo tipo.
     */
    public function publish(
        string $type,
        string $version,
        string $content,
        string $effectiveFrom,
        User $createdBy,
        ?string $summary = null,
    ): TermVersion {
        TermVersion::query()
            ->where('type', $type)
            ->where('active', true)
            ->update(['active' => false]);

        return TermVersion::create([
            'type'           => $type,
            'version'        => $version,
            'content'        => $content,
            'summary'        => $summary,
            'effective_from' => $effectiveFrom,
            'active'         => true,
            'created_by'     => $createdBy->id,
        ]);
    }
}
