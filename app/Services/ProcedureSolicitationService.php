<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{Cid10Code, ExamKit, Indication, Procedure};
use App\Services\Documents\CertificateBuilder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * F6 — Solicitação de procedimentos + indicações.
 *
 * Centraliza formatação das linhas exibidas na textarea editável do médico
 * e expõe as constantes clínicas (limite de itens + tipos aceitos), evitando
 * que controllers/views espalhem strings mágicas.
 */
class ProcedureSolicitationService
{
    /**
     * Teto de exames por pedido (Fase 2 / B2): o maior kit padrão tem 5
     * exames e um pré-operatório comum junta até três kits (catarata +
     * glaucoma + retina = 13) e ainda recebe exames avulsos — 20 cabe com
     * folga. O teto antigo (10 itens, exames + indicações somados) não
     * comportava dois kits com avulsos. Indicações e CIDs agora são campos do
     * pedido, com limite próprio (não ocupam vaga de exame).
     */
    public const MAX_PROCEDURES = 20;

    public const MAX_INDICATIONS = 5;

    public const MAX_CIDS = 4;

    public const MAX_KITS = 5;

    /**
     * Tipos canônicos de solicitação (paridade smart_oftal `solicitation_type`).
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'rotina'      => 'Rotina',
        'urgencia'    => 'Urgência',
        'controle'    => 'Controle',
        'comparativo' => 'Comparativo',
    ];

    /**
     * @return array<int, string>
     */
    public static function typeKeys(): array
    {
        return array_keys(self::TYPES);
    }

    public function resolveTypeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $key = strtolower(trim($type));

        if (! isset(self::TYPES[$key])) {
            throw new InvalidArgumentException("Tipo de solicitação inválido: {$type}");
        }

