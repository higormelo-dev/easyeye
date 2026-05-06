<?php

namespace Database\Seeders;

use App\Models\{ReportSetting, ReportSettingContent, ReportSettingVariable};
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Registra as variáveis dinâmicas de cada template de documentação clínica.
 *
 * Cada variável mapeia um placeholder {{VARIAVEL}} do conteúdo HTML para
 * a sua fonte de dados:
 *
 *   source_type  | origem da substituição
 *   -------------|-------------------------------------------------------
 *   patient      | Model Patient / Person (nome, CPF, data de nascimento)
 *   doctor       | Model Doctor / Person (nome, CRM, especialidade)
 *   entity       | Model Entity (nome da clínica, endereço, telefone)
 *   date         | Carbon: data atual, data por extenso
 *   medical_record | Prontuário/exame atual (tonometria, refração, cirurgia)
 *   custom       | Preenchimento manual pelo médico no momento da emissão
 *
 * Variáveis resolvidas automaticamente por MedicalRecordDocumentationService:
 *   {{PACIENTE_NOME}}, {{PACIENTE_CPF}}, {{PACIENTE_DATA_NASCIMENTO}},
 *   {{PACIENTE_IDADE}}, {{MEDICO_NOME}}, {{MEDICO_CRM}},
 *   {{MEDICO_CRM_ESPECIALIDADE}}, {{CLINICA_NOME}}, {{CLINICA_ENDERECO}},
 *   {{CLINICA_TELEFONE}}, {{DATA_ATUAL}}, {{DATA_EXTENSO}}.
 *
 * Variáveis de prontuário (source_type=medical_record) são resolvidas pela
 * camada de negócio ao construir o documento a partir do exame/consulta.
 */
class ReportSettingVariableSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->variables() as $settingTitle => $labeledGroups) {
            $setting = ReportSetting::whereNull('entity_id')
                ->where('title', $settingTitle)
                ->first();

            if (! $setting) {
                $this->command->warn("  [skip] ReportSetting não encontrado: \"{$settingTitle}\"");

                continue;
            }

