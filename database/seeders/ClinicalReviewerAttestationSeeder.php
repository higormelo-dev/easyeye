<?php

namespace Database\Seeders;

use App\Domains\ClinicalReview\Support\AttestationStatement;
use Illuminate\Database\Seeder;

/**
 * Instala a declaração do Revisor Clínico em `term_versions` (idempotente). O fluxo a instala
 * sozinho no primeiro uso; o seeder só deixa a linha visível desde o começo do ambiente.
 */
class ClinicalReviewerAttestationSeeder extends Seeder
{
    public function run(): void
    {
        AttestationStatement::install();
    }
}
