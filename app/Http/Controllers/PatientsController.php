<?php

namespace App\Http\Controllers;

use App\DTOs\ActionPolicy;
use App\Enums\ClinicalSafety\AllergyCategory;
use App\Http\Requests\{PatientRequest, QuickStorePatientRequest};
use App\Http\Resources\PatientResource;
use App\Models\{Covenant, IrisType, Patient, People, SkinType};
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Services\{PatientDuplicateFinder, PatientService};
use App\Services\WhatsApp\WhatsAppService;
use App\Support\BrazilianFormat;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\{DB, Storage, Vite};
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

class PatientsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected Patient $model;

    protected PatientService $service;

    public function __construct(Patient $patient, PatientService $patientService)
    {
        $this->titleController = __('actions.sidemenu.patients');
        $this->model           = $patient;
        $this->service         = $patientService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $entityId = session('selected_entity_id');
        $search   = $request->string('search')->trim()->value();
        $sortBy   = $request->string('sort', 'created_at')->value();
        $sortDir  = $request->string('direction', 'desc')->value();

        $allowedSorts = ['created_at', 'full_name', 'code', 'cellphone'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = Patient::query()
            ->withTrashed()
            ->select('patients.*', 'people.full_name', 'people.gender', 'people.cellphone', 'people.whatsapp')
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where(
                fn ($q) => $q
                    ->where('patients.entity_id', $entityId)
                    ->orWhere(fn ($q) => $q->whereNull('patients.entity_id')->whereNull('patients.deleted_at')),
            );

        if ($search !== '') {
            $searchDigits = self::formattedNumberDigits($search);

            $query->where(
                fn ($q) => $q
                    ->whereLikeUnaccent('people.full_name', $search)
                    ->orWhereLikeUnaccent('patients.code', $search)
                    ->orWhereLikeUnaccent('people.cellphone', $search)
                    ->when($searchDigits, fn ($q, $digits) => $q->orWhereLikeUnaccent('people.cellphone', $digits)),
            );
        }

        $dbCol = match ($sortBy) {
            'full_name' => 'people.full_name',
            'cellphone' => 'people.cellphone',
            default     => "patients.{$sortBy}",
        };
        $query->orderBy($dbCol, $sortDir);

        $patients = $query->paginate(15)->withQueryString();

        return Inertia::render('Panel/Patients/Index', [
            'patients'      => $patients->through(fn ($p) => $this->toTableRow($p, $entityId)),
            'totalPatients' => fn () => Patient::where('entity_id', $entityId)->count(),
            // Só ativos, como na Agenda: vínculo novo com convênio inativo é
            // recusado (App\Rules\ActiveCovenant). O inativo que o paciente
            // já tem chega pelo editData (covenant_option) e aparece marcado.
            'covenants' => fn () => Covenant::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->toArray(),
            'skinTypes'       => fn () => SkinType::all()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->toArray(),
            'irisTypes'       => fn () => IrisType::all()->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values()->toArray(),
            'genders'         => People::$genders,
            'maritalStatuses' => People::$maritalStatuses,
            'statesOfBrazil'  => People::$statesOfBrazil,
            'filters'         => $request->only(['search', 'sort', 'direction']),
        ]);
    }

    private function toTableRow(Patient $p, string $entityId): array
    {
        $policy   = ActionPolicy::from($p, $entityId);
        $photoUrl = ($p->photo && Storage::disk('public')->exists('images/patient/' . $p->photo))
            ? asset('storage/images/patient/' . $p->photo)
            : Vite::asset('resources/img/system/team.png');

        return [
            'id'                  => $p->id,
            'code'                => $p->code,
            'full_name'           => $p->full_name,
            'gender'              => $p->gender,
            'gender_label'        => People::$genders[(int) $p->gender] ?? null,
            'cellphone'           => BrazilianFormat::phone($p->cellphone),
            'whatsapp'            => (bool) $p->whatsapp,
            'active'              => (bool) $p->active,
            'deleted_at'          => $p->deleted_at,
            'created_at'          => $p->created_at?->format('d/m/Y'),
            'photo_url'           => $photoUrl,
            'medical_records_url' => route('panel.patients.medicalrecords.index', $p->id),
            'mode'                => $policy->mode,
            'is_owned'            => $policy->isOwned,
            'is_global'           => $policy->isGlobal,
            'deleted'             => $policy->deleted,
        ];
    }

    /**
     * Return paginated patients as JSON for the card view.
     */
    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $patients = Patient::query()
            ->withTrashed()
            ->with(['person', 'covenant', 'covenantPlan', 'skinType', 'irisType'])
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where(function ($query) {
                $query->where('patients.entity_id', session()->get('selected_entity_id'))
                    ->orWhere(function ($q) {
                        $q->whereNull('patients.entity_id')
                            ->whereNull('patients.deleted_at');
                    });
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->whereLikeUnaccent('people.full_name', $search)
                        ->orWhereLikeUnaccent('patients.code', $search);
                });
            })
            ->select('patients.*')
            ->orderBy('patients.created_at', 'desc')
            ->paginate($perPage);

        $entityId = session()->get('selected_entity_id');

        $data = $patients->map(fn (Patient $p) => [
            'id'        => $p->id,
            'full_name' => $p->person->full_name,
            'code'      => $p->code,
            'age'       => $p->person->birth_date?->age
                ? $p->person->birth_date->age . ' ' . __('actions.years')
                : null,
            'photo_url' => $p->photo && Storage::disk('public')->exists('images/patient/' . $p->photo)
                ? asset('storage/images/patient/' . $p->photo)
                : Vite::asset('resources/img/system/team.png'),
            'skin'                => $p->skinType?->name,
            'iris'                => $p->irisType?->name,
            'covenant'            => $p->covenant?->name,
            'covenant_plan'       => $p->covenantPlan?->name,
            'medical_records_url' => route('panel.patients.medicalrecords.index', $p->id),
            ...ActionPolicy::from($p, $entityId)->toArray(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $patients->total(),
                'per_page'     => $patients->perPage(),
                'current_page' => $patients->currentPage(),
                'last_page'    => $patients->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PatientRequest $request): Application|RedirectResponse|Redirector|JsonResponse
    {
        $record        = $this->service->create($request);
        $messageReturn = $this->getCreateMessage();

        if (request()->wantsJson()) {
            return response()->json(
                [
                    'message' => $messageReturn,
                    'data'    => new PatientResource($record),
                ],
            );
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Display the specified resource (JSON only — viewed via modal drawer on index).
     */
    public function show(string $id): JsonResponse|RedirectResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (! request()->wantsJson()) {
            return redirect()->route('panel.patients.index');
        }

        $person   = $record->person;
        $photoUrl = ($record->photo && Storage::disk('public')->exists('images/patient/' . $record->photo))
            ? asset('storage/images/patient/' . $record->photo)
            : Vite::asset('resources/img/system/team.png');

        return response()->json([
            'data' => [
                'id'                  => $record->id,
                'code'                => $record->code,
                'import_code'         => $record->import_code,
                'covenant'            => $record->covenant?->name,
                'covenant_plan'       => $this->planDetails($record),
                'card_number'         => $record->card_number,
                'skin_type'           => $record->skinType?->name,
                'iris_type'           => $record->irisType?->name,
                'active'              => (bool) $record->active,
                'partner'             => (bool) $record->partner,
                'photo_url'           => $photoUrl,
                'created_at'          => $record->created_at?->format('d/m/Y H:i'),
                'updated_at'          => $record->updated_at?->format('d/m/Y H:i'),
                'deleted_at'          => $record->deleted_at?->format('d/m/Y H:i'),
                'full_name'           => $person->full_name,
                'nickname'            => $person->nickname,
                'cpf'                 => BrazilianFormat::cpf($person->national_registry),
                'birth_date'          => $person->birth_date ? $person->present()->getBirthDate() : null,
                'age'                 => $person->birth_date ? $person->present()->getAge() : null,
                'gender'              => $person->present()->getGender(),
                'marital_status'      => $person->present()->getMaritalStatus(),
                'email'               => $person->email,
                'mother_name'         => $person->mother_name,
                'father_name'         => $person->father_name,
                'occupation'          => $person->occupation,
                'rg'                  => $person->state_registry,
                'rg_agency'           => $person->state_registry_agency,
                'rg_state'            => $person->state_registry_initial,
                'rg_date'             => $person->state_registry_date ? $person->present()->getStateRegistryDate() : null,
                'telephone'           => BrazilianFormat::phone($person->telephone),
                'cellphone'           => BrazilianFormat::phone($person->cellphone),
                'whatsapp'            => (bool) $person->whatsapp,
                'whatsapp_opted_out'  => WhatsAppService::optedOutFor((string) $record->entity_id, $person->cellphone),
                'zipcode'             => $person->zipcode ? $person->present()->getZipcode() : null,
                'address'             => $person->address,
                'number'              => $person->number,
                'complement'          => $person->complement,
                'district'            => $person->district,
                'city'                => $person->city,
                'state'               => $person->state,
                'medical_records_url' => route('panel.patients.medicalrecords.index', $record->id),
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PatientRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);
        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new PatientResource($updatedRecord),
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Search patients for the schedule quick-link (AJAX).
     */
    public function search(Request $request): JsonResponse
    {
        $q        = $request->string('q')->trim()->value();
        $entityId = session()->get('selected_entity_id');

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $qDigits = self::formattedNumberDigits($q);

        $patients = Patient::query()
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where('patients.entity_id', $entityId)
            ->where('patients.active', true)
            ->where(function ($inner) use ($q, $qDigits) {
                $inner->whereLikeUnaccent('people.full_name', $q)
                    ->orWhereLikeUnaccent('people.cellphone', $q)
                    ->orWhereLikeUnaccent('people.telephone', $q)
                    ->orWhereLikeUnaccent('people.national_registry', $q)
                    ->orWhereLikeUnaccent('patients.code', $q)
                    ->when($qDigits, fn ($inner, $digits) => $inner
                        ->orWhereLikeUnaccent('people.cellphone', $digits)
                        ->orWhereLikeUnaccent('people.telephone', $digits)
                        ->orWhereLikeUnaccent('people.national_registry', $digits));
            })
            ->select('patients.id', 'patients.code', 'patients.covenant_id', 'people.full_name', 'people.cellphone', 'people.telephone', 'people.birth_date')
            ->orderBy('people.full_name')
            ->limit(10)
            ->get();

        // Telefones formatados para o dropdown; quem preenche form com eles
        // (agenda/lista de espera) tem v-mask e o FormRequest volta pra dígitos.
        // Idade (não a data) e convênio do cadastro: o pronto atendimento
        // sugere a prioridade por lei (60+) e pré-seleciona o convênio.
        return response()->json($patients->map(fn (Patient $p) => [
            'id'          => $p->id,
            'code'        => $p->code,
            'full_name'   => $p->full_name,
            'cellphone'   => BrazilianFormat::phone($p->cellphone),
            'telephone'   => BrazilianFormat::phone($p->telephone),
            'age'         => $p->birth_date ? Carbon::parse($p->birth_date)->age : null,
            'covenant_id' => $p->covenant_id,
        ])->values());
    }

    /**
     * Telefone/CPF colado já formatado ("(61) 99999-8888", "123.456.789-09"),
     * como a tabela e o dropdown exibem, não casa por LIKE com as colunas
     * gravadas só com dígitos. Devolve os dígitos quando o termo é só número
     * + pontuação de máscara; nome, código ou termo já sem máscara → null.
     */
    private static function formattedNumberDigits(string $term): ?string
    {
        return BrazilianFormat::searchDigits($term);
    }

    /**
     * Quick-create a patient and link to schedule (AJAX).
     */
    public function quickStore(QuickStorePatientRequest $request): JsonResponse
    {
        $entityId = session()->get('selected_entity_id');

        // patients.covenant_id é NOT NULL: o cadastro rápido (lista de espera)
        // nasce "Particular" — mesma semântica da importação de pacientes —, o
        // da clínica antes do global. Antes criava sem convênio e estourava.
        $particularId = Covenant::query()
            ->where('active', true)
            ->whereRaw("upper(btrim(name)) = 'PARTICULAR'")
            ->orderByRaw('entity_id IS NULL')
            ->value('id');

        if (! $particularId) {
            throw ValidationException::withMessages(['name' => [__('schedules.wl_quick_no_particular')]]);
        }

        $patient = DB::transaction(function () use ($request, $entityId, $particularId) {
            Patient::lockRegistrations((string) $entityId);

            $person = People::create([
                'full_name'  => $request->input('name'),
                'cellphone'  => $request->input('cellphone'),
                'birth_date' => $request->input('birth_date') ?: null,
            ]);

            $patient = Patient::create([
                'entity_id'   => $entityId,
                'person_id'   => $person->id,
                'covenant_id' => $particularId,
                'active'      => true,
            ]);

            // Fase 2 (G2): alergias informadas pelo paciente (recepção — nunca
            // confirmadas aqui) ou "nega alergias", na MESMA transação do
            // cadastro. Nada informado = "sem registro de alergias".
            $safety     = app(PatientClinicalSafetyService::class);
            $substances = $request->allergySubstances();

            foreach ($substances as $substance) {
                // Categoria "outro": o texto curto não diz se é remédio, colírio,
                // látex ou alimento; a checagem da receita usa a substância (não a
                // categoria) e o médico reclassifica ao confirmar.
                $safety->createAllergy($patient, ['category' => AllergyCategory::Other->value, 'substance' => $substance], asDoctor: false);
            }

            if ($substances === [] && $request->boolean('allergies_denied')) {
                $safety->setDenied($patient, true);
            }

            return $patient;
        });

        $patient->load('person');

        return response()->json([
            'patient' => [
                'id'          => $patient->id,
                'code'        => $patient->code,
                'full_name'   => $patient->person->full_name,
                'cellphone'   => $patient->person->cellphone ?? '',
                'telephone'   => $patient->person->telephone ?? '',
                'age'         => $patient->person->birth_date?->age,
                'covenant_id' => $patient->covenant_id,
            ],
        ], 201);
    }

    /**
     * Return flat JSON of a patient's data for the crudForm modal.
     */
    public function editData(Patient $patient): JsonResponse
    {
        // Achado de segurança (auditoria da Fase 1 do Portal do Paciente): o
        // route model binding de {patient} sozinho NÃO é filtrado por entidade
        // aqui — `SubstituteBindings` roda antes de `tenant.bind` na ordem de
        // middleware do Laravel, então o EntityScope global ainda está inerte
        // quando {patient} é resolvido. Sem esta checagem explícita, staff de
        // uma entidade conseguia ler PII completo (CPF, endereço, telefone) de
        // paciente de OUTRA entidade só sabendo o UUID.
        abort_unless(
            (string) $patient->entity_id === (string) session('selected_entity_id'),
            404,
        );

        $patient->load(['person', 'covenantPlan', 'covenant']);
        $person = $patient->person;

        return response()->json([
            'data' => [
                'covenant_id' => $patient->covenant_id,
                // Convênio atual (rótulo + ativo) — o seletor só lista ativos.
                'covenant_option' => $patient->covenant ? [
                    'id'     => $patient->covenant->id,
                    'name'   => $patient->covenant->name,
                    'active' => (bool) $patient->covenant->active,
                ] : null,
                'covenant_plan_id' => $patient->covenant_plan_id,
                // Opção já escolhida (o seletor de plano busca no servidor).
                'covenant_plan'          => $patient->covenantPlan?->toOption(),
                'card_number'            => $patient->card_number,
                'skin_id'                => $patient->skin_id,
                'iris_id'                => $patient->iris_id,
                'active'                 => (bool) $patient->active,
                'name'                   => $person->full_name,
                'nickname'               => $person->nickname,
                'national_registry'      => $person->national_registry,
                'birth_date'             => $person->birth_date?->format('Y-m-d'),
                'gender'                 => $person->gender,
                'marital_status'         => $person->marital_status,
                'email'                  => $person->email,
                'mother_name'            => $person->mother_name,
                'father_name'            => $person->father_name,
                'occupation'             => $person->occupation,
                'state_registry'         => $person->state_registry,
                'state_registry_agency'  => $person->state_registry_agency,
                'state_registry_initial' => $person->state_registry_initial,
                'state_registry_date'    => $person->state_registry_date?->format('Y-m-d'),
                'telephone'              => $person->telephone,
                'cellphone'              => $person->cellphone,
                'whatsapp'               => (bool) $person->whatsapp,
                'zipcode'                => $person->zipcode,
                'address'                => $person->address,
                'number'                 => $person->number,
                'complement'             => $person->complement,
                'district'               => $person->district,
                'city'                   => $person->city,
                'state'                  => $person->state,
                'country'                => $person->country,
            ],
        ]);
    }

    /**
     * Estrela de prioridade/triagem do paciente (0-5; 0/null = sem
     * prioridade) — benchmark contra concorrente (Ger Exames/iWayBrasil):
     * sinaliza urgência na fila de atendimento. Diferente de
     * patient_exams.quality_rating (nota de qualidade da CAPTURA de uma
     * imagem específica, doctor-only via Gate::IssueReport) — aqui é
     * triagem operacional, não ato clínico, por isso admin/doctor/secretary
     * (mesmo grupo de permissão de patients.manage) em vez de IssueReport.
     */
    public function updatePriority(Request $request, Patient $patient): JsonResponse
    {
        abort_unless(
            (string) $patient->entity_id === (string) session('selected_entity_id'),
            404,
        );

        $validated = $request->validate([
            'priority_rating' => ['nullable', 'integer', 'min:0', 'max:5'],
        ]);

        $rating = $validated['priority_rating'] ?? null;
        $patient->update(['priority_rating' => $rating === 0 ? null : $rating]);

        return response()->json(['id' => (string) $patient->id, 'priority_rating' => $patient->priority_rating]);
    }

    /**
     * Remove the specified resource from storage.
     */
    // BUG-FIX (achado pelo E2E Cypress): o tipo de retorno não incluía
    // RedirectResponse — TODA exclusão de paciente pela UI (não-JSON)
    // estourava TypeError 500 ao retornar o redirect final.
    public function destroy(string $id): Application|View|JsonResponse|RedirectResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $recordData = $record->toArray();

            $record->delete();

            // People é identidade GLOBAL: a contagem antiga passava pelo
            // EntityScope (só via esta clínica) e apagava o People que outra
            // clínica ainda usava. Agora só sai sem nenhum uso ativo.
            $this->service->deletePersonIfUnused($record->person_id, exceptPatientId: $record->id);

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message' => $this->getDeleteMessage(),
                    'deleted' => $recordData,
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $this->getDeleteMessage());
        });
    }

    /**
     * Restaura paciente excluído (soft delete) — a listagem já mostrava
     * "Restaurar" para eles, mas a rota não existia. Mesmo grupo de permissão
     * da exclusão, só da clínica da sessão (findByIdOrCode), com auditoria
     * (Auditable registra "restored") e sem reabrir duplicado: outro paciente
     * ATIVO com o mesmo nome + nascimento (PatientDuplicateFinder) ou o mesmo
     * CPF nesta clínica barra a restauração.
     */
    public function restore(string $id, PatientDuplicateFinder $duplicates): JsonResponse|RedirectResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if ($record->trashed()) {
            $person = People::query()->withTrashed()->find($record->person_id);
            $error  = $person ? $this->restoreConflict($record, $person, $duplicates) : null;

            if ($error !== null) {
                if (request()->wantsJson()) {
                    return response()->json(['message' => $error, 'errors' => ['name' => [$error]]], 422);
                }

                return back()->with('error', $error);
            }

            DB::transaction(function () use ($record, $person): void {
                if ($person?->trashed()) {
                    $person->deleted_by = null;
                    $person->restore();
                }

                $record->deleted_by = null;
                $record->restore();
            });
        }

        if (request()->wantsJson()) {
            return response()->json(['message' => $this->getRestoreMessage()]);
        }

        return back()->with('success', $this->getRestoreMessage());
    }

    /** Motivo que impede restaurar (duplicado ativo por nome+nascimento ou CPF), ou null. */
    private function restoreConflict(Patient $record, People $person, PatientDuplicateFinder $duplicates): ?string
    {
        $entityId = (string) $record->entity_id;
        $found    = $duplicates->find($entityId, (string) $person->full_name, $person->birth_date?->toDateString(), (string) $person->id);

        if ($found['duplicates'] !== []) {
            return __('validation.custom.patient.duplicate', ['codes' => implode(', ', $found['duplicates'])]);
        }

        if (blank($person->national_registry)) {
            return null;
        }

        $sameCpf = Patient::query()
            ->where('entity_id', $entityId)
            ->whereKeyNot($record->id)
            ->whereHas('person', fn ($q) => $q->where('national_registry', $person->national_registry)->whereKeyNot($person->id))
            ->orderBy('code')
            ->pluck('code')
            ->all();

        return $sameCpf !== []
            ? __('validation.custom.patient.duplicate_cpf', ['codes' => implode(', ', $sameCpf)])
            : null;
    }

    /**
     * Plano para a gaveta de detalhes: nome, registro, características e
     * aviso quando deixou de estar disponível (cancelado na ANS etc.).
     *
     * @return array<string, mixed>|null
     */
    private function planDetails(Patient $patient): ?array
    {
        $plan = $patient->covenantPlan;

        if (! $plan) {
            return null;
        }

        return [
            ...$plan->toOption(),
            'unavailable' => ! $plan->active || $plan->trashed(),
        ];
    }
}
