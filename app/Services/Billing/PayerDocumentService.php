<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\CheckoutException;
use App\Models\Entity;
use App\Support\Billing\PayerDocument;
use App\Support\BrazilianFormat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava o CPF/CNPJ da clínica (entities.national_registration) a partir do checkout, para a clínica que ainda não tem
 * documento utilizável (PayerDocument::assertFor barrou o pagamento). SÓ preenche: clínica que já tem documento válido
 * recusa (`document_already_set`) — trocar um documento válido continua sendo do Manager.
 *
 * A linha da clínica é relida e travada (FOR UPDATE) antes de decidir, então dois pedidos simultâneos não
 * sobrescrevem um ao outro. Salva pelo model (Auditable/HasAuditColumns: a trilha de auditoria da Entity registra o
 * antes/depois e quem alterou). Duplicidade que escapa da validação (corrida entre duas clínicas) vira erro de
 * validação, não 500.
 */
class PayerDocumentService
{
    /**
     * @param string $document CPF/CNPJ canônico (já validado pelo PayerDocumentRequest)
     *
     * @return array{document: string, formatted: string}
     */
    public function fill(Entity $entity, string $document): array
    {
        $canonical = (string) BrazilianFormat::documentChars($document);

        try {
            DB::transaction(function () use ($entity, $canonical): void {
                $locked = Entity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();

                if (PayerDocument::isUsable($locked)) {
                    throw CheckoutException::make('document_already_set');
                }

                $locked->national_registration = $canonical;
                $locked->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['document' => __('validation.cnpj_already_registered')]);
        }

        $entity->refresh();

        return [
            'document'  => $canonical,
            'formatted' => (string) BrazilianFormat::cpfCnpj($canonical),
        ];
    }
}
