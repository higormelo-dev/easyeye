<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Portal do Paciente com várias clínicas: o mesmo paciente pode ter um
 * cadastro (People) em cada clínica — cada clínica com o seu, sem
 * compartilhar dados. Esta tabela liga à conta do portal os cadastros que o
 * PRÓPRIO paciente vinculou (aceitando o convite assinado de cada clínica,
 * logado e confirmando a senha). A clínica nunca vincula nada sozinha.
 *
 * person_id UNIQUE: um cadastro pertence a no máximo UMA conta (garantia no
 * banco, não só na aplicação). patient_accounts.person_id continua sendo o
 * cadastro "titular" da conta; ele também entra aqui (carga abaixo).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('patient_account_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_account_id')->constrained('patient_accounts')->cascadeOnDelete();
            $table->foreignUuid('person_id')->unique()->constrained('people')->cascadeOnDelete();
            // Clínica do convite aceito (trilha de auditoria da clínica); null
            // quando o cadastro não pertence a exatamente uma clínica.
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->index('patient_account_id');
        });

        // Contas existentes: o cadastro titular vira o primeiro vínculo.
        DB::table('patient_accounts')
            ->select(['id', 'person_id', 'created_at'])
            ->orderBy('id')
            ->chunk(500, function ($accounts): void {
                DB::table('patient_account_links')->insertOrIgnore($accounts->map(fn ($account): array => [
                    'id'                 => (string) Str::uuid(),
                    'patient_account_id' => $account->id,
                    'person_id'          => $account->person_id,
                    'linked_at'          => $account->created_at ?? now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_account_links');
    }
};
