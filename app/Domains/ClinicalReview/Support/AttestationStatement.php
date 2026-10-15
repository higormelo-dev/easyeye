<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Support;

use App\Models\TermVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;

/**
 * A declaração que o Revisor Clínico assina junto do parecer (ADR 0002), mantida como
 * versão de documento legal em `term_versions` (tipo `clinical_reviewer_attestation`).
 *
 * Instalada SOB DEMANDA, a partir de resources/legal: nenhum seeder nem migration precisa
 * ter rodado para o fluxo funcionar, e a linha não polui a tabela de quem só usa os termos
 * de uso e a política de privacidade. O atestado de cada parecer copia o TEXTO e o sha256
 * da declaração vigente, então a prova nunca depende de esta linha continuar igual.
 *
 * Fora de TermsService::REQUIRED_TYPES de propósito: é aceite do Revisor externo, que não
 * tem usuário no sistema.
 */
final class AttestationStatement
{
    public const TYPE = 'clinical_reviewer_attestation';

    public const VERSION = '1.0';

    /** A versão vigente da declaração; instala a 1.0 se ainda não existir. */
    public static function current(): TermVersion
    {
        return TermVersion::currentFor(self::TYPE) ?? self::install();
    }

    /** sha256 do texto OFICIAL (pt_BR) — o que o atestado grava como prova. */
    public static function hash(string $officialText): string
    {
        return hash('sha256', $officialText);
    }

    /**
     * Cria a versão 1.0 (idempotente; seguro contra duas requisições ao mesmo tempo: o
     * índice único (type, version) decide e quem perde relê).
     */
    public static function install(): TermVersion
    {
        $attributes = [
            'content'        => trim(File::get(resource_path('legal/clinical-reviewer-attestation-1.0.txt'))),
            'translations'   => ['en' => trim(File::get(resource_path('legal/clinical-reviewer-attestation-1.0.en.txt')))],
            'summary'        => 'Declaração inicial do Revisor Clínico: identidade, o que revisou, validade, conflito de interesses, registro da prova, conferência no CFM e exibição do nome.',
            'effective_from' => '2026-10-22',
            'active'         => true,
        ];

        try {
            return TermVersion::query()->firstOrCreate(['type' => self::TYPE, 'version' => self::VERSION], $attributes);
        } catch (QueryException) {
            return TermVersion::query()->where('type', self::TYPE)->where('version', self::VERSION)->firstOrFail();
        }
    }
}
