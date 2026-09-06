<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal;

use App\Enums\{DataAccessPurpose, ShareableDocumentType};
use App\Http\Controllers\Controller;
use App\Models\{MedicalRecordDocumentation, MedicalRecordFile, PatientAccount, PatientExam};
use App\Services\{MedicalRecordPdfService, PatientDocumentAccessService};
use App\Traits\LogsDataAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\{RedirectResponse, Response as HttpResponse};
use Illuminate\Support\Facades\{Auth, Storage};
use Inertia\{Inertia, Response as InertiaResponse};
// BUGFIX (revisão de segurança): Illuminate\Http\StreamedResponse NÃO
// existe — Laravel usa a classe do Symfony diretamente pra respostas
// streamed (Storage::response()/download() retornam isso). O alias antigo
// apontava pra uma classe inexistente; funcionava por acidente (instanceof
// contra classe não resolvível vira false silenciosamente, não fatal), mas
// o tipo declarado nunca batia de verdade. Mesma correção replicada em
// MedicalRecordFilesController (staff), que tinha o mesmo import quebrado.
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leitura de UM documento compartilhado no Portal do Paciente. Toda ação
 * passa OBRIGATORIAMENTE por PatientDocumentAccessService::assertCanView()
 * — nunca confiar em {tipo}/{id} da URL sozinhos.
 *
 *  - view()     : página Inertia (viewer) com metadados — o <iframe>/<img>
 *                 dela aponta pra show() abaixo.
 *  - show()     : conteúdo bruto pra visualização inline (PDF/imagem/arquivo).
 *  - download() : mesmo conteúdo, forçando download (Content-Disposition
 *                 attachment / nova URL S3 com response-content-disposition).
 */
class DocumentsController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly PatientDocumentAccessService $access,
        private readonly MedicalRecordPdfService $pdfService,
    ) {
    }

    public function view(string $tipo, string $id): InertiaResponse
    {
        [$type, $shareable] = $this->authorize($tipo, $id);

        return Inertia::render('PatientPortal/DocumentView', [
            'appName'   => config('app.name', 'EasyEye'),
            'type'      => $type->value,
            'typeLabel' => $type->label(),
            'title'     => $type->resolveTitle($shareable),
            'isImage'   => $type === ShareableDocumentType::Exame
                || ($type === ShareableDocumentType::Anexo && method_exists($shareable, 'isImage') && $shareable->isImage()),
            'isPdf'       => $type === ShareableDocumentType::Laudo,
            'showUrl'     => route('patient-portal.documents.show', [$type->value, $shareable->getKey()]),
            'downloadUrl' => route('patient-portal.documents.download', [$type->value, $shareable->getKey()]),
        ]);
    }

    public function show(string $tipo, string $id): RedirectResponse|HttpResponse|StreamedResponse
    {
        return $this->respond($tipo, $id, forDownload: false);
    }

    public function download(string $tipo, string $id): RedirectResponse|HttpResponse|StreamedResponse
    {
        return $this->respond($tipo, $id, forDownload: true);
    }

    private function respond(string $tipo, string $id, bool $forDownload): RedirectResponse|HttpResponse|StreamedResponse
    {
        [$type, $shareable] = $this->authorize($tipo, $id);

        $this->logAccess($shareable, DataAccessPurpose::PatientCare, patientId: $shareable->getAttribute('patient_id'));

        return match ($type) {
            ShareableDocumentType::Exame => $this->respondExam($shareable, $forDownload),
            ShareableDocumentType::Laudo => $this->respondDocumentation($shareable, $forDownload),
            ShareableDocumentType::Anexo => $this->respondFile($shareable, $forDownload),
        };
    }

    /**
     * @return array{0: ShareableDocumentType, 1: Model}
     */
    private function authorize(string $tipo, string $id): array
    {
        $type = ShareableDocumentType::tryFrom($tipo);
        abort_unless($type !== null, 404);

        $modelClass = $type->modelClass();
        $shareable  = $modelClass::find($id);
        abort_unless($shareable !== null, 404);

        /** @var PatientAccount $account */
        $account = Auth::guard('patient')->user();
        $this->access->assertCanView($account, $shareable);

        return [$type, $shareable];
    }

    private function respondExam(PatientExam $exam, bool $forDownload): RedirectResponse
    {
        abort_unless($exam->archive, 404);

        return redirect()->away($exam->archiveUrlForPatientPortal($forDownload));
    }

    private function respondDocumentation(MedicalRecordDocumentation $doc, bool $forDownload): HttpResponse
    {
        $response = $this->pdfService->generateDocumentation($doc);

        if ($forDownload) {
            $disposition = (string) $response->headers->get('Content-Disposition');
            $response->headers->set('Content-Disposition', str_replace('inline', 'attachment', $disposition));
        }

        return $response;
    }

    private function respondFile(MedicalRecordFile $file, bool $forDownload): StreamedResponse
    {
        abort_unless(Storage::disk('private')->exists($file->file_path), 404);

        // BUGFIX (revisão de segurança): nunca montar Content-Disposition por
        // concatenação manual — original_name é entrada do usuário (upload
        // do staff) e um `"` corrompe/estende o header. Omitir a chave deixa
        // o Laravel usar makeDisposition() (escapa corretamente).
        return $forDownload
            ? Storage::disk('private')->download($file->file_path, $file->original_name)
            : Storage::disk('private')->response($file->file_path, $file->original_name);
    }
}
