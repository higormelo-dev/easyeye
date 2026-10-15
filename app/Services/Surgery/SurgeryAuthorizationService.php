<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{AuthorizationEventType, AuthorizationStatus, SurgicalCaseStatus};
use App\Events\Surgery\AuthorizationEventRecorded;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase, SurgicalCaseMaterial, SurgicalCaseProcedure};
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autorização do pedido cirúrgico (ADR 0003, D9).
 *
 * A verdade é o log imutável `surgical_authorization_events`; o estado
 * corrente (`authorization_status`, número, senha, validade) é desnormalizado
 * em `surgical_cases` na MESMA transação, sob a trava da linha do caso.
 *
 * Quem grava o evento NÃO decide a transição por conta própria: o tipo do
 * evento diz o que acontece com o caso (Authorized → autorizado, Denied →
 * negado...), e a transição é validada pela máquina de estados contra o estado
 * ATUAL relido sob a trava.
 */
class SurgeryAuthorizationService
{
    /** Tipos que o operador registra à mão (os demais nascem do fluxo ou do job). */
    public const RECORDABLE = [
        AuthorizationEventType::Submitted,
        AuthorizationEventType::Authorized,
        AuthorizationEventType::PartiallyAuthorized,
        AuthorizationEventType::Denied,
        AuthorizationEventType::Waived,
        AuthorizationEventType::Note,
    ];

    public function __construct(private readonly SurgicalCaseService $cases)
    {
    }

    // ── Pedido ──────────────────────────────────────────────────────────────

