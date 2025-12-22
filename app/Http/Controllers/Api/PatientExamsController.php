<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PatientExamRequest;
use App\Http\Resources\PatientExamResource;
use App\Models\{EntityIntegrator, PatientExam};
use Illuminate\Http\{JsonResponse};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\RandomException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PatientExamsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected PatientExam $model;

    public function __construct(PatientExam $patientExam)
    {
        $this->model = $patientExam;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(string $patientId)
    {
        $integrator = $this->getAuthenticatedIntegrator();

        if (!$integrator) {
            return $this->unauthorizedResponse();
        }

        $patientExams = $this->model->query()
            ->join('patients', 'patient_exams.patient_id', '=', 'patients.id')
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->join('entities', 'patients.entity_id', '=', 'entities.id')
            ->where('patients.id', $patientId)
            ->where('patients.entity_id', $integrator->entity_id);

        if (request()->has('search')) {
            $patientExams = $patientExams->where(function ($query) {
                $query->where('patients.code', 'like', '%' . request()->search . '%')
                    ->orWhere('people.name', 'like', '%' . request()->search . '%');
            });
        }

        $patientExams = $patientExams->paginate(request()->get('per_page', 10));

        return PatientExamResource::collection($patientExams);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PatientExamRequest $request, string $patientId): PatientExamResource|JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (!$integrator) {
                return $this->unauthorizedResponse();
            }

            $data = collect($request->validated())->merge([
                'patient_id'  => $patientId,
                'doctor_id'   => $request->doctor_id ?? null,
                'schedule_id' => $request->schedule_id ?? null,
                'code'        => $this->generateUniqueExamCode($patientId),
                'active'      => true,
            ]);

            $uuid      = Str::uuid();
            $timestamp = time();
            $extension = $request->file('archive')->getClientOriginalExtension();

            $fileName = "{$timestamp}_{$uuid}.{$extension}";
            $uploaded = Storage::disk('s3')
                ->put(
                    "{$integrator->entity_id}/{$patientId}/exams/{$fileName}",
                    file_get_contents($request->file('archive')),
                    'public'
                );

            if ($uploaded) {
                $data = $data->merge([
                    'archive' => "{$integrator->entity_id}/{$patientId}/exams/{$fileName}",
                ]);
                $exam = $this->model->create($data->toArray());

                return new PatientExamResource($exam);
            }

            return response()->json(
                ['message' => 'File upload failed.'],
                HttpResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $patientId, string $id): PatientExamResource|JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (!$integrator) {
                return $this->unauthorizedResponse();
            }

            $exam = $this->findPatientForIntegrator($patientId, $id);

            if (!$exam) {
                return $this->notFoundResponse();
            }

            return new PatientExamResource($exam);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PatientExamRequest $request, string $patientId, string $id): PatientExamResource|JsonResponse
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (!$integrator) {
                return $this->unauthorizedResponse();
            }

            $exam = $this->findPatientForIntegrator($patientId, $id);

            if (!$exam) {
                return $this->notFoundResponse();
            }

            if ($exam->archive) {
                Storage::disk('s3')->delete($exam->archive);
            }

            $data = collect($request->validated())->merge([
                'patient_id'  => $patientId,
                'doctor_id'   => $request->doctor_id ?? null,
                'schedule_id' => $request->schedule_id ?? null,
                'code'        => $this->generateUniqueExamCode($patientId),
                'active'      => true,
            ]);
            $uuid      = Str::uuid();
            $timestamp = time();
            $extension = $request->archive->getClientOriginalExtension();
            $fileName  = "{$timestamp}_{$uuid}.{$extension}";
            $uploaded  = Storage::disk('s3')
                ->put(
                    "{$integrator->entity_id}/{$patientId}/exams/{$fileName}",
                    file_get_contents($request->file('archive')),
                    'public'
                );

            if ($uploaded) {
                $data = $data->merge([
                    'archive' => "{$integrator->entity_id}/{$patientId}/exams/{$fileName}",
                ]);
                $exam->update($data->toArray());
                $exam->refresh();

                return new PatientExamResource($exam);
            }

            return response()->json(
                ['message' => 'File upload failed.'],
                HttpResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $patientId, string $id)
    {
        try {
            $integrator = $this->getAuthenticatedIntegrator();

            if (!$integrator) {
                return $this->unauthorizedResponse();
            }

            $exam = $this->findPatientForIntegrator($patientId, $id);

            if (!$exam) {
                return $this->notFoundResponse();
            }

            if ($exam->archive !== null) {
                Storage::disk('s3')->delete($exam->archive);
            }

            $exam->delete();

            return response()->json([], HttpResponse::HTTP_NO_CONTENT);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Get authenticated integrator by bearer token
     */
    private function getAuthenticatedIntegrator(): ?EntityIntegrator
    {
        return EntityIntegrator::query()
            ->where('token_session', request()->bearerToken())
            ->first();
    }

    /**
     * Find patient for specific integrator
     */
    private function findPatientForIntegrator(string $patientId, string $id): ?PatientExam
    {
        return $this->model->query()
            ->where('patient_id', $patientId)
            ->where('id', $id)
            ->first();
    }

    /**
     * Generate unique exam code
     *
     * @throws RandomException
     */
    private function generateUniqueExamCode(string $patientId): string
    {
        $maxAttempts = 10;
        $attempts    = 0;

        do {
            $code = 'EXAM-' . substr(str_replace('-', '', Str::uuid()), 0, 12);

            $attempts++;

            $exists = $this->model->query()->where('patient_id', $patientId)
                ->where('code', $code)->exists();

            if ($attempts >= $maxAttempts && $exists) {
                $code   = 'EXAM-' . time() . '-' . random_int(1000, 9999);
                $exists = $this->model->query()->where('patient_id', $patientId)
                    ->where('code', $code)->exists();
            }

        } while ($exists && $attempts < $maxAttempts);

        if ($exists) {
            $code = 'EXAM-' . substr(str_replace('-', '', Str::uuid()), 0, 12);
        }

        return strtoupper($code);
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json(['message' => 'Not authorized.'], HttpResponse::HTTP_UNAUTHORIZED);
    }

    /**
     * Return not found response
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json(['message' => 'Exam not found.'], HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Return server error response
     */
    private function serverErrorResponse(): JsonResponse
    {
        return response()->json(['message' => 'An error occurred.'], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
