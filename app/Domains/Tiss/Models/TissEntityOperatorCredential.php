<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Models;

use App\Domains\Tiss\Concerns\BelongsToEntity;
use App\Models\Entity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class TissEntityOperatorCredential extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    public const ENV_PRODUCTION = 'production';

    /** Homologação da operadora (TISS_TRANSPORT_ENVIRONMENT=sandbox). */
    public const ENV_SANDBOX = 'sandbox';

    public const ENVIRONMENTS = [self::ENV_SANDBOX, self::ENV_PRODUCTION];

    protected $table = 'tiss_entity_operator_credentials';

    /** Segredos nunca vão para JSON/Inertia (só o "está salvo", montado à mão na tela). */
    protected $hidden = ['password_encrypted', 'token_encrypted', 'certificate_password_encrypted'];

    /** @var list<string> segredos (mesmo cifrados) e login nunca vão para audit_logs */
    protected array $auditExclude = ['username', 'password_encrypted', 'token_encrypted', 'certificate_password_encrypted'];

    protected $fillable = [
        'entity_id',
        'operator_id',
        'environment',
        'endpoint_submission',
        'endpoint_status',
        'endpoint_eligibility',
        'endpoint_authorization',
        'username',
        'password_encrypted',
        'token_encrypted',
        'certificate_path',
        'certificate_password_encrypted',
        'private_key_path',
        'ssl_enabled',
        'active',
        'last_success_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ssl_enabled'     => 'boolean',
            'active'          => 'boolean',
            'last_success_at' => 'datetime',
            'metadata'        => 'array',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime',
            'deleted_at'      => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(TissOperator::class, 'operator_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(TissEntityOperatorContract::class, 'credential_id');
    }

    /** Endereço que o transporte HTTP usa: o da credencial ou, sem ele, o da operadora + /tiss. */
    public function submissionEndpoint(): ?string
    {
        if (filled($this->endpoint_submission)) {
            return (string) $this->endpoint_submission;
        }

        $base = $this->operator?->webservice_base_url;

        return filled($base) ? rtrim((string) $base, '/') . '/tiss' : null;
    }

    /**
     * O que impede o transporte HTTP de enviar com esta credencial: sem endereço do webservice, ou ainda a linha
     * provisória que o 1º faturamento criou sozinho (nenhum administrador revisou). Lista vazia = pode enviar.
     *
     * Autenticação NÃO entra: depende da operadora (usuário e senha, token, certificado, ou só liberação por IP).
     * A tela avisa quando não há nenhuma (hasAuthentication) e o teste de conexão mostra a recusa (401/403).
     *
     * @return list<'endpoint'|'review'>
     */
    public function missingForSubmission(): array
    {
        return array_values(array_filter([
            $this->submissionEndpoint() === null ? 'endpoint' : null,
            $this->isPlaceholder() ? 'review' : null,
        ]));
    }

    public function hasAuthentication(): bool
    {
        return (filled($this->username) && filled($this->password_encrypted))
            || filled($this->token_encrypted)
            || filled($this->certificate_path);
    }

    /** Linha criada sozinha no 1º faturamento do convênio (ResolveTissOperatorForCovenantAction), ainda sem dado real. */
    public function isPlaceholder(): bool
    {
        return filled($this->metadata['provisioned_by'] ?? null)
            && blank($this->endpoint_submission)
            && blank($this->username)
            && blank($this->password_encrypted)
            && blank($this->token_encrypted)
            && blank($this->certificate_path);
    }
}
