<?php

namespace App\Http\Controllers;

use App\Models\{Doctor, Entity, Patient, PatientExam};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class EyeImagesController extends Controller
{
    public function index(Request $request): View
    {
        $entityId = session('selected_entity_id');

        $meta = [
            'title'            => __('dashboard.module_eye_images'),
            'breadcrumb_title' => false,
            'action'           => __('dashboard.module_eye_images'),
            'breadcrumbs'      => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => __('dashboard.module_eye_images'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        $doctors  = Doctor::with('person')
            ->whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->get(['id', 'person_id']);

        $patients = $this->queryPatients(entityId: $entityId, period: 'hoje');

        $entity = Entity::find($entityId, ['id', 'name', 'address', 'telephone', 'cellphone', 'email', 'logo']);

        return view('system.eye_images.index', compact('meta', 'doctors', 'patients', 'entity'));
    }

    public function search(Request $request): JsonResponse
    {
        $entityId = session('selected_entity_id');

        $patients = $this->queryPatients(
            entityId: $entityId,
            period:   $request->input('period', 'hoje'),
            doctorId: $request->input('doctor_id'),
        );

        return response()->json([
            'patients'    => $patients,
            'total_exams' => $patients->sum(fn ($p) => $p->exams->count()),
        ]);
    }

    public function patientExamUrls(Patient $patient): JsonResponse
    {
        $entityId = session('selected_entity_id');
        abort_unless($patient->entity_id === $entityId, 403);

        $urls = $patient->exams()
            ->whereNotNull('archive')
            ->get(['id', 'archive'])
            ->mapWithKeys(fn ($exam) => [
                $exam->id => Storage::disk('s3')->temporaryUrl($exam->archive, now()->addHours(2)),
            ]);

        return response()->json(['urls' => $urls]);
    }

    public function imageUrl(PatientExam $exam): JsonResponse
    {
        abort_unless($exam->archive, 404);

        $entityId = session('selected_entity_id');
        abort_unless(
            Patient::where('id', $exam->patient_id)->where('entity_id', $entityId)->exists(),
            403,
        );

        return response()->json([
            'url' => Storage::disk('s3')->temporaryUrl($exam->archive, now()->addHour()),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function queryPatients(
        string  $entityId,
        string  $period   = 'hoje',
        ?string $doctorId = null,
    ): Collection {
        $from = $period === 'hoje'
            ? now()->startOfDay()
            : now()->subDays(max((int) $period, 1))->startOfDay();

        $examFilter = function (Builder|Relation $q) use ($from, $doctorId): void {
            $q->where('created_at', '>=', $from);
            if ($doctorId) {
                $q->where('doctor_id', $doctorId);
            }
        };

        $patients = Patient::query()
            ->where('entity_id', $entityId)
            ->whereHas('exams', $examFilter)
            ->with([
                'person',
                'exams' => function (Builder|Relation $q) use ($examFilter): void {
                    $examFilter($q);
                    $q->with(['examType', 'doctor.person', 'equipment'])
                      ->orderByDesc('created_at');
                },
            ])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        $patients->each(fn ($p) => $p->exams->each->makeHidden(['archive_url']));

        return $patients;
    }
}