            foreach ($labeledGroups as $slug => $vars) {
                $content = ReportSettingContent::where('report_setting_id', $setting->id)
                    ->where('slug', $slug)
                    ->first();

                if (! $content) {
                    $this->command->warn("  [skip] Content não encontrado: \"{$settingTitle}\" / slug=\"{$slug}\"");

                    continue;
                }

                foreach ($vars as $var) {
                    ReportSettingVariable::firstOrCreate(
                        [
                            'report_setting_content_id' => $content->id,
                            'placeholder'               => $var['placeholder'],
                        ],
                        [
                            'source_type'   => $var['source_type'],
                            'source_field'  => $var['source_field'] ?? null,
                            'default_value' => $var['default_value'] ?? null,
                            'active'        => true,
                        ],
                    );
                }
            }
        }
    }

    /**
     * Estrutura: [ 'TITULO DO REPORT SETTING' => [ 'slug_do_content' => [ vars... ] ] ].
     *
     * @return array<string, array<string, list<array{placeholder: string, source_type: string, source_field?: string|null, default_value?: string|null}>>>
     */
    private function variables(): array
    {
        // ── Variáveis de refração reutilizadas em múltiplos templates ────────
        $refrDinamicaOD = [
            ['placeholder' => '{{REFRACAO_DINAMICA_OD_ESF}}', 'source_type' => 'medical_record', 'source_field' => 'dynamic_spherical_right'],
            ['placeholder' => '{{REFRACAO_DINAMICA_OD_CIL}}', 'source_type' => 'medical_record', 'source_field' => 'dynamic_cylindrical_right'],
            ['placeholder' => '{{REFRACAO_DINAMICA_OD_EIXO}}', 'source_type' => 'medical_record', 'source_field' => 'dynamic_axis_right'],
        ];

        $refrDinamicaOE = [
            ['placeholder' => '{{REFRACAO_DINAMICA_OE_ESF}}', 'source_type' => 'medical_record', 'source_field' => 'dynamic_spherical_left'],
            ['placeholder' => '{{REFRACAO_DINAMICA_OE_CIL}}', 'source_type' => 'medical_record', 'source_field' => 'dynamic_cylindrical_left'],
            ['placeholder' => '{{REFRACAO_DINAMICA_OE_EIXO}}', 'source_type' => 'medical_record', 'source_field' => 'dynamic_axis_left'],
        ];

        $refrEstaticaOD = [
            ['placeholder' => '{{REFRACAO_ESTATICA_OD_ESF}}', 'source_type' => 'medical_record', 'source_field' => 'static_spherical_right'],
            ['placeholder' => '{{REFRACAO_ESTATICA_OD_CIL}}', 'source_type' => 'medical_record', 'source_field' => 'static_cylindrical_right'],
            ['placeholder' => '{{REFRACAO_ESTATICA_OD_EIXO}}', 'source_type' => 'medical_record', 'source_field' => 'static_axis_right'],
        ];

        $refrEstaticaOE = [
            ['placeholder' => '{{REFRACAO_ESTATICA_OE_ESF}}', 'source_type' => 'medical_record', 'source_field' => 'static_spherical_left'],
            ['placeholder' => '{{REFRACAO_ESTATICA_OE_CIL}}', 'source_type' => 'medical_record', 'source_field' => 'static_cylindrical_left'],
            ['placeholder' => '{{REFRACAO_ESTATICA_OE_EIXO}}', 'source_type' => 'medical_record', 'source_field' => 'static_axis_left'],
        ];

        $lentes = [
            ['placeholder' => '{{LENTE_LONGE}}', 'source_type' => 'medical_record', 'source_field' => 'lensAway.name'],
            ['placeholder' => '{{LENTE_OBSERVACAO}}', 'source_type' => 'medical_record', 'source_field' => 'observation_of_lenses'],
        ];

        $lentesPerto = [
            ['placeholder' => '{{LENTE_PERTO}}', 'source_type' => 'medical_record', 'source_field' => 'lensNear.name'],
        ];

        return [
            // ── LAUDO DE TONÔMETRIA ──────────────────────────────────────────
            'LAUDO DE TONÔMETRIA' => [
                'padrao' => [
                    ['placeholder' => '{{TONOMETRIA_OD}}', 'source_type' => 'medical_record', 'source_field' => 'tonometer_right'],
                    ['placeholder' => '{{TONOMETRIA_OE}}', 'source_type' => 'medical_record', 'source_field' => 'tonometer_left'],
                ],
            ],

            // ── LAUDO DE ECOBIOMETRIA ────────────────────────────────────────
            'LAUDO DE ECOBIOMETRIA' => [
                'padrao' => [
                    ['placeholder' => '{{ECOBIOMETRIA_LIO_OD}}', 'source_type' => 'custom', 'default_value' => ''],
                    ['placeholder' => '{{ECOBIOMETRIA_LIO_OE}}', 'source_type' => 'custom', 'default_value' => ''],
                ],
            ],

            // ── PAQUIMETRIA CORNEANA ─────────────────────────────────────────
            'PAQUIMETRIA CORNEANA' => [
                'padrao' => [
                    ['placeholder' => '{{PAQUIMETRIA_OD}}', 'source_type' => 'medical_record', 'source_field' => 'pachymetry_right'],
                    ['placeholder' => '{{PAQUIMETRIA_OE}}', 'source_type' => 'medical_record', 'source_field' => 'pachymetry_left'],
                ],
            ],

            // ── LAUDO DE GONIOSCOPIA ─────────────────────────────────────────
            'LAUDO DE GONIOSCOPIA' => [
                'padrao' => [
                    ['placeholder' => '{{GONIOSCOPIA_OD}}', 'source_type' => 'medical_record', 'source_field' => 'gonioscopy_right'],
                    ['placeholder' => '{{GONIOSCOPIA_OE}}', 'source_type' => 'medical_record', 'source_field' => 'gonioscopy_left'],
                ],
            ],

            // ── CURVA TENSIONAL DIÁRIA ───────────────────────────────────────
            'CURVA TENSIONAL DIÁRIA' => [
                'padrao' => [
                    ['placeholder' => '{{TONOMETRIA_OD}}', 'source_type' => 'medical_record', 'source_field' => 'tonometer_right'],
                    ['placeholder' => '{{TONOMETRIA_OE}}', 'source_type' => 'medical_record', 'source_field' => 'tonometer_left'],
                ],
            ],

            // ── MAPEAMENTO DE RETINA ─────────────────────────────────────────
            // (sem variáveis específicas no template padrão; Texto livre.)

            // ── TOPOGRAFIA CORNEANA ──────────────────────────────────────────
            // (sem variáveis específicas no template padrão; Texto livre.)

            // ── LAUDO OFTALMOLÓGICO ──────────────────────────────────────────
            'LAUDO OFTALMOLÓGICO' => [
                'completo' => array_merge(
                    [
                        ['placeholder' => '{{AVSC_OD}}', 'source_type' => 'medical_record', 'source_field' => 'visualAcuityTypeWithoutCorrectionRight.name'],
                        ['placeholder' => '{{AVSC_OE}}', 'source_type' => 'medical_record', 'source_field' => 'visualAcuityTypeWithoutCorrectionLeft.name'],
                        ['placeholder' => '{{AVCC_OD}}', 'source_type' => 'medical_record', 'source_field' => 'visualAcuityTypeWitCorrectionRight.name'],
                        ['placeholder' => '{{AVCC_OE}}', 'source_type' => 'medical_record', 'source_field' => 'visualAcuityTypeWitCorrectionLeft.name'],
                        ['placeholder' => '{{TONOMETRIA_OD}}', 'source_type' => 'medical_record', 'source_field' => 'tonometer_right'],
                        ['placeholder' => '{{TONOMETRIA_OE}}', 'source_type' => 'medical_record', 'source_field' => 'tonometer_left'],
                    ],
                    $refrDinamicaOD,
                    $refrDinamicaOE,
                ),
            ],

            // ── PRESCRIÇÃO DE LENTES ─────────────────────────────────────────
            'PRESCRIÇÃO DE LENTES' => [
                'dinamica_longe' => array_merge(
                    $refrDinamicaOD,
                    $refrDinamicaOE,
                    $lentes,
                ),
                'estatica_longe' => array_merge(
                    $refrEstaticaOD,
                    $refrEstaticaOE,
                    $lentes,
                ),
                'longe_perto_presbiopia' => array_merge(
                    $refrDinamicaOD,
                    $refrDinamicaOE,
                    $refrEstaticaOD,
                    $refrEstaticaOE,
                    $lentes,
                    $lentesPerto,
                ),
                'perto_presbiopia' => array_merge(
                    $refrDinamicaOD,
                    $refrDinamicaOE,
                    $lentesPerto,
                ),
            ],

            // ── RECEITUÁRIO DE CATARATA ──────────────────────────────────────
            'RECEITUÁRIO DE CATARATA' => [
                'pre_operatorio' => [
                    ['placeholder' => '{{OLHO_OPERADO}}', 'source_type' => 'custom', 'default_value' => ''],
                ],
                'pos_operatorio' => [
                    ['placeholder' => '{{OLHO_OPERADO}}', 'source_type' => 'custom', 'default_value' => ''],
                ],
                'instrucoes_cirurgicas' => [
                    ['placeholder' => '{{OLHO_OPERADO}}', 'source_type' => 'custom', 'default_value' => ''],
                    ['placeholder' => '{{DATA_CIRURGIA}}', 'source_type' => 'custom', 'default_value' => ''],
                    ['placeholder' => '{{HORA_CIRURGIA}}', 'source_type' => 'custom', 'default_value' => ''],
                ],
            ],

            // ── PRESCRIÇÃO DE MEDICAMENTOS ───────────────────────────────────
            'PRESCRIÇÃO DE MEDICAMENTOS' => [
                'padrao' => [
                    ['placeholder' => '{{PACIENTE_NOME}}', 'source_type' => 'patient', 'source_field' => 'person.full_name'],
                    ['placeholder' => '{{DATA_ATUAL}}', 'source_type' => 'date', 'source_field' => 'current'],
                    ['placeholder' => '{{LISTA_MEDICAMENTOS}}', 'source_type' => 'custom', 'default_value' => ''],
                ],
            ],

            // ── SOLICITAÇÃO DE PROCEDIMENTOS ─────────────────────────────────
            'SOLICITAÇÃO DE PROCEDIMENTOS' => [
                'padrao' => [
                    ['placeholder' => '{{PACIENTE_NOME}}', 'source_type' => 'patient', 'source_field' => 'person.full_name'],
                    ['placeholder' => '{{DATA_ATUAL}}', 'source_type' => 'date', 'source_field' => 'current'],
                    ['placeholder' => '{{PROCEDIMENTOS_SOLICITADOS}}', 'source_type' => 'custom', 'default_value' => ''],
                ],
            ],

            // ── RELATÓRIO OFTALMOLÓGICO (declaração livre) ───────────────────
            'RELATÓRIO OFTALMOLÓGICO' => [
                'declaracao_medica' => [
                    ['placeholder' => '{{CONTEUDO_LIVRE}}', 'source_type' => 'custom', 'default_value' => ''],
                ],
            ],

            // ── ATESTADO MÉDICO ──────────────────────────────────────────────
            'ATESTADO MÉDICO' => [
                'afastamento' => [
                    // Resolvidos automaticamente pelo MedicalRecordDocumentationService
                    ['placeholder' => '{{PACIENTE_NOME}}', 'source_type' => 'patient', 'source_field' => 'person.full_name'],
                    ['placeholder' => '{{DATA_ATUAL}}', 'source_type' => 'date', 'source_field' => 'current'],
                    // Preenchimento manual: médico informa o número de dias
                    ['placeholder' => '{{DIAS_AFASTAMENTO}}', 'source_type' => 'custom', 'source_field' => null],
                    ['placeholder' => '{{DIAS_AFASTAMENTO_EXTENSO}}', 'source_type' => 'custom', 'source_field' => null],
                ],
            ],

            // ── ATESTADO DE COMPARECIMENTO ───────────────────────────────────
            'ATESTADO DE COMPARECIMENTO' => [
                'comparecimento' => [
                    // Resolvidos automaticamente pelo MedicalRecordDocumentationService
                    ['placeholder' => '{{PACIENTE_NOME}}', 'source_type' => 'patient', 'source_field' => 'person.full_name'],
                    ['placeholder' => '{{DATA_ATUAL}}', 'source_type' => 'date', 'source_field' => 'current'],
                ],
            ],
        ];
    }
}
