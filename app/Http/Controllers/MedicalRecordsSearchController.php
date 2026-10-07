<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Doctor, MedicalRecord};
use App\Support\{BrazilianFormat, CurrentDoctor};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * "Prontuários" no menu (Fase 1 / B3): busca geral dos prontuários da
 * clínica — paciente (nome, CPF, código PAC), código PMR, médico, período e
 * assinado/não.
 *
 * Mesmo acesso de hoje aos prontuários (entity.role:admin,doctor,secretary,
 * leitura) + escopo de médico pela regra única (App\Support\CurrentDoctor):
 * perfil médico vê os prontuários DELE; sem cadastro de médico, nada (aviso).
 * A lista não traz conteúdo clínico (queixa, CID, conduta): abrir o
 * prontuário passa pelo show/edit de sempre, que registram o acesso
 * (LogsDataAccess).
 */
class MedicalRecordsSearchController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'q'      => ['nullable', 'string', 'max:100'],
            'doctor' => ['nullable', 'uuid'],
            'from'   => ['nullable', 'date_format:Y-m-d'],
            'until'  => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'signed' => ['nullable', 'in:yes,no'],
        ]);

        $entityId      = (string) session('selected_entity_id');
        $isDoctor      = CurrentDoctor::isDoctorProfile();
        $doctor        = CurrentDoctor::doctor();
        $doctorMissing = $isDoctor && ! $doctor;
        $doctors       = $isDoctor ? collect() : $this->doctors($entityId);

        $filters = [
            'q'      => trim((string) ($validated['q'] ?? '')),
            'doctor' => $isDoctor
                ? (string) $doctor?->id
                : ($doctors->contains('id', $validated['doctor'] ?? null) ? (string) $validated['doctor'] : ''),
            'from'   => $validated['from'] ?? '',
            'until'  => $validated['until'] ?? '',
            'signed' => $validated['signed'] ?? '',
        ];

        $canEdit = CurrentDoctor::canIssue();

        return Inertia::render('Panel/MedicalRecords/Search', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.medical_records'), 'url' => '#', 'active' => true],
            ],
            'filters' => $filters,
            'records' => fn () => $doctorMissing
                ? null
                : $this->query($entityId, $filters)
                    ->paginate(self::PER_PAGE)
                    ->withQueryString()
                    ->through(fn (MedicalRecord $r) => $this->row($r, $canEdit)),
            'doctors'           => $doctors->values()->all(),
            'isDoctor'          => $isDoctor,
            'doctorMissing'     => $doctorMissing,
            'doctorMissingText' => CurrentDoctor::missingMessage(),
            't'                 => trans('actions.medical_records'),
            'ts'                => trans('medical_record_search'),
        ]);
    }

    /** @return Builder<MedicalRecord> */
    private function query(string $entityId, array $filters): Builder
    {
        $query = MedicalRecord::query()
            ->with(['doctor.person', 'doctor.entityUser.user', 'patient.person', 'schedule:id,origin'])
            ->join('patients', 'patients.id', '=', 'medical_records.patient_id')
            ->join('people', 'people.id', '=', 'patients.person_id')
            ->where('medical_records.entity_id', $entityId)
            ->where('patients.entity_id', $entityId)
            ->whereNull('patients.deleted_at')
            ->select('medical_records.*');

        if ($filters['doctor'] !== '') {
            $query->where('medical_records.doctor_id', $filters['doctor']);
        }

        if ($filters['from'] !== '') {
            $query->where('medical_records.created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if ($filters['until'] !== '') {
            $query->where('medical_records.created_at', '<=', Carbon::parse($filters['until'])->endOfDay());
        }

        // Mesma regra de MedicalRecord::isSigned() (data + hash da assinatura).
        if ($filters['signed'] === 'yes') {
            $query->whereNotNull('medical_records.signed_at')->whereNotNull('medical_records.signature_hash');
        } elseif ($filters['signed'] === 'no') {
            $query->where(fn (Builder $q) => $q->whereNull('medical_records.signed_at')->orWhereNull('medical_records.signature_hash'));
        }

        if ($filters['q'] !== '') {
            $this->applySearch($query, $filters['q']);
        }

        return $query->orderByDesc('medical_records.created_at')->orderByDesc('medical_records.id');
    }

    /**
     * Paciente (nome sem acento, CPF), código PAC ou PMR — com ou sem o
     * prefixo e os zeros ("PMR-42", "pmr42", "42").
     */
    private function applySearch(Builder $query, string $term): void
    {
        $digits = BrazilianFormat::digits($term);
        $code   = preg_match('/^(PAC|PMR)\s*-?\s*0*(\d+)$/i', $term, $m) === 1
            ? ['prefix' => strtoupper($m[1]), 'number' => (int) $m[2]]
            : null;

        $query->where(function (Builder $q) use ($term, $digits, $code) {
            $q->whereLikeUnaccent('people.full_name', $term)
                ->orWhereLikeUnaccent('patients.code', $term)
                ->orWhereLikeUnaccent('medical_records.code', $term);

            if ($code !== null) {
                $formatted = sprintf('%s-%010d', $code['prefix'], $code['number']);
                $q->orWhere($code['prefix'] === 'PMR' ? 'medical_records.code' : 'patients.code', $formatted);
            } elseif ($digits !== '' && ctype_digit(str_replace([' ', '.', '-', '/'], '', $term))) {
                // Só número: CPF (11 dígitos) ou o número do código (PAC/PMR).
                $q->orWhereLikeUnaccent('people.national_registry', $digits);

                if (strlen($digits) <= 10) {
                    $q->orWhere('medical_records.code', sprintf('PMR-%010d', (int) $digits))
                        ->orWhere('patients.code', sprintf('PAC-%010d', (int) $digits));
                }
            }
        });
    }

    /** @return array<string, mixed> */
    private function row(MedicalRecord $r, bool $canEdit): array
    {
        $locale = app()->getLocale();
        $person = $r->patient?->person;
        $birth  = $person?->birth_date;

        return [
            'id'             => (string) $r->id,
            'code'           => $r->code,
            'created_at'     => $r->created_at?->copy()->locale($locale)->isoFormat('L LT'),
            'created_at_iso' => $r->created_at?->toIso8601String(),
            'patient_id'     => (string) $r->patient_id,
            'patient_name'   => $person?->full_name ?? '—',
            'patient_code'   => $r->patient?->code,
            'age'            => $birth ? Carbon::parse($birth)->age : null,
            'doctor_name'    => $r->doctor?->person?->full_name ?? $r->doctor?->entityUser?->user?->name,
            'is_signed'      => $r->isSigned(),
            'signed_at'      => $r->signed_at?->copy()->locale($locale)->isoFormat('L LT'),
            'is_locked'      => $r->isLocked(),
            'is_urgent_care' => (bool) $r->schedule?->isUrgentCare(),
            // Drawer de detalhes (show registra o acesso) e PDF.
            'show_url'    => route('panel.patients.medicalrecords.show', [$r->patient_id, $r->id]),
            'pdf_url'     => route('panel.patients.medicalrecords.pdf', [$r->patient_id, $r->id]),
            'history_url' => route('panel.patients.medicalrecords.index', $r->patient_id),
            // Editar: só médico com cadastro ativo, prontuário não assinado.
            'edit_url' => $canEdit && ! $r->isLocked()
                ? route('panel.patients.medicalrecords.edit', [$r->patient_id, $r->id])
                : null,
        ];
    }

    /** Médicos da clínica (ativos e inativos — prontuário antigo também se busca). */
    private function doctors(string $entityId)
    {
        return Doctor::query()
            ->with('person:id,full_name')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->join('users', 'users.id', '=', 'entity_users.user_id')
            ->where('entity_users.entity_id', $entityId)
            ->orderBy('users.name')
            ->get(['doctors.id', 'doctors.person_id', 'doctors.active', 'users.name as user_name'])
            ->map(fn (Doctor $d) => [
                'id'     => (string) $d->id,
                'name'   => $d->person?->full_name ?: (string) $d->user_name,
                'active' => (bool) $d->active,
            ]);
    }
}