    /**
     * Envia o pedido à operadora: rascunho → aguardando; negado → aguardando
     * (recurso, evento `appealed`). Confere se o pedido está completo e
     * recongela o retrato de segurança clínica.
     */
    public function request(SurgicalCase $case, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $expectedRevision): SurgicalCase {
            $locked = $this->cases->lock((string) $case->getKey());

            $this->cases->assertRevision($locked, $expectedRevision);

            if (! in_array($locked->status, [SurgicalCaseStatus::Draft, SurgicalCaseStatus::Denied], true)) {
                throw SurgeryRuleException::state('surgery.errors.cannot_request_in_status', ['status' => $locked->status->label()]);
            }

            $this->assertRequestComplete($locked);

            $appeal = $locked->status === SurgicalCaseStatus::Denied;

            // Rodada nova de autorização: o que a operadora respondeu antes (parte do pedido
            // autorizada, depois negada ou retirada) não vale para o pedido reenviado.
            $this->clearAuthorizedQuantities($locked);

            $this->cases->captureSafetySnapshot($locked);

            $this->cases->changeStatus($locked, SurgicalCaseStatus::AwaitingAuthorization, null, [
                'authorization_status'      => AuthorizationStatus::Pending,
                'authorization_number'      => null,
                'authorization_password'    => null,
                'authorization_valid_until' => null,
                'requested_at'              => $locked->requested_at ?? now(),
            ]);

            $this->log($locked, $appeal ? AuthorizationEventType::Appealed : AuthorizationEventType::Requested, []);

            return $locked->refresh();
        });
    }

    /** Retira o pedido enviado (aguardando → rascunho) para poder corrigi-lo. */
    public function withdraw(SurgicalCase $case, string $reason, ?int $expectedRevision = null): SurgicalCase
    {
        return DB::transaction(function () use ($case, $reason, $expectedRevision): SurgicalCase {
            $locked = $this->cases->lock((string) $case->getKey());

            $this->cases->assertRevision($locked, $expectedRevision);

            if ($locked->status !== SurgicalCaseStatus::AwaitingAuthorization) {
                throw SurgeryRuleException::state('surgery.errors.cannot_withdraw_in_status', ['status' => $locked->status->label()]);
            }

            $this->cases->changeStatus($locked, SurgicalCaseStatus::Draft, $reason, [
                'authorization_status' => AuthorizationStatus::NotRequested,
            ]);

            $this->log($locked, AuthorizationEventType::Cancelled, ['note' => $reason]);

            return $locked->refresh();
        });
    }

    // ── Resposta da operadora ───────────────────────────────────────────────

    /**
     * Registra um evento (resposta da operadora, dispensa, protocolo de envio
     * ou anotação). `$data`: type, occurred_at, authorization_number,
     * authorization_password, valid_until, authorized_items, denial_reason,
     * protocol_number, channel, note, tiss_guide_id e, só do TISS, `scope`.
     *
     * `scope` (`[{kind, id}]`) = os itens do pedido que ESTA resposta cobre. A operadora responde
     * por guia (internação = procedimentos; anexo de OPME = materiais): a resposta de uma guia
     * autoriza só os itens dela e o caso só passa a autorizado quando não sobra item sem resposta
     * — antes disso segue "aguardando" (a outra guia ainda pode ser enviada) e a resposta fica no
     * log como anotação com número, validade e itens. Sem `scope` (registro manual), a resposta
     * vale para tudo que ainda não foi respondido.
     *
     * @param array<string, mixed> $data
     */
    public function record(SurgicalCase $case, array $data, ?int $expectedRevision = null): SurgicalCase
    {
        $type = AuthorizationEventType::from((string) $data['type']);

        if (! in_array($type, self::RECORDABLE, true)) {
            throw SurgeryRuleException::invalid('surgery.errors.event_type_not_recordable');
        }

        return DB::transaction(function () use ($case, $data, $type, $expectedRevision): SurgicalCase {
            $locked = $this->cases->lock((string) $case->getKey());

            $this->cases->assertRevision($locked, $expectedRevision);

            $this->assertOccurredAt($data['occurred_at'] ?? null);

            match ($type) {
                AuthorizationEventType::Submitted           => $this->applySubmitted($locked, $data),
                AuthorizationEventType::Authorized          => $this->applyAuthorized($locked, $data, partial: false),
                AuthorizationEventType::PartiallyAuthorized => $this->applyAuthorized($locked, $data, partial: true),
                AuthorizationEventType::Denied              => $this->applyDenied($locked, $data),
                AuthorizationEventType::Waived              => $this->applyWaived($locked, $data),
                AuthorizationEventType::Note                => $this->applyNote($locked, $data),
                default                                     => throw SurgeryRuleException::invalid('surgery.errors.event_type_not_recordable'),
            };

            return $locked->refresh();
        });
    }

    /**
     * Job diário: autorização vencida pela validade. Autorizado/parcial volta a
     * "aguardando" (precisa de renovação, registrada como novo evento
     * `authorized`); cirurgia já AGENDADA continua agendada, mas o caso fica
     * com a autorização vencida (alerta na tela e conferência ao realizar).
     * Cada caso em transação própria, relido sob a trava.
     *
     * @return int quantos casos venceram agora
     */
    public function expireDue(?CarbonInterface $today = null): int
    {
        $today = ($today ?? now())->toDateString();
        $count = 0;

        $ids = SurgicalCase::query()
            ->withoutEntityScope()
            ->whereIn('authorization_status', [AuthorizationStatus::Authorized->value, AuthorizationStatus::PartiallyAuthorized->value])
            ->whereNotNull('authorization_valid_until')
            ->where('authorization_valid_until', '<', $today)
            ->whereIn('status', [
                SurgicalCaseStatus::Authorized->value,
                SurgicalCaseStatus::PartiallyAuthorized->value,
                SurgicalCaseStatus::Scheduled->value,
            ])
            ->pluck('id');

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $today): int {
                $locked = SurgicalCase::query()->withoutEntityScope()->whereKey($id)->lockForUpdate()->first();

                if ($locked === null
                    || ! in_array($locked->authorization_status, [AuthorizationStatus::Authorized, AuthorizationStatus::PartiallyAuthorized], true)
                    || $locked->authorization_valid_until === null
                    || $locked->authorization_valid_until->toDateString() >= $today
                    || ! in_array($locked->status, [SurgicalCaseStatus::Authorized, SurgicalCaseStatus::PartiallyAuthorized, SurgicalCaseStatus::Scheduled], true)
                ) {
                    return 0; // mudou desde a listagem (renovada, realizada, cancelada)
                }

                if ($locked->status === SurgicalCaseStatus::Scheduled) {
                    $locked->forceFill(['authorization_status' => AuthorizationStatus::Expired])->save();
                    $this->bumpRevision($locked);
                } else {
                    $this->cases->changeStatus($locked, SurgicalCaseStatus::AwaitingAuthorization, 'authorization_expired', [
                        'authorization_status' => AuthorizationStatus::Expired,
                    ]);
                }

                $this->log($locked, AuthorizationEventType::Expired, [
                    'valid_until' => $locked->authorization_valid_until,
                    'note'        => 'authorization_expired',
                ], recordedBy: null);

                return 1;
            });
        }

        return $count;
    }

    // ── Aplicação de cada evento ────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function applySubmitted(SurgicalCase $locked, array $data): void
    {
        $this->requireStatus($locked, [SurgicalCaseStatus::AwaitingAuthorization]);

        $this->log($locked, AuthorizationEventType::Submitted, $data);
        $this->bumpRevision($locked);
    }

    /** @param array<string, mixed> $data */
    private function applyAuthorized(SurgicalCase $locked, array $data, bool $partial): void
    {
        $this->requireStatus($locked, [SurgicalCaseStatus::AwaitingAuthorization]);

        if (blank($data['authorization_number'] ?? null)) {
            throw ValidationException::withMessages(['authorization_number' => (string) __('surgery.validation.authorization_number_required')]);
        }

        if (filled($data['valid_until'] ?? null) && Carbon::parse((string) $data['valid_until'])->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['valid_until' => (string) __('surgery.validation.valid_until_past')]);
        }

        // Rodada nova: autorização vencida que está sendo renovada. O que a operadora respondeu
        // na rodada anterior (quantidades, número, validade) não vale mais.
        $newRound = $locked->authorization_status !== AuthorizationStatus::Pending;

        $items   = $this->requestItems($locked, keepAnswers: ! $newRound);
        $covered = $this->coveredKeys($items, $data['scope'] ?? null);
        $answers = $this->answersFor($items, $covered, $data['authorized_items'] ?? null, $partial);

        if ($newRound) {
            $this->clearAuthorizedQuantities($locked);
        }

        $this->storeAuthorizedQuantities($locked, $items, $answers);

        foreach ($answers as $key => $quantity) {
            $items[$key]['answered'] = $quantity;
        }

        $fields = $this->mergedAuthorization($locked, $data, $newRound);
        $open   = array_filter($items, fn (array $item) => $item['answered'] === null);

        // Ainda há item sem resposta (a outra guia do pedido): o caso segue aguardando. A resposta
        // fica no log como anotação — não é uma decisão sobre o caso, e o aviso ao paciente e os
        // indicadores contam o desfecho do caso uma vez só, quando ele fecha.
        if ($open !== []) {
            $locked->forceFill([...$fields, 'authorization_status' => AuthorizationStatus::Pending]);
            $this->bumpRevision($locked);

            $this->log($locked, AuthorizationEventType::Note, [
                ...$data,
                'authorized_items' => $this->snapshot($items, array_keys($answers)),
                'note'             => $this->partialAnswerNote($items, array_keys($answers), array_keys($open), $partial, $data),
            ]);

            return;
        }

        $full = collect($items)->every(fn (array $item) => $item['answered'] === $item['quantity']);

        $this->cases->changeStatus($locked, $full ? SurgicalCaseStatus::Authorized : SurgicalCaseStatus::PartiallyAuthorized, null, [
            ...$fields,
            'authorization_status' => $full ? AuthorizationStatus::Authorized : AuthorizationStatus::PartiallyAuthorized,
            'authorized_at'        => now(),
        ]);

        // O evento leva o desfecho do CASO (todos os itens, com o que ficou autorizado).
        $this->log($locked, $full ? AuthorizationEventType::Authorized : AuthorizationEventType::PartiallyAuthorized, [
            ...$data,
            'authorized_items' => $this->snapshot($items),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function applyDenied(SurgicalCase $locked, array $data): void
    {
        $this->requireStatus($locked, [SurgicalCaseStatus::AwaitingAuthorization]);

        if (mb_strlen(trim((string) ($data['denial_reason'] ?? ''))) < 3) {
            throw ValidationException::withMessages(['denial_reason' => (string) __('surgery.validation.denial_reason_required')]);
        }

        $this->cases->changeStatus($locked, SurgicalCaseStatus::Denied, (string) $data['denial_reason'], [
            'authorization_status'      => AuthorizationStatus::Denied,
            'authorization_number'      => null,
            'authorization_password'    => null,
            'authorization_valid_until' => null,
        ]);

        $this->log($locked, AuthorizationEventType::Denied, $data);
    }

    /** @param array<string, mixed> $data */
    private function applyWaived(SurgicalCase $locked, array $data): void
    {
        $this->requireStatus($locked, [SurgicalCaseStatus::Draft, SurgicalCaseStatus::AwaitingAuthorization, SurgicalCaseStatus::Denied]);

        // Dispensar é para quem não passa por operadora (particular). Com convênio real, a
        // dispensa não pode apagar uma negativa da operadora.
        if ($locked->requiresInsurer() && $locked->status === SurgicalCaseStatus::Denied) {
            throw SurgeryRuleException::state('surgery.errors.cannot_waive_denied_insurer');
        }

        if (mb_strlen(trim((string) ($data['note'] ?? ''))) < 3) {
            throw ValidationException::withMessages(['note' => (string) __('surgery.validation.waiver_reason_required')]);
        }

        $this->assertRequestComplete($locked);

        // Todo item pedido fica autorizado: a dispensa cobre o pedido inteiro.
        $items = $this->requestItems($locked, keepAnswers: false);

        $this->storeAuthorizedQuantities($locked, $items, array_map(fn (array $item) => $item['quantity'], $items));

        $this->cases->changeStatus($locked, SurgicalCaseStatus::Authorized, (string) $data['note'], [
            'authorization_status'      => AuthorizationStatus::Waived,
            'authorization_number'      => null,
            'authorization_password'    => null,
            'authorization_valid_until' => null,
            'requested_at'              => $locked->requested_at ?? now(),
            'authorized_at'             => now(),
        ]);

        $this->log($locked, AuthorizationEventType::Waived, $data);
    }

    /** @param array<string, mixed> $data */
    private function applyNote(SurgicalCase $locked, array $data): void
    {
        if ($locked->status->isFinal()) {
            throw SurgeryRuleException::state('surgery.errors.case_closed');
        }

        if (blank($data['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => (string) __('surgery.validation.note_required')]);
        }

        $this->log($locked, AuthorizationEventType::Note, $data);
        $this->bumpRevision($locked);
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    /** @param list<SurgicalCaseStatus> $allowed */
    private function requireStatus(SurgicalCase $locked, array $allowed): void
    {
        if (! in_array($locked->status, $allowed, true)) {
            throw SurgeryRuleException::state('surgery.errors.authorization_event_not_allowed', ['status' => $locked->status->label()]);
        }
    }

    private function assertOccurredAt(mixed $occurredAt): void
    {
        if (filled($occurredAt) && Carbon::parse((string) $occurredAt)->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['occurred_at' => (string) __('surgery.validation.occurred_at_future')]);
        }
    }

    /**
     * Itens do pedido (procedimentos, depois materiais), cada um com o que foi pedido e o que a
     * operadora já respondeu (`answered`; null = sem resposta nesta rodada).
     *
     * @return array<string, array{kind: string, id: string, label: string, quantity: int, answered: int|null}>
     */
    private function requestItems(SurgicalCase $locked, bool $keepAnswers): array
    {
        $items = [];

        foreach ($locked->procedures as $p) {
            $items['procedure:' . $p->getKey()] = [
                'kind'     => 'procedure',
                'id'       => (string) $p->getKey(),
                'label'    => (string) $p->description,
                'quantity' => (int) $p->quantity,
                'answered' => $keepAnswers ? $p->authorized_quantity : null,
            ];
        }

        foreach ($locked->materials as $m) {
            $items['material:' . $m->getKey()] = [
                'kind'     => 'material',
                'id'       => (string) $m->getKey(),
                'label'    => (string) $m->description,
                'quantity' => (int) $m->quantity,
                'answered' => $keepAnswers ? $m->authorized_quantity : null,
            ];
        }

        return $items;
    }

    /**
     * Chaves dos itens que esta resposta cobre: os do `scope` (resposta de UMA guia) ou, sem
     * `scope` (registro manual), tudo que ainda não foi respondido.
     *
     * @param array<string, array<string, mixed>>   $items
     * @param array<int, array<string, mixed>>|null $scope
     *
     * @return list<string>
     */
    private function coveredKeys(array $items, ?array $scope): array
    {
        if ($scope === null) {
            $open = array_keys(array_filter($items, fn (array $item) => $item['answered'] === null));

            if ($open === []) {
                throw SurgeryRuleException::state('surgery.errors.authorization_nothing_pending');
            }

            return $open;
        }

        $keys = [];

        foreach ($scope as $row) {
            $key = ($row['kind'] ?? '') . ':' . ($row['id'] ?? '');

            if (isset($items[$key])) {
                $keys[$key] = $key;
            }
        }

        if ($keys === []) {
            throw SurgeryRuleException::invalid('surgery.errors.authorization_scope_unmatched');
        }

        return array_values($keys);
    }

    /**
     * Quantidade autorizada de cada item coberto. Total = o que foi pedido. Parcial exige a
     * lista, ao menos um item autorizado e ao menos um abaixo do pedido (entre os itens que esta
     * resposta cobre; o que já tinha resposta não se muda e uma citação dele é ignorada).
     *
     * @param array<string, array<string, mixed>>   $items
     * @param list<string>                          $covered
     * @param array<int, array<string, mixed>>|null $given
     *
     * @return array<string, int>
     */
    private function answersFor(array $items, array $covered, ?array $given, bool $partial): array
    {
        $answers = [];

        if (! $partial) {
            foreach ($covered as $key) {
                $answers[$key] = $items[$key]['quantity'];
            }

            return $answers;
        }

        $stated = [];

        foreach ((array) $given as $row) {
            $key = ($row['kind'] ?? '') . ':' . ($row['id'] ?? '');

            if (! isset($items[$key])) {
                throw ValidationException::withMessages(['authorized_items' => (string) __('surgery.validation.authorized_item_unknown')]);
            }

            $quantity = max(0, (int) ($row['quantity'] ?? 0));

            if ($quantity > $items[$key]['quantity']) {
                throw ValidationException::withMessages(['authorized_items' => (string) __('surgery.validation.authorized_above_requested')]);
            }

            $stated[$key] = $quantity;
        }

        // Item coberto que a lista não cita foi negado (quantidade 0).
        foreach ($covered as $key) {
            $answers[$key] = $stated[$key] ?? 0;
        }

        $anyAuthorized = collect($answers)->contains(fn (int $quantity) => $quantity > 0);
        $anyShort      = collect($answers)->contains(fn (int $quantity, string $key) => $quantity < $items[$key]['quantity']);

        if (! $anyAuthorized) {
            throw ValidationException::withMessages(['authorized_items' => (string) __('surgery.validation.partial_needs_authorized_item')]);
        }

        if (! $anyShort) {
            throw ValidationException::withMessages(['authorized_items' => (string) __('surgery.validation.partial_needs_denied_item')]);
        }

        return $answers;
    }

    /**
     * Número, senha e validade do CASO. Dentro da rodada, a resposta de cada guia se soma às
     * anteriores (números distintos lado a lado; vale a MENOR validade — a cirurgia precisa de
     * todas as autorizações vigentes). Rodada nova substitui.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mergedAuthorization(SurgicalCase $locked, array $data, bool $newRound): array
    {
        $number     = trim((string) $data['authorization_number']);
        $password   = filled($data['authorization_password'] ?? null) ? trim((string) $data['authorization_password']) : null;
        $validUntil = filled($data['valid_until'] ?? null) ? Carbon::parse((string) $data['valid_until'])->toDateString() : null;

        if (! $newRound) {
            $number     = $this->joinDistinct($locked->authorization_number, $number);
            $password   = $this->joinDistinct($locked->authorization_password, $password);
            $validUntil = $this->earliestDate($locked->authorization_valid_until?->toDateString(), $validUntil);
        }

        return [
            'authorization_number'      => $number,
            'authorization_password'    => $password,
            'authorization_valid_until' => $validUntil,
        ];
    }

    /** "A / B" sem repetir; a coluna tem 40 posições — se não couber, fica o que já estava (o log guarda cada número). */
    private function joinDistinct(?string $current, ?string $new): ?string
    {
        $parts = array_values(array_filter(array_map('trim', explode(' / ', (string) $current))));

        if (filled($new) && ! in_array($new, $parts, true)) {
            $joined = implode(' / ', [...$parts, $new]);

            if (mb_strlen($joined) <= 40) {
                $parts[] = $new;
            }
        }

        return $parts === [] ? null : implode(' / ', $parts);
    }

    private function earliestDate(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return min($a, $b); // datas ISO (Y-m-d) ordenam como texto
    }

    /**
     * `[{kind, id, quantity}]` dos itens (todos, ou só as chaves dadas), com a quantidade
     * respondida — o formato do log.
     *
     * @param array<string, array<string, mixed>> $items
     * @param list<string>|null                   $only
     *
     * @return list<array{kind: string, id: string, quantity: int}>
     */
    private function snapshot(array $items, ?array $only = null): array
    {
        $rows = [];

        foreach ($items as $key => $item) {
            if ($only === null || in_array($key, $only, true)) {
                $rows[] = ['kind' => $item['kind'], 'id' => $item['id'], 'quantity' => (int) $item['answered']];
            }
        }

        return $rows;
    }

    /**
     * Texto da anotação de uma resposta que cobre só parte do pedido: o que foi respondido, com
     * quanto, e o que ainda espera resposta.
     *
     * @param array<string, array<string, mixed>> $items
     * @param list<string>                        $answered
     * @param list<string>                        $open
     * @param array<string, mixed>                $data
     */
    private function partialAnswerNote(array $items, array $answered, array $open, bool $partial, array $data): string
    {
        $describe = fn (string $key, bool $withQuantity) => Str::limit($items[$key]['label'], 60)
            . ($withQuantity ? ' (' . $items[$key]['answered'] . '/' . $items[$key]['quantity'] . ')' : '');

        $note = (string) __('surgery.authorization_scope.answered', [
            'decision' => ($partial ? AuthorizationEventType::PartiallyAuthorized : AuthorizationEventType::Authorized)->label(),
            'answered' => implode(', ', array_map(fn (string $key) => $describe($key, true), $answered)),
            'pending'  => implode(', ', array_map(fn (string $key) => $describe($key, false), $open)),
        ]);

        return trim($note . ' ' . trim((string) ($data['note'] ?? '')));
    }

    /** Zera a resposta da operadora em todos os itens do caso (rodada nova). */
    private function clearAuthorizedQuantities(SurgicalCase $locked): void
    {
        foreach ([SurgicalCaseProcedure::class, SurgicalCaseMaterial::class] as $model) {
            $model::query()->where('surgical_case_id', $locked->getKey())->update(['authorized_quantity' => null]);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<string, int>                  $answers chave do item => quantidade autorizada
     */
    private function storeAuthorizedQuantities(SurgicalCase $locked, array $items, array $answers): void
    {
        foreach ($answers as $key => $quantity) {
            $model = $items[$key]['kind'] === 'procedure' ? SurgicalCaseProcedure::class : SurgicalCaseMaterial::class;

            $model::query()
                ->where('surgical_case_id', $locked->getKey())
                ->whereKey($items[$key]['id'])
                ->update(['authorized_quantity' => $quantity]);
        }
    }

    /**
     * O que a operadora precisa receber para analisar: procedimento, TUSS (com
     * convênio), diagnóstico e — para internação ou OPME — local, diárias e a
     * justificativa técnica do material. Devolve TODOS os problemas de uma vez
     * (a tela mostra o que falta antes de o usuário tentar enviar).
     *
     * @return list<string>
     */
    public function requestProblems(SurgicalCase $case): array
    {
        $case->loadMissing(['procedures', 'materials', 'covenant']);

        $problems = [];

        if ($case->procedures->isEmpty()) {
            $problems[] = (string) __('surgery.validation.procedure_required');
        }

        if ($case->requiresInsurer() && $case->procedures->contains(fn ($p) => blank($p->tuss_code))) {
            $problems[] = (string) __('surgery.validation.tuss_required_with_covenant');
        }

        if ($case->regime->requiresInpatientRequest()) {
            if ($case->surgery_facility_id === null) {
                $problems[] = (string) __('surgery.validation.facility_required_for_regime');
            }

            if ($case->days_requested < 1) {
                $problems[] = (string) __('surgery.validation.days_required_for_regime');
            }

            if (count((array) $case->cid_codes) < 1) {
                $problems[] = (string) __('surgery.validation.cid_required_for_regime');
            }
        }

        if ($case->needs_opme) {
            if ($case->materials->isEmpty()) {
                $problems[] = (string) __('surgery.validation.materials_required_with_opme');
            } elseif ($case->materials->contains(fn ($m) => blank($m->technical_justification))) {
                $problems[] = (string) __('surgery.validation.material_justification_required');
            }
        }

        return $problems;
    }

    private function assertRequestComplete(SurgicalCase $locked): void
    {
        $locked->unsetRelation('procedures')->unsetRelation('materials');

        $problems = $this->requestProblems($locked);

        if ($problems !== []) {
            throw ValidationException::withMessages(['request' => $problems]);
        }
    }

    private function bumpRevision(SurgicalCase $locked): void
    {
        $locked->revision = $locked->revision + 1;
        $locked->save();
    }

    /** @param array<string, mixed> $data */
    private function log(SurgicalCase $locked, AuthorizationEventType $type, array $data, string|false|null $recordedBy = false): SurgicalAuthorizationEvent
    {
        $event = SurgicalAuthorizationEvent::query()->create([
            'entity_id'              => $locked->entity_id,
            'surgical_case_id'       => $locked->getKey(),
            'type'                   => $type,
            'occurred_at'            => filled($data['occurred_at'] ?? null) ? $data['occurred_at'] : now(),
            'authorization_number'   => $data['authorization_number'] ?? null,
            'authorization_password' => $data['authorization_password'] ?? null,
            'valid_until'            => $data['valid_until'] ?? null,
            'authorized_items'       => $data['authorized_items'] ?? null,
            'denial_reason'          => $data['denial_reason'] ?? null,
            'protocol_number'        => $data['protocol_number'] ?? null,
            'channel'                => $data['channel'] ?? null,
            'tiss_guide_id'          => $data['tiss_guide_id'] ?? null,
            'note'                   => $data['note'] ?? null,
            'recorded_by'            => $recordedBy === false ? Auth::id() : $recordedBy,
        ]);

        // Avisos e indicadores rodam depois do commit, nunca sob a trava do caso.
        DB::afterCommit(fn () => AuthorizationEventRecorded::dispatch($locked->refresh(), $event));

        return $event;
    }
}
