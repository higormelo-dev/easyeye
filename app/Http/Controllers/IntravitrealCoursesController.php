<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Recall\Intravitreal\Enums\{AuthorizationStatus, CourseRegimen, CourseStatus};
use App\Domains\Recall\Intravitreal\Exceptions\IntravitrealException;
use App\Domains\Recall\Intravitreal\Models\IntravitrealCourse;
use App\Domains\Recall\Intravitreal\Services\{IntravitrealApplicationService, IntravitrealCourseService};
use App\Enums\DataAccessPurpose;
use App\Http\Requests\Intravitreal\{CloseIntravitrealCourseRequest, ScheduleNextIntravitrealRequest};
use App\Models\Patient;
use App\Traits\LogsDataAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Cursos de injeções intravítreas (anti-VEGF) do paciente (Fase 6, ADR 0002, item 10). Leitura segue o grupo do
 * prontuário (admin/médico/secretária, como a lista de procedimentos); escrita — encerrar o curso e agendar a próxima
 * aplicação — é ato do médico (Gate IssueReport no FormRequest). A EXECUÇÃO de cada aplicação entra pelo `markDone` do
 * procedimento (MedicalRecordProceduresController), na mesma transação.
 */
class IntravitrealCoursesController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly IntravitrealCourseService $courses,
        private readonly IntravitrealApplicationService $applications,
    ) {
    }

    public function index(Patient $patient): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        abort_unless((string) $patient->entity_id === $entityId, 404);

        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        return response()->json(['data' => [
            'courses'  => $this->courses->forPatient($entityId, (string) $patient->id),
            'drugs'    => $this->drugs(),
            'regimens' => array_map(static fn (CourseRegimen $regimen): array => ['value' => $regimen->value, 'label' => $regimen->label()], CourseRegimen::cases()),
            'statuses' => array_map(static fn (AuthorizationStatus $status): array => ['value' => $status->value, 'label' => $status->label()], AuthorizationStatus::cases()),
            'weeks'    => ['min' => IntravitrealApplicationService::INTERVAL_MIN, 'max' => IntravitrealApplicationService::INTERVAL_MAX],
            't'        => trans('recall_course'),
        ]]);
    }

    public function close(CloseIntravitrealCourseRequest $request, Patient $patient, string $course): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        $this->assertOwns($patient, $course, $entityId);

        try {
            $closed = $this->courses->close($entityId, $course, CourseStatus::from((string) $request->validated('status')), (string) $request->validated('reason'), $request->user());
        } catch (IntravitrealException $e) {
            return $this->refused($e);
        }

        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        return response()->json(['message' => __('recall_course.done.closed'), 'data' => ['id' => (string) $closed->id, 'status' => $closed->status->value]]);
    }

    public function scheduleNext(ScheduleNextIntravitrealRequest $request, Patient $patient, string $course): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        $this->assertOwns($patient, $course, $entityId);

        $result = $this->applications->scheduleNext($entityId, $course, CarbonImmutable::parse((string) $request->validated('date_time')));

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        return response()->json(['message' => __('recall_course.done.scheduled'), 'data' => ['schedule_id' => $result['schedule_id']]]);
    }

    /** O curso é deste paciente e desta clínica (outra clínica ou outro paciente: não existe). */
    private function assertOwns(Patient $patient, string $courseId, string $entityId): void
    {
        abort_unless((string) $patient->entity_id === $entityId, 404);

        $owns = IntravitrealCourse::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patient->id)
            ->whereKey($courseId)
            ->exists();

        abort_unless($owns, 404);
    }

    private function refused(IntravitrealException $e): JsonResponse
    {
        return response()->json([
            'message' => __('recall_course.errors.' . $e->reason()),
            'errors'  => $e->field() !== null ? [$e->field() => [__('recall_course.errors.' . $e->reason())]] : null,
        ], 422);
    }

    /** @return list<array{value: string, label: string}> */
    private function drugs(): array
    {
        return array_values(array_map(
            static fn (string $key): array => ['value' => $key, 'label' => (string) __('recall_course.drugs.' . $key)],
            array_keys((array) config('recall.intravitreal.drugs', [])),
        ));
    }
}
