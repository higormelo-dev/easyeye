<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExamTypeResource;
use App\Models\ExamType;
use Illuminate\Support\Str;

class ExamTypesController extends Controller
{
    protected ExamType $model;

    public function __construct(ExamType $examType)
    {
        $this->model = $examType;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $integrator = request()->attributes->get('integrator');

        $examTypes = $this->model->query()
            ->with('entity')
            ->where(function ($query) use ($integrator) {
                $query->whereNull('entity_id')
                    ->orWhere('entity_id', $integrator->user->entity_id);
            });

        if (request()->has('search')) {
            $examTypes = $examTypes->where(function ($query) {
                $query->when(
                    is_numeric(request()->search),
                    static fn ($q) => $q->where('category', request()->search),
                    static fn ($q) => $q->where(
                        'exam_types.code',
                        'like',
                        '%' . request()->search . '%',
                    )->orWhere('exam_types.name', 'like', '%' . request()->search . '%'),
                );
            });
        }

        $examTypes = $examTypes->paginate($this->perPage());

        return ExamTypeResource::collection($examTypes);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): ExamTypeResource
    {
        $integrator = request()->attributes->get('integrator');

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id', $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('ETP-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        $examType = $this->model->query()
            ->with('entity')
            ->where(function ($q) use ($integrator) {
                $q->whereNull('entity_id')
                    ->orWhere('entity_id', $integrator->user->entity_id);
            })
            ->where($column, $value)
            ->firstOrFail();

        return new ExamTypeResource($examType);
    }
}
