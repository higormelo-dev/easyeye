<?php

declare(strict_types=1);

namespace App\Models\Fiscal;

use App\Enums\Fiscal\{NfseEnvironment, NfseStandard, NfseStatus};
use App\Models\Billing\Invoice;
use App\Models\{Entity, User};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NFS-e emitida (ou em emissão) pelo SaaS para uma fatura paga de clínica.
 *
 * Uma fatura tem N linhas ao longo do tempo (reemissão após erro/cancelamento =
 * linha nova, `ref` nova), mas no máximo UMA ocupando-a por vez — garantido pelo
 * índice único parcial `nfse_documents_invoice_occupying_unique`.
 *
 * `amount`, `currency` e `payer_snapshot` são congelados na criação: estorno
 * parcial posterior não os reescreve (regra fiscal). Payloads e `errors` são
 * saneados (sem token) e ficam fora da auditoria — ela guarda a mudança de
 * status, não o corpo inteiro.
 */
class NfseDocument extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;

    protected $table = 'nfse_documents';

    protected $fillable = [
        'invoice_id',
        'entity_id',
        'environment',
        'standard',
        'ref',
        'status',
        'numero',
        'codigo_verificacao',
        'dps_series',
        'dps_number',
        'amount',
        'currency',
        'issued_at',
        'cancelled_at',
        'cancelled_externally',
        'cancel_required',
        'cancel_required_reason',
        'url',
        'pdf_url',
        'xml_path',
        'cancel_xml_path',
        'payer_snapshot',
        'request_payload',
        'response_payload',
        'errors',
        'justification',
        'correlation_id',
        'source',
    ];

    /** @var list<string> o corpo não vai para audit_logs: só a mudança de status e dos campos curtos */
    protected array $auditExclude = ['request_payload', 'response_payload', 'payer_snapshot', 'errors'];

    protected function casts(): array
    {
        return [
            'environment'          => NfseEnvironment::class,
            'standard'             => NfseStandard::class,
            'status'               => NfseStatus::class,
            'amount'               => 'decimal:2',
            'dps_series'           => 'integer',
            'dps_number'           => 'integer',
            'issued_at'            => 'datetime',
            'cancelled_at'         => 'datetime',
            'cancelled_externally' => 'boolean',
            'cancel_required'      => 'boolean',
            'payer_snapshot'       => 'array',
            'request_payload'      => 'array',
            'response_payload'     => 'array',
            'errors'               => 'array',
        ];
    }

    protected static function booted(): void
    {
        // A ref é derivada do id (uma vez, na criação): a mesma no envio, na consulta, no cancelamento e no e-mail.
        // O id já foi gerado pelo HasUuids (que registra o `creating` antes deste).
        static::creating(function (self $document): void {
            if (blank($document->ref)) {
                $document->ref = self::refFor((string) $document->getKey());
            }
        });
    }

    /** UUID sem hífens, maiúsculo: 32 alfanuméricos — o formato aceito pela Focus em `?ref=`. */
    public static function refFor(string $id): string
    {
        return strtoupper(str_replace('-', '', $id));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOccupying(): bool
    {
        return $this->status->isOccupying();
    }

    public function isAuthorized(): bool
    {
        return $this->status === NfseStatus::Authorized;
    }
}