        return self::TYPES[$key];
    }

    /**
     * Formata linha de procedimento — `- Nome (Tipo)` ou `- Nome` quando sem tipo.
     */
    public function formatProcedureLine(Procedure $procedure, ?string $type = null): string
    {
        $label = $this->resolveTypeLabel($type);
        $name  = trim($procedure->name);

        if ($label !== null) {
            return sprintf('- %s (%s)', $name, $label);
        }

        return sprintf('- %s', $name);
    }

    /**
     * Formata linha de indicação — `- Indicação: Descrição`.
     */
    public function formatIndicationLine(Indication $indication): string
    {
        return sprintf('- Indicação: %s', trim($indication->description));
    }

    /**
     * Concatena lista de linhas (procedimentos + indicações já formatadas)
     * preservando newline final único.
     *
     * @param iterable<string> $lines
     */
    public function joinLines(iterable $lines): string
    {
        $rows = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                $rows[] = $line;
            }
        }

        if ($rows === []) {
            return '';
        }

        return implode("\n", $rows) . "\n";
    }

    /**
     * Pedido de exames ESTRUTURADO (Fase 2 / B2): resolve no SERVIDOR os
     * exames (catálogo da clínica ou texto livre com código TUSS), as
     * indicações, os CIDs e os kits usados, e monta o corpo do documento.
     * Nada do que a tela manda como nome/código de item do catálogo é
     * confiado — vem do catálogo.
     *
     * @param array<string, mixed> $payload items[], type, indications[], indication_text, cids[], notes, kit_ids[]
     *
     * @return array{html: string, metadata: array<string, mixed>}
     *
     * @throws ValidationException item/indicação/CID inválido para a clínica
     */
    public function buildRequest(string $entityId, array $payload): array
    {
        $items = $this->resolveItems($entityId, is_array($payload['items'] ?? null) ? $payload['items'] : []);

        $indications = $this->resolveIndications($entityId, is_array($payload['indications'] ?? null) ? $payload['indications'] : []);
        $cids        = $this->resolveCids(is_array($payload['cids'] ?? null) ? $payload['cids'] : []);
        // Kits usados: os informados + os marcados nos itens (rastreio).
        $kits = $this->resolveKits($entityId, [
            ...(is_array($payload['kit_ids'] ?? null) ? $payload['kit_ids'] : []),
            ...array_column($items, 'kit_id'),
        ]);
        $type  = $this->resolveTypeLabel(isset($payload['type']) ? (string) $payload['type'] : null);
        $text  = trim((string) ($payload['indication_text'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));

        $indicationText = implode('; ', [...array_column($indications, 'description'), ...($text !== '' ? [$text] : [])]);

        return [
            'html'     => $this->renderRequestHtml($items, $type, $indicationText, $cids, $notes),
            'metadata' => [
                'items'           => $items,
                'type'            => isset($payload['type']) && $payload['type'] !== '' ? strtolower((string) $payload['type']) : null,
                'indications'     => $indications,
                'indication_text' => $text !== '' ? $text : null,
                'cids'            => $cids,
                'notes'           => $notes !== '' ? $notes : null,
                'kits'            => $kits,
            ],
        ];
    }

    /**
     * Mesma montagem de buildRequest() sem catálogo nem kit — pedido em
     * texto livre (sem item nenhum). Caráter/indicação/CID/observações
     * continuam validados e indo pro documento e pros metadados (16ª
     * revisão Codex, P2).
     *
     * @param array<string, mixed> $payload type, indications[], indication_text, cids[], notes
     *
     * @return array{html: string, metadata: array<string, mixed>}
     */
    public function buildTextOnlyRequest(string $entityId, array $payload, string $content): array
    {
        $indications = $this->resolveIndications($entityId, is_array($payload['indications'] ?? null) ? $payload['indications'] : []);
        $cids        = $this->resolveCids(is_array($payload['cids'] ?? null) ? $payload['cids'] : []);
        $type        = $this->resolveTypeLabel(isset($payload['type']) ? (string) $payload['type'] : null);
        $text        = trim((string) ($payload['indication_text'] ?? ''));
        $notes       = trim((string) ($payload['notes'] ?? ''));

        $indicationText = implode('; ', [...array_column($indications, 'description'), ...($text !== '' ? [$text] : [])]);

        return [
            'html'     => $this->renderTextOnlyRequestHtml($content, $type, $indicationText, $cids, $notes),
            'metadata' => [
                'items'           => [],
                'type'            => isset($payload['type']) && $payload['type'] !== '' ? strtolower((string) $payload['type']) : null,
                'indications'     => $indications,
                'indication_text' => $text !== '' ? $text : null,
                'cids'            => $cids,
                'notes'           => $notes !== '' ? $notes : null,
                'kits'            => [],
            ],
        ];
    }

    /**
     * Corpo HTML a partir de dados JÁ resolvidos (nome/código do catálogo,
     * indicação, CID — ninguém aqui bate no banco). Extraído de buildRequest()
     * para reaproveitar no PREVIEW do modelo PESSOAL salvo pelo editor de
     * Documentações (3ª revisão Codex, P2): o modelo guarda caráter/indicação/
     * observações em `payload`, já validados quando foi salvo — revalidar de
     * novo contra o catálogo na prévia só arriscava 422 num procedimento
     * desativado depois, sem ganho (a emissão real já revalida, por
     * buildRequest()).
     *
     * @param list<array{code: ?string, name: string}>       $items
     * @param list<array{code: string, description: string}> $cids
     */
    public function renderRequestHtml(array $items, ?string $typeLabel, string $indicationText, array $cids, string $notes): string
    {
        $html = '<p><strong>Solicito:</strong></p><ol>';

        foreach ($items as $item) {
            // "cód." neutro: o código do kit é TUSS, mas o do catálogo da clínica
            // pode ser próprio/antigo — o papel não afirma o que não sabe.
            $html .= '<li>' . CertificateBuilder::text($item['name']) . ($item['code'] ? ' — cód. ' . CertificateBuilder::text($item['code']) : '') . '</li>';
        }

        $html .= '</ol>';

        return $html . $this->renderRequestTail($typeLabel, $indicationText, $cids, $notes);
    }

    /**
     * Pedido em TEXTO LIVRE (sem item do catálogo, modelo só-de-corpo ou
     * digitado direto) — o texto do médico faz o papel da lista de exames;
     * caráter/indicação/CID/observações seguem iguais ao pedido estruturado.
     * Sem isto, esses campos escolhidos no modal somem do documento e da
     * auditoria quando não há item nenhum (16ª revisão Codex, P2).
     */
    public function renderTextOnlyRequestHtml(string $content, ?string $typeLabel, string $indicationText, array $cids, string $notes): string
    {
        $html = nl2br(CertificateBuilder::text(trim($content)));

        return $html . $this->renderRequestTail($typeLabel, $indicationText, $cids, $notes);
    }

    /** Caráter/indicação/CID/observações — rodapé comum aos dois formatos de pedido. */
    private function renderRequestTail(?string $typeLabel, string $indicationText, array $cids, string $notes): string
    {
        $html = '';

        if ($typeLabel !== null) {
            $html .= '<p><strong>Caráter:</strong> ' . CertificateBuilder::text($typeLabel) . '</p>';
        }

        if ($indicationText !== '') {
            $html .= '<p><strong>Indicação clínica:</strong> ' . CertificateBuilder::text($indicationText) . '</p>';
        }

        if ($cids !== []) {
            $html .= '<p><strong>CID-10:</strong> ' . CertificateBuilder::text(implode('; ', array_map(
                fn (array $cid) => $cid['code'] . ' — ' . $cid['description'],
                $cids,
            ))) . '</p>';
        }

        if ($notes !== '') {
            $html .= '<p><strong>Observações:</strong> ' . nl2br(CertificateBuilder::text($notes)) . '</p>';
        }

        return $html;
    }

    /**
     * @param array<int, mixed> $items
     *
     * @return list<array{procedure_id: ?string, code: ?string, name: string, kit_id: ?string, in_catalog: bool}>
     */
    private function resolveItems(string $entityId, array $items): array
    {
        // validated() monta a lista regra a regra (procedure_id, depois
        // code/name): as chaves chegam fora de ordem — a ordem do médico é a
        // do índice.
        ksort($items);

        $ids = collect($items)->pluck('procedure_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();

        $catalog = $ids === [] ? collect() : Procedure::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('active', true)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['id', 'code', 'name'])
            ->keyBy(fn (Procedure $p) => (string) $p->id);

        $out  = [];
        $seen = [];

        foreach (array_values($items) as $index => $item) {
            $procedureId = filled($item['procedure_id'] ?? null) ? (string) $item['procedure_id'] : null;
            $procedure   = $procedureId !== null ? $catalog->get($procedureId) : null;

            if ($procedureId !== null && $procedure === null) {
                throw ValidationException::withMessages(["items.{$index}.procedure_id" => __('clinical_documents.request.procedure_invalid')]);
            }

            $name = $procedure ? trim((string) $procedure->name) : trim((string) ($item['name'] ?? ''));
            $code = $procedure ? (filled($procedure->code) ? trim((string) $procedure->code) : null)
                : (filled($item['code'] ?? null) ? trim((string) $item['code']) : null);

            if ($name === '') {
                throw ValidationException::withMessages(["items.{$index}.name" => __('clinical_documents.request.item_name_required')]);
            }

            // O mesmo exame marcado em dois kits sai uma vez só.
            $key = $procedure ? 'p:' . $procedure->id : 'c:' . ($code ?? '') . ':' . mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[]      = [
                'procedure_id' => $procedure ? (string) $procedure->id : null,
                'code'         => $code,
                'name'         => $name,
                'kit_id'       => filled($item['kit_id'] ?? null) ? (string) $item['kit_id'] : null,
                'in_catalog'   => $procedure !== null,
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages(['items' => __('clinical_documents.request.items_required')]);
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $ids
     *
     * @return list<array{id: string, description: string}>
     */
    private function resolveIndications(string $entityId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));

        if ($ids === []) {
            return [];
        }

        $found = Indication::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('active', true)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['id', 'description'])
            ->keyBy(fn (Indication $i) => (string) $i->id);

        $out = [];

        foreach ($ids as $index => $id) {
            $indication = $found->get($id);

            if ($indication === null) {
                throw ValidationException::withMessages(["indications.{$index}" => __('clinical_documents.request.indication_invalid')]);
            }

            $out[] = ['id' => $id, 'description' => trim((string) $indication->description)];
        }

        return $out;
    }

    /**
     * CIDs do pedido — só códigos do catálogo CID-10 (descrição do catálogo).
     *
     * @param array<int, mixed> $codes
     *
     * @return list<array{code: string, description: string}>
     */
    public function resolveCids(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($code) => strtoupper(trim(is_array($code) ? (string) ($code['code'] ?? '') : (string) $code)),
            $codes,
        ))));

        if ($codes === []) {
            return [];
        }

        $found = Cid10Code::query()->whereIn('code', $codes)->get(['code', 'description'])->keyBy('code');
        $out   = [];

        foreach ($codes as $index => $code) {
            $cid = $found->get($code);

            if ($cid === null) {
                throw ValidationException::withMessages(["cids.{$index}" => __('clinical_documents.request.cid_invalid', ['code' => $code])]);
            }

            $out[] = ['code' => $code, 'description' => (string) $cid->description];
        }

        return $out;
    }

    /**
     * Kits usados (rastreio): da clínica ou globais; de outra clínica, 422.
     *
     * @param array<int, mixed> $ids
     *
     * @return list<array{id: string, name: string}>
     */
    private function resolveKits(string $entityId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));

        if ($ids === []) {
            return [];
        }

        $found = ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['id', 'name'])
            ->keyBy(fn (ExamKit $k) => (string) $k->id);

        $out = [];

        foreach ($ids as $index => $id) {
            $kit = $found->get($id);

            if ($kit === null) {
                throw ValidationException::withMessages(["kit_ids.{$index}" => __('clinical_documents.request.kit_invalid')]);
            }

            $out[] = ['id' => $id, 'name' => $kit->name];
        }

        return $out;
    }
}
