<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{TermVersion, User, UserTermAcceptance};
use Illuminate\Support\Facades\DB;

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
     * Versão VIGENTE de cada documento obrigatório (tipo → versão), na ordem de REQUIRED_TYPES. Tipo sem versão
     * vigente (nada publicado, ou só com vigência futura) fica de fora — não há o que aceitar.
     *
     * @return array<string, TermVersion>
     */
    public function currentRequired(): array
    {
        $current = [];

        foreach (self::REQUIRED_TYPES as $type) {
            $version = TermVersion::currentFor($type);

            if ($version !== null) {
                $current[$type] = $version;
            }
        }

        return $current;
    }

    /**
     * O que a tela exibiu (UUIDs de versão enviados no cadastro) é EXATAMENTE o conjunto de versões vigentes,
     * sem importar ordem nem repetição? Aceite de um texto que a pessoa não viu não vale como prova.
     *
     * @param array<string, TermVersion> $current resultado de currentRequired()
     */
    public static function sameVersionSet(mixed $submitted, array $current): bool
    {
        if (! is_array($submitted)) {
            return false;
        }

        $shown = array_values(array_unique(array_map(fn ($id) => is_string($id) ? strtolower($id) : '', $submitted)));
        $now   = array_values(array_unique(array_map(fn (TermVersion $version) => strtolower((string) $version->id), $current)));

        sort($shown);
        sort($now);

        return $shown === $now;
    }

    /**
     * Verifica se o usuário aceitou TODOS os documentos obrigatórios na versão atual.
     */
    public function hasAcceptedAll(User $user): bool
    {
        return $this->pendingAcceptances($user) === [];
    }

    /**
     * Retorna os documentos obrigatórios que o usuário ainda não aceitou.
     *
     * @return TermVersion[]
     */
    public function pendingAcceptances(User $user): array
    {
        $pending = [];

        foreach ($this->currentRequired() as $current) {
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
     * Publica uma nova versão de documento e desativa as anteriores do mesmo tipo — ATOMICAMENTE: sem a transação,
     * quem lê entre as duas escritas via o tipo sem nenhuma versão vigente (o cadastro criaria a conta sem aceite).
     */
    public function publish(
        string $type,
        string $version,
        string $content,
        string $effectiveFrom,
        User $createdBy,
        ?string $summary = null,
    ): TermVersion {
        return DB::transaction(function () use ($type, $version, $content, $effectiveFrom, $createdBy, $summary): TermVersion {
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
        });
    }
}
