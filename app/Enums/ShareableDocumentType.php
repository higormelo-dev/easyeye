<?php

namespace App\Enums;

use App\Models\{MedicalRecordDocumentation, MedicalRecordFile, PatientExam};
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Chave curta e estável usada na URL/payload do grant de compartilhamento e
 * do Portal do Paciente — NUNCA o FQCN da classe (não expor namespace de
 * model no client/JS). Resolvida pra classe real só no backend.
 */
enum ShareableDocumentType: string
{
    case Laudo = 'laudo';
    case Exame = 'exame';
    case Anexo = 'anexo';

    public function modelClass(): string
    {
        return match ($this) {
            self::Laudo => MedicalRecordDocumentation::class,
            self::Exame => PatientExam::class,
            self::Anexo => MedicalRecordFile::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Laudo => 'Laudo',
            self::Exame => 'Exame',
            self::Anexo => 'Anexo',
        };
    }

    public static function fromModelClass(string $modelClass): self
    {
        return match ($modelClass) {
            MedicalRecordDocumentation::class => self::Laudo,
            PatientExam::class                => self::Exame,
            MedicalRecordFile::class          => self::Anexo,
            default                           => throw new InvalidArgumentException("Tipo de documento não suportado: {$modelClass}"),
        };
    }

    /**
     * Título de exibição pro Portal do Paciente — centralizado aqui (não
     * duplicado em ClinicController e DocumentsController).
     */
    public function resolveTitle(Model $shareable): string
    {
        return match ($this) {
            self::Laudo => $shareable->title ?: $shareable->getTypeLabel(),
            self::Exame => $shareable->name ?: 'Exame',
            self::Anexo => $shareable->original_name,
        };
    }
}
