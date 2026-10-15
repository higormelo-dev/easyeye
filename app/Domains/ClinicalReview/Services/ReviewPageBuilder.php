<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Services;

use App\Domains\ClinicalReview\Models\{ClinicalReviewInvitation, ClinicalReviewItem};
use App\Domains\ClinicalReview\Support\AttestationStatement;
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Support\{ProtocolContentDiff, ProtocolContentSchema, RecallPreview};
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monta o que a página PÚBLICA do Revisor recebe (ADR 0002, invariante I6): as versões do
 * convite — e SÓ elas —, com a regra, o que mudou desde a versão anterior, os exemplos de
 * datas e a declaração. Nunca entra aqui dado de paciente, de clínica ou de usuário da
 * EasyEye: o impacto, se existir um dia, é só contagem agregada.
 *
 * O conteúdo vem NORMALIZADO do servidor — o mesmo que o hash cobre e que RecallRuleCard
 * mostra no Manager e na clínica.
 */
class ReviewPageBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ClinicalReviewInvitation $invitation, string $locale): array
    {
        $items    = $invitation->items()->get();
        $versions = RecallProtocolVersion::query()
            ->with('protocol')
            ->whereIn('id', $items->pluck('subject_id'))
            ->get()
            ->keyBy('id');
        $parents = RecallProtocolVersion::query()
            ->whereIn('id', $versions->pluck('parent_version_id')->filter())
            ->get()
            ->keyBy('id');

        $anchor    = CarbonImmutable::today();
        $statement = AttestationStatement::current();
        $text      = $statement->contentFor($locale);

        $built = $items->map(fn (ClinicalReviewItem $item): array => $this->item($item, $versions->get($item->subject_id), $parents, $anchor, $locale))->values();

        return [
            // Só o nome (para cumprimentar). CRM, RQE e e-mail do cadastro NÃO vão à página: o Revisor DECLARA a
            // própria identidade no atestado e a EasyEye compara com o cadastro — se a página já viesse
            // preenchida, quem recebesse o link encaminhado assinaria como o convidado sem digitar nada.
            'reviewer'   => ['name' => $invitation->reviewer_name],
            'invitation' => [
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'sent_at'    => $invitation->sent_at?->toIso8601String(),
            ],
            'items' => $built->all(),
            // Alguma versão saiu de revisão (retirada, aposentada...): o convite não pode mais ser respondido.
            'available'   => $built->every(fn (array $item): bool => $item['available']),
            'exampleDate' => $anchor->toDateString(),
            'statement'   => [
                'term_version_id' => (string) $statement->id,
                'version'         => (string) $statement->version,
                'sha256'          => AttestationStatement::hash((string) $statement->content),
                'text'            => $text['content'],
                'is_translation'  => $text['is_translation'],
                // Quem lê a tradução assina o texto em português: ele também fica à vista (a tradução só ajuda).
                'official_text' => $text['is_translation'] ? (string) $statement->content : null,
            ],
            'draft'        => $invitation->draft_payload,
            'draftSavedAt' => $invitation->draft_saved_at?->toIso8601String(),
        ];
    }

    /**
     * @param Collection<string, RecallProtocolVersion> $parents
     *
     * @return array<string, mixed>
     */
    private function item(ClinicalReviewItem $item, ?RecallProtocolVersion $version, $parents, CarbonImmutable $anchor, string $locale): array
    {
        $available = $version !== null
            && $version->state === ProtocolVersionState::InReview
            && $version->content_hash === $item->content_hash;

        $content = null;
        $diff    = [];
        $parent  = null;

        if ($version !== null) {
            $content       = ProtocolContentSchema::normalize($version->contentArray());
            $parentVersion = $version->parent_version_id ? $parents->get($version->parent_version_id) : null;

            if ($parentVersion !== null) {
                $diff   = ProtocolContentDiff::between(ProtocolContentSchema::normalize($parentVersion->contentArray()), $content);
                $parent = ['version' => $parentVersion->version, 'validated' => $parentVersion->validated_acceptance_id !== null];
            }
        }

        $protocol = $version?->protocol;

        return [
            'id'         => (string) $item->id,
            'key'        => $item->subject_key,
            'title'      => $protocol instanceof RecallProtocol ? $protocol->title($locale) : $item->subject_key,
            'summary'    => $protocol instanceof RecallProtocol ? $protocol->text('summary', $locale) : '',
            'version'    => $item->subject_version,
            'short_hash' => substr((string) $item->content_hash, 2, 12),
            'available'  => $available,
            'content'    => $content,
            'parent'     => $parent,
            'diff'       => $diff,
            'examples'   => $content !== null ? RecallPreview::examples($content, $anchor) : [],
        ];
    }
}
