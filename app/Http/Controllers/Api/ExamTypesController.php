<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExamTypeResource;
use App\Models\ExamType;
use Illuminate\Support\Str;

class ExamTypesController extends Controller
{
    /**
     * Instance of the standard model.
     */
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
                        '%' . request()->search . '%'
                    )->orWhere('exam_types.name', 'like', '%' . request()->search . '%')
                );
            });
        }

        $examTypes = $examTypes->paginate(min((int) request()->get('per_page', 10), request()->attributes->get('api_per_page_limit', 100)));

        return ExamTypeResource::collection($examTypes);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): ExamTypeResource
    {
        $integrator = request()->attributes->get('integrator');

        $examType = $this->model->query()
            ->with('entity')
            ->where(function ($query) use ($integrator) {
                $query->whereNull('entity_id')
                    ->orWhere('entity_id', $integrator->user->entity_id);
            })
            ->when(
                Str::isUuid($idOrCode),
                static fn ($q) => $q->where('id', $idOrCode),
                static fn ($q) => $q->where('code', $idOrCode)
            )
            ->firstOrFail();

        return new ExamTypeResource($examType);
    }
}
