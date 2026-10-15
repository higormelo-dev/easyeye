<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{SurgeryDocumentType, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{Doctor, Entity, Patient};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\Documents\ConsentBasis;
use App\Support\CurrentDoctor;
use App\Support\Surgery\CanonicalHash;
use Barryvdh\Snappy\Facades\SnappyPdf;
use finfo;
use Illuminate\Http\{Response, UploadedFile};
use Illuminate\Support\Facades\{Auth, DB, Storage};
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

/**
 * Emissão de documento cirúrgico CONGELADO (ADR 0003, D4): TCLE, orientações,
 * termo do implante, alta.
 *
 * O texto emitido é copiado para a linha (`content`), junto com o cabeçalho
 * (clínica, paciente, caso) e o signatário: o PDF sai SÓ do retrato, nunca do
 * cadastro vivo — corrigir o cadastro depois não reescreve um documento já
 * entregue. `content_hash` cobre tudo isso. Reemissão cria linha nova e marca
 * a anterior `superseded_by_id`; o mesmo conteúdo emitido de novo em até 10
 * minutos (clique duplo, reenvio do formulário) devolve a emissão existente.
 *
 * Assinatura ICP-Brasil (Fase 3) não existe: o ponto de troca é `issue()`.
 */
class SurgeryDocumentIssueService
{
    public const DEDUPE_WINDOW_MINUTES = 10;

    public const SIGNED_COPY_MAX_KB = 10240;

    public const SIGNED_COPY_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    /**
     * @param array<string, mixed> $data  dados estruturados do documento (vão em payload.data)
     * @param string               $scope distingue emissões simultâneas do mesmo tipo (ex.: um termo por implante)
     */
    public function issue(
        SurgicalCase $case,
        SurgeryDocumentType $type,
        string $title,
        string $content,
        array $data = [],
        string $scope = 'main',
        ?string $templateId = null,
        ?int $templateVersion = null,
        ?string $basisHash = null,
    ): SurgeryDocumentIssue {
        return DB::transaction(function () use ($case, $type, $title, $content, $data, $scope, $templateId, $templateVersion, $basisHash): SurgeryDocumentIssue {
            /** @var SurgicalCase $locked */
            $locked = SurgicalCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === SurgicalCaseStatus::Cancelled) {
                throw SurgeryRuleException::state('surgery.errors.case_closed');
            }

            // O TCLE é anterior à cirurgia: depois de realizada, emitir ou reemitir o
            // termo trocaria o vigente (assinado) por um sem cópia, ou criaria um
            // termo com data posterior ao ato.
            if ($type === SurgeryDocumentType::Consent && $locked->status->wasPerformed()) {
                throw SurgeryRuleException::state('surgery_documents.errors.consent_locked');
            }

            // O TCLE guarda o que afirma sobre a cirurgia (olho, procedimentos, cirurgião, anestesia): a cópia
            // assinada só vale enquanto o pedido continuar sendo esse (ConsentSignedGuard). Se o texto veio de uma
            // prévia e o pedido mudou desde então, o termo sairia dizendo uma coisa e valendo para outra.
            if ($type === SurgeryDocumentType::Consent) {
                $basis = ConsentBasis::of($locked);

                if ($basisHash !== null && ! hash_equals(ConsentBasis::hash($basis), $basisHash)) {
                    throw SurgeryRuleException::state('surgery_documents.errors.consent_basis_changed');
                }

                $data['consent_basis'] = $basis;
            }

            $issuer = CurrentDoctor::issuer();

            if ($type->requiresDoctor() && $issuer === null) {
                throw SurgeryRuleException::state('surgery.errors.document_needs_doctor');
            }

            $patient = Patient::query()->with('person')->findOrFail($locked->patient_id);
            $content = Purifier::clean($content, 'medical');
            $payload = [
                'scope'  => $scope,
                'header' => $this->header($locked, $patient),
                'signer' => $issuer !== null ? $this->signer($issuer) : null,
                'data'   => $data,
            ];

            $hash = CanonicalHash::of([
                'v'       => CanonicalHash::VERSION,
                'case'    => (string) $locked->getKey(),
                'patient' => (string) $locked->patient_id,
                'type'    => $type->value,
                'title'   => $title,
                'content' => $content,
                'payload' => $payload,
            ]);

            $recent = SurgeryDocumentIssue::query()
                ->where('surgical_case_id', $locked->getKey())
                ->where('type', $type->value)
                // Só a emissão VIGENTE conta: o mesmo texto de uma já substituída
                // é uma reemissão de verdade (volta a ser a vigente).
                ->whereNull('superseded_by_id')
                ->where('content_hash', $hash)
                ->where('issued_at', '>=', now()->subMinutes(self::DEDUPE_WINDOW_MINUTES))
                ->orderByDesc('issued_at')
                ->first();

            if ($recent !== null) {
                return $recent;
            }

            $issue = SurgeryDocumentIssue::query()->create([
                'entity_id'           => $locked->entity_id,
                'surgical_case_id'    => $locked->getKey(),
                'patient_id'          => $locked->patient_id,
                'type'                => $type,
                'title'               => $title,
                'content'             => $content,
                'payload'             => $payload,
                'content_hash'        => $hash,
                'template_id'         => $templateId,
                'template_version'    => $templateVersion,
                'issued_by'           => Auth::id(),
                'issued_by_doctor_id' => $issuer?->getKey(),
                'issued_at'           => now(),
            ]);

            // A emissão vigente anterior (mesmo caso + tipo + escopo) vira histórico.
            SurgeryDocumentIssue::query()
                ->where('surgical_case_id', $locked->getKey())
                ->where('type', $type->value)
                ->whereNull('superseded_by_id')
                ->whereKeyNot($issue->getKey())
                ->whereRaw("coalesce(payload->>'scope', 'main') = ?", [$scope])
                ->get()
                ->each(fn (SurgeryDocumentIssue $old) => $old->update(['superseded_by_id' => $issue->getKey()]));

            return $issue;
        });
    }

    // ── PDF ────────────────────────────────────────────────────────────────

    /** PDF montado só do retrato gravado. */
    public function pdf(SurgeryDocumentIssue $issue): Response
    {
        $entity = Entity::query()->findOrFail($issue->entity_id);

        $filename = preg_replace('/[^A-Za-z0-9\-_.]/', '_', 'cirurgia-' . $issue->type->value . '-' . substr((string) $issue->getKey(), 0, 8) . '.pdf');

        return SnappyPdf::loadView('pdf.surgery_document', [
            'issue'  => $issue,
            'entity' => $entity,
            'header' => (array) ($issue->payload['header'] ?? []),
            'signer' => $issue->payload['signer'] ?? null,
            'data'   => (array) ($issue->payload['data'] ?? []),
        ])
            ->setPaper('a4')
            ->setOption('margin-top', '1.8cm')
            ->setOption('margin-right', '1.8cm')
            ->setOption('margin-bottom', '2cm')
            ->setOption('margin-left', '2cm')
            ->setOption('footer-font-size', '7')
            ->setOption('footer-center', substr($issue->content_hash, 0, 16) . ' · ' . __('pdf.page_footer_native'))
            ->setOption('encoding', 'UTF-8')
            ->inline($filename);
    }

    // ── Cópia digitalizada assinada ─────────────────────────────────────────

    /**
     * Anexa a cópia do documento assinado pelo paciente (PDF/JPG/PNG). Troca a
     * cópia anterior (o arquivo velho é apagado depois do commit). O tipo é
     * conferido pelo CONTEÚDO do arquivo, não pelo nome nem pelo cabeçalho do
     * navegador.
     */
    public function attachSignedCopy(SurgeryDocumentIssue $issue, UploadedFile $file): SurgeryDocumentIssue
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! in_array($mime, self::SIGNED_COPY_MIMES, true)) {
            throw SurgeryRuleException::invalid('surgery.errors.signed_copy_type');
        }

        if ($file->getSize() > self::SIGNED_COPY_MAX_KB * 1024) {
            throw SurgeryRuleException::invalid('surgery.errors.signed_copy_size', ['max' => (int) (self::SIGNED_COPY_MAX_KB / 1024)]);
        }

        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            default           => 'png',
        };

        $path = sprintf('surgery/%s/%s/%s.%s', $issue->entity_id, $issue->surgical_case_id, (string) Str::uuid(), $extension);
        $old  = null; // mantido só para a leitura da cópia anterior dentro da transação

        $updated = DB::transaction(function () use ($issue, $file, $mime, $path, &$old): SurgeryDocumentIssue {
            $locked = SurgeryDocumentIssue::query()->whereKey($issue->getKey())->lockForUpdate()->firstOrFail();

            // Depois de realizada a cirurgia a cópia assinada do termo não se troca (nem por engano).
            $case = SurgicalCase::query()->whereKey($locked->surgical_case_id)->first();

            if ($locked->hasSignedCopy() && $case?->status->wasPerformed()) {
                throw SurgeryRuleException::state('surgery.errors.signed_copy_locked');
            }
            $old = $locked->signed_copy_path;

            Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

            $locked->update([
                'signed_copy_path'        => $path,
                'signed_copy_name'        => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'signed_copy_mime'        => $mime,
                'signed_copy_size'        => $file->getSize(),
                'signed_copy_hash'        => hash_file('sha256', $file->getRealPath()),
                'signed_copy_uploaded_at' => now(),
                'signed_copy_uploaded_by' => Auth::id(),
            ]);

            return $locked;
        });

        // O arquivo anterior NÃO é apagado: é evidência de que o paciente assinou; o caminho e o hash
        // antigos ficam na trilha de auditoria (SurgeryDocumentIssue é Auditable) e no disco.
        return $updated;
    }

    // ── Retrato do cabeçalho ────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function header(SurgicalCase $case, Patient $patient): array
    {
        $entity = Entity::query()->find($case->entity_id);
        $person = $patient->person;

        return [
            'clinic' => [
                'name'    => $entity?->name,
                'cnpj'    => $entity?->national_registration,
                'cnes'    => $entity?->cnes,
                'address' => $entity ? trim(implode(', ', array_filter([$entity->address, $entity->number, $entity->district, $entity->city . ($entity->state ? '/' . $entity->state : '')]))) : null,
                'phone'   => $entity?->telephone ?: $entity?->cellphone,
            ],
            'patient' => [
                'name'       => $person?->full_name,
                'birth_date' => $person?->birth_date?->toDateString(),
                'code'       => $patient->code,
            ],
            'case' => [
                'code'  => $case->code,
                'title' => $case->title,
                'eye'   => $case->eye->value,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function signer(Doctor $doctor): array
    {
        $doctor->loadMissing('person');

        return [
            'doctor_id' => (string) $doctor->getKey(),
            'name'      => $doctor->person?->full_name,
            'registry'  => $doctor->record,
            'specialty' => $doctor->record_specialty,
        ];
    }
}
