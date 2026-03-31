<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class BaseSettingService
{
    abstract protected function modelClass(): string;

    public function create(FormRequest $request): Model
    {
        return DB::transaction(fn () => $this->findOrCreate($request));
    }

    public function update(Model $record, FormRequest $request): Model
    {
        return DB::transaction(function () use ($record, $request) {
            $record->update($this->getUpdateData($request));

            return $record;
        });
    }

    public function all(): \Illuminate\Database\Eloquent\Collection
    {
        $class = $this->modelClass();

        return $class::query()
            ->withTrashed()
            ->where(fn ($q) => $q
                ->where('entity_id', session()->get('selected_entity_id'))
                ->orWhereNull('entity_id')
            )
            ->get();
    }

    public function findByIdOrCode(string $idOrCode): Model
    {
        $class = $this->modelClass();

        return $class::query()
            ->withTrashed()
            ->where(fn ($q) => $q
                ->where('entity_id', session()->get('selected_entity_id'))
                ->orWhereNull('entity_id')
            )
            ->when(
                Str::isUuid($idOrCode),
                fn ($q) => $q->where('id', $idOrCode),
                fn ($q) => $q->where('code', $idOrCode)
            )
            ->firstOrFail();
    }

    protected function getCreateData(FormRequest $request): array
    {
        return ['name' => $request->name];
    }

    protected function getUpdateData(FormRequest $request): array
    {
        $data = [];

        if ($request->has('name')) {
            $data['name'] = $request->name;
        }

        if ($request->has('active')) {
            $data['active'] = $request->boolean('active');
        }

        return $data;
    }

    protected function findOrCreate(FormRequest $request): Model
    {
        $class = $this->modelClass();

        $existing = $class::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('name', $request->name)
            ->first();

        $data = $this->getCreateData($request);

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($data);

            return $existing->fresh();
        }

        return $class::create(array_merge($data, [
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]));
    }
}
