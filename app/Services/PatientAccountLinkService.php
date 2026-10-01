<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{Patient, PatientAccount, PatientAccountLink, People};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, URL};
use SensitiveParameter;

/**
 * Vínculos cadastro (People) ↔ conta do Portal do Paciente.
 *
 * Única porta de escrita: o paciente, com o convite assinado da clínica (link
 * enviado ao e-mail que a clínica cadastrou), autenticado na conta cujo e-mail
 * é o MESMO do convite (um clique se já logado; senão o próprio login conclui).
 * Staff/clínica nunca chamam link() — só disparam o convite. O CADASTRO do
 * paciente na clínica nunca depende disto: o portal é opcional.
 *
 * Um cadastro fica em no máximo uma conta: UNIQUE(person_id) nas duas tabelas
 * + lock por cadastro na transação (titular de uma conta nova × vínculo a
 * outra conta ao mesmo tempo).
 */
class PatientAccountLinkService
{
    /** O cadastro já está em alguma conta (vínculo ou titular)? */
    public function isLinked(string $personId): bool
    {
        return PatientAccountLink::query()->where('person_id', $personId)->exists()
            || PatientAccount::query()->withTrashed()->where('person_id', $personId)->exists();
    }

    /** Mesmo e-mail (sem diferenciar maiúsculas/espaços)? */
    public function sameEmail(?string $a, ?string $b): bool
    {
        return filled($a) && filled($b)
            && mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /**
     * Vincula o cadastro à conta. false quando o e-mail do cadastro não é o da
     * conta ou quando ele já pertence a uma conta (esta ou outra).
     */
    public function link(PatientAccount $account, People $person): bool
    {
        if (! $this->sameEmail($account->email, $person->email)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($account, $person): bool {
                $this->lockPerson($person->id);

                $isOwnTitular = (string) $account->person_id === (string) $person->id;

                if ((! $isOwnTitular && $this->isLinked($person->id))
                    || PatientAccountLink::query()->where('person_id', $person->id)->exists()) {
                    return false;
                }

                $this->createLink($account, $person);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Retoma o convite guardado antes do login (InvitationController::
     * loginToLink) e vincula — o login com senha acabou de provar a conta, e o
     * link assinado (revalidado aqui: assinatura e prazo) foi enviado ao MESMO
     * e-mail dela. Devolve o cadastro vinculado ou null (link inválido/vencido,
     * e-mail diferente, cadastro já em conta).
     */
    public function linkFromInvitationUrl(PatientAccount $account, string $url): ?People
    {
        $request = Request::create($url);

        if ($request->url() !== route('patient-portal.invitation.accept') || ! URL::hasValidSignature($request)) {
            return null;
        }

        $person = People::query()->find((string) $request->query('person_id'));

        return $person !== null && $this->link($account, $person) ? $person : null;
    }

    /**
     * Nomes das clínicas do cadastro (só nome — nada clínico), para o paciente
     * saber o que está adicionando.
     *
     * @return list<string>
     */
    public function clinicNames(People $person): array
    {
        return Patient::query()
            ->withoutGlobalScopes()
            ->where('person_id', $person->id)
            ->with('entity:id,name')
            ->get()
            ->map(fn (Patient $patient): ?string => $patient->entity?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Nomes das clínicas para mensagens ("Clínica A, Clínica B"). */
    public function clinicLabel(People $person): string
    {
        return implode(', ', $this->clinicNames($person)) ?: __('patient_portal.link.clinic_fallback');
    }

    /**
     * Primeira conta do paciente, pelo convite: conta + vínculo do titular.
     * null quando o cadastro já está numa conta (link reutilizado/duas abas).
     */
    public function createAccount(People $person, #[SensitiveParameter] string $password): ?PatientAccount
    {
        return DB::transaction(function () use ($person, $password): ?PatientAccount {
            $this->lockPerson($person->id);

            if ($this->isLinked($person->id)) {
                return null;
            }

            // email_verified_at fica de fora de $fillable de propósito (o
            // paciente nunca deve conseguir setar isso via mass-assignment em
            // outro endpoint) — forceFill() aqui é o único lugar autorizado a
            // marcá-lo, no momento exato em que o e-mail do convite (o mesmo já
            // cadastrado pela clínica) é confirmado pelo aceite do link assinado.
            $account = new PatientAccount([
                'person_id' => $person->id,
                'email'     => $person->email,
                'password'  => $password,
            ]);
            $account->forceFill(['email_verified_at' => now()]);
            $account->save();

            $this->createLink($account, $person);

            return $account;
        });
    }

    private function createLink(PatientAccount $account, People $person): void
    {
        PatientAccountLink::query()->create([
            'patient_account_id' => $account->id,
            'person_id'          => $person->id,
            'entity_id'          => $this->soleEntityId($person->id),
            'linked_at'          => now(),
        ]);
    }

    /**
     * PostgreSQL: advisory lock de TRANSAÇÃO por cadastro — criar conta e
     * vincular o mesmo People ficam em fila; o segundo já vê o primeiro.
     * Demais drivers: sem lock; os UNIQUE do banco seguem valendo.
     */
    private function lockPerson(string $personId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('select pg_advisory_xact_lock(?)', [
            (int) hexdec(substr(sha1('patient-account-link|' . $personId), 0, 15)),
        ]);
    }

    /**
     * Clínica do cadastro, para a trilha de auditoria da própria clínica —
     * null quando ele não pertence a exatamente uma (cadastro compartilhado
     * antigo).
     */
    private function soleEntityId(string $personId): ?string
    {
        $entityIds = Patient::query()
            ->withoutGlobalScopes()
            ->where('person_id', $personId)
            ->distinct()
            ->pluck('entity_id');

        return $entityIds->count() === 1 ? (string) $entityIds->first() : null;
    }
}
