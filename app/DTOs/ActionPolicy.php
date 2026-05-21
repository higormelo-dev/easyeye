<?php

namespace App\DTOs;

/**
 * Visibilidade e modo de ações disponíveis para um registro na UI.
 *
 * Resolve ownership multi-tenant + soft-delete + flag global em um modo
 * único. Consumido pelos controllers em listings Inertia (spread em toArray())
 * e pelos componentes Vue (v-if em record.mode).
 */
final readonly class ActionPolicy
{
    public const MODE_RESTORE = 'restore';

    public const MODE_VIEW_ONLY = 'view_only';

    public const MODE_FULL = 'full';

    public const MODE_NONE = 'none';

    public function __construct(
        public string $mode,
        public bool $active,
        public bool $isOwned,
        public bool $isGlobal,
        public bool $deleted,
    ) {
    }

    /**
     * Resolve políticas a partir do registro (escopo tenant).
     *
     * @param object      $record   Model com atributos entity_id (nullable), active, deleted_at
     * @param string|null $entityId Entidade selecionada na sessão (null = manager/global)
     */
    public static function from(object $record, ?string $entityId): self
    {
        $recordEntityId = data_get($record, 'entity_id');
        $isOwned        = $recordEntityId !== null && $recordEntityId === $entityId;
        $isGlobal       = $recordEntityId === null;
        $deleted        = ! blank(data_get($record, 'deleted_at'));
        $active         = (bool) data_get($record, 'active', true);

        $mode = match (true) {
            $deleted && $isOwned    => self::MODE_RESTORE,
            $isGlobal && ! $deleted => self::MODE_VIEW_ONLY,
            $isOwned && ! $deleted  => self::MODE_FULL,
            default                 => self::MODE_NONE,
        };

        return new self(
            mode: $mode,
            active: $active,
            isOwned: $isOwned,
            isGlobal: $isGlobal,
            deleted: $deleted,
        );
    }

    /**
     * Resolve políticas para escopo manager (sem entity_id; manager controla tudo).
     *
     * Usar para domínios manager-level (Plans, Subscriptions, ReportSettings global).
     */
    public static function forManager(object $record): self
    {
        $deleted = ! blank(data_get($record, 'deleted_at'));
        $active  = (bool) data_get($record, 'active', true);

        $mode = $deleted ? self::MODE_RESTORE : self::MODE_FULL;

        return new self(
            mode: $mode,
            active: $active,
            isOwned: true,
            isGlobal: false,
            deleted: $deleted,
        );
    }

    /**
     * Payload serializável para o frontend (cards JSON).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode'      => $this->mode,
            'active'    => $this->active,
            'is_owned'  => $this->isOwned,
            'is_global' => $this->isGlobal,
            'deleted'   => $this->deleted,
        ];
    }
}
