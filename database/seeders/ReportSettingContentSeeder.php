<?php

namespace Database\Seeders;

use App\Enums\DocumentationType;
use App\Models\{ReportSetting, ReportSettingContent};
use App\Services\ReportSettingService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Popula os templates de conteúdo para os modelos de documentação clínica.
 *
 * Cada entrada representa um bloco de texto/HTML que o médico pode selecionar
 * ao emitir um documento (receituário, atestado, laudo, etc.).
 *
 * Variáveis dinâmicas seguem o formato {{VARIAVEL}} e são resolvidas em tempo
 * de geração do documento pelo MedicalRecordDocumentationService::loadTemplate().
 *
 * Tipos suportados: prescription | procedure | certificate | referral | report
 */
class ReportSettingContentSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->contents() as $settingTitle => $entries) {
            $setting = ReportSetting::whereNull('entity_id')
                ->where('title', $settingTitle)
                ->first();

            if (! $setting) {
                continue;
            }

            foreach ($entries as $entry) {
                // updateOrCreate p/ que mudanças nos templates globais (ex: F4d)
                // sejam aplicadas a cada execução do seeder. Customizações por
                // entidade vivem nas cópias adotadas e NÃO são afetadas — o
                // backfill (`reports:sync-adopted`) propaga por slug, preservando
                // overrides locais que tenham slugs próprios.
                ReportSettingContent::updateOrCreate(
                    [
                        'report_setting_id' => $setting->id,
                        'type'              => $entry['type'],
                        'slug'              => $entry['slug'],
                    ],
                    [
                        'label'      => $entry['label'],
                        'content'    => $entry['content'],
                        'is_system'  => true,
                        'sort_order' => $entry['sort_order'],
                        'active'     => true,
                    ],
                );
            }
        }

        // Propaga mudanças de conteúdo dos templates globais para todas as
        // cópias adotadas pelas clínicas (idempotente — preserva customizações).
        app(ReportSettingService::class)->syncAdoptedContentsWithGlobal();
    }

    /** @return array<string, list<array{type: DocumentationType, slug: string, label: string, sort_order: int, content: string}>> */
    private function contents(): array
    {
        return [
            // ────────────────────────────────────────────────────────────────────
            // LAUDOS
            // ────────────────────────────────────────────────────────────────────

            'LAUDO DE TONÔMETRIA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p>Olho Direito: <strong>{{TONOMETRIA_OD}} mmHg</strong></p>'
                        . '<p>Olho Esquerdo: <strong>{{TONOMETRIA_OE}} mmHg</strong></p>',
                ],
            ],

            'LAUDO DE GONIOSCOPIA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>OD:</strong> {{GONIOSCOPIA_OD}}</p>'
                        . '<p><strong>OE:</strong> {{GONIOSCOPIA_OE}}</p>'
                        . '<p><strong>Conclusão:</strong> Os achados gonioscópicos descritos acima caracterizam o estado angular de cada olho. Correlacionar com pressão intra-ocular e papila óptica.</p>',
                ],
            ],

            'LAUDO DE ECOBIOMETRIA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p>Exame realizado em ambos os olhos.</p>'
                        . '<p>Constante da LIO: 118.5</p>'
                        . '<p>LIO Olho Direito: <strong>{{ECOBIOMETRIA_LIO_OD}} D</strong></p>'
                        . '<p>LIO Olho Esquerdo: <strong>{{ECOBIOMETRIA_LIO_OE}} D</strong></p>',
                ],
            ],

            'LAUDO DE ECOGRAFIA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>OLHO DIREITO</strong></p>'
                        . '<ul>'
                        . '<li>Segmento anterior: Ecos cristalinianos de baixa reflectividade.</li>'
                        . '<li>Vítreo: Câmara vítrea homogênea.</li>'
                        . '<li>Retina: Aplicada.</li>'
                        . '<li>Espaço retrobulbar: Sem alterações observáveis.</li>'
                        . '</ul>'
                        . '<p>Conclusão: Exame ecográfico dentro da normalidade.</p>'
                        . '<p><strong>OLHO ESQUERDO</strong></p>'
                        . '<ul>'
                        . '<li>Segmento anterior: Ecos cristalinianos de baixa reflectividade.</li>'
                        . '<li>Vítreo: Câmara vítrea homogênea.</li>'
                        . '<li>Retina: Aplicada.</li>'
                        . '<li>Espaço retrobulbar: Sem alterações observáveis.</li>'
                        . '</ul>'
                        . '<p>Conclusão: Exame ecográfico dentro da normalidade.</p>',
                ],
            ],

            'LAUDO OFTALMOLÓGICO' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'completo',
                    'label'      => 'Completo',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>ACUIDADE VISUAL SEM CORREÇÃO</strong></p>'
                        . '<ul>'
                        . '<li>Olho Direito: A/V {{AVSC_OD}}</li>'
                        . '<li>Olho Esquerdo: A/V {{AVSC_OE}}</li>'
                        . '</ul>'
                        . '<p><strong>PRESCRIÇÃO DE LENTES</strong></p>'
                        . '<table><thead><tr><th></th><th>Esférico</th><th>Cilindro</th><th>Eixo</th></tr></thead>'
                        . '<tbody>'
                        . '<tr><td><strong>OD</strong></td><td>{{REFRACAO_DINAMICA_OD_ESF}}</td><td>{{REFRACAO_DINAMICA_OD_CIL}}</td><td>{{REFRACAO_DINAMICA_OD_EIXO}}</td></tr>'
                        . '<tr><td><strong>OE</strong></td><td>{{REFRACAO_DINAMICA_OE_ESF}}</td><td>{{REFRACAO_DINAMICA_OE_CIL}}</td><td>{{REFRACAO_DINAMICA_OE_EIXO}}</td></tr>'
                        . '</tbody></table>'
                        . '<p><strong>ACUIDADE VISUAL COM CORREÇÃO</strong></p>'
                        . '<ul>'
                        . '<li>Olho Direito: A/V {{AVCC_OD}}</li>'
                        . '<li>Olho Esquerdo: A/V {{AVCC_OE}}</li>'
                        . '</ul>'
                        . '<p><strong>TONOMETRIA</strong></p>'
                        . '<ul>'
                        . '<li>Olho Direito: {{TONOMETRIA_OD}} mmHg</li>'
                        . '<li>Olho Esquerdo: {{TONOMETRIA_OE}} mmHg</li>'
                        . '</ul>'
                        . '<p><strong>BIOMICROSCOPIA DO SEGMENTO ANTERIOR</strong></p>'
                        . '<p><em>Olho Direito:</em> Pálpebras sem edema ou hiperemias, conjuntiva normocorada, córnea brilhante e transparente, câmara anterior profunda, íris normal, pupila centrada e isocórica, cristalino transparente.</p>'
                        . '<p><em>Olho Esquerdo:</em> Pálpebras sem edema ou hiperemias, conjuntiva normocorada, córnea brilhante e transparente, câmara anterior profunda, íris normal, pupila centrada e isocórica, cristalino transparente.</p>'
                        . '<p><strong>FUNDOSCOPIA</strong></p>'
                        . '<p><em>Olho Direito:</em> Papilas de cor, tamanho e bordas nítidos, escavação de 0.4 dp. Morfologia vascular inalterada. Mácula com reflexos preservados. Periferia sem lesões de perigo.</p>'
                        . '<p><em>Olho Esquerdo:</em> Papilas de cor, tamanho e bordas nítidos, escavação de 0.4 dp. Morfologia vascular inalterada. Mácula com reflexos preservados. Periferia sem lesões de perigo.</p>'
                        . '<p><strong>CONCLUSÃO:</strong> Normal em ambos os olhos.</p>',
                ],
            ],

            'MAPEAMENTO DE RETINA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>OLHO DIREITO</strong></p>'
                        . '<p><em>Papila Óptica:</em> Normocorada, bordos nítidos e escavação fisiológica.</p>'
                        . '<p><em>Retina:</em> Morfologia vascular preservada com relação art./veia = 2/3, ausência de fenômenos vaso-espásticos e de fenômenos de cruzamentos patológicos (Gunn e Salus). Não se evidencia microangiopatia diabética. Reflexos maculares preservados. Umbo foveal bem visível. Periferia sem lesões degenerativas.</p>'
                        . '<p><em>Vítreo:</em> Ausência de sinerese. Estrutura fibrilar preservada. Vítreo aderido.</p>'
                        . '<p><strong>OLHO ESQUERDO</strong></p>'
                        . '<p><em>Papila Óptica:</em> Normocorada, bordos nítidos e escavação fisiológica.</p>'
                        . '<p><em>Retina:</em> Morfologia vascular preservada com relação art./veia = 2/3, ausência de fenômenos vaso-espásticos e de fenômenos de cruzamentos patológicos (Gunn e Salus). Não se evidencia microangiopatia diabética. Reflexos maculares preservados. Umbo foveal bem visível. Periferia sem lesões degenerativas.</p>'
                        . '<p><em>Vítreo:</em> Ausência de sinerese. Estrutura fibrilar preservada. Vítreo aderido.</p>'
                        . '<p><strong>CONCLUSÃO:</strong> Mapeamento de Retina dentro da normalidade.</p>',
                ],
            ],

            'MICROSCOPIA ESPECULAR' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>MICROSCOPIA ESPECULAR DE CÓRNEA</strong></p>'
                        . '<p><em>Olho Direito</em></p>'
                        . '<ul>'
                        . '<li>Densidade Endotelial: 5515 céls/mm²</li>'
                        . '<li>Pleomorfismo: Ausentes</li>'
                        . '<li>Polimegatismo: Ausentes</li>'
                        . '<li>Limites Celulares: Normais</li>'
                        . '</ul>'
                        . '<p><em>Olho Esquerdo</em></p>'
                        . '<ul>'
                        . '<li>Densidade Endotelial: 5536 céls/mm²</li>'
                        . '<li>Pleomorfismo: Ausentes</li>'
                        . '<li>Polimegatismo: Ausentes</li>'
                        . '<li>Limites Celulares: Normais</li>'
                        . '</ul>'
                        . '<p><em>Parâmetros de referência:</em> Densidade Endotelial: 2000 a 3500 cél/mm². Pleomorfismo e Polimegatismo podem relacionar-se ao uso de lentes de contato ou ceratocone.</p>',
                ],
            ],

            'PAQUIMETRIA CORNEANA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>PAQUIMETRIA CORNEANA</strong></p>'
                        . '<p>Medidas medianas:</p>'
                        . '<ul>'
                        . '<li>Olho Direito: {{PAQUIMETRIA_OD}} μm</li>'
                        . '<li>Olho Esquerdo: {{PAQUIMETRIA_OE}} μm</li>'
                        . '</ul>',
                ],
            ],

            'CAMPIMETRIA COMPUTADORIZADA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p>Exame realizado no campímetro computadorizado OCULUS Twinfield® 2, utilizando programa CLIP 24-2(SF).</p>'
                        . '<p><strong>OLHO DIREITO</strong></p>'
                        . '<ul>'
                        . '<li>Índice de confiabilidade: Aceitáveis</li>'
                        . '<li>Índices globais: Normais</li>'
                        . '<li>Gray tone: Escala de tons dentro da normalidade</li>'
                        . '<li>Conclusão: Campo visual dentro da normalidade.</li>'
                        . '</ul>'
                        . '<p><strong>OLHO ESQUERDO</strong></p>'
                        . '<ul>'
                        . '<li>Índice de confiabilidade: Aceitáveis</li>'
                        . '<li>Índices globais: Normais</li>'
                        . '<li>Gray tone: Escala de tons dentro da normalidade</li>'
                        . '<li>Conclusão: Campo visual dentro da normalidade.</li>'
                        . '</ul>',
                ],
            ],

            'CURVA TENSIONAL DIÁRIA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>Tonometria registrada:</strong> OD {{TONOMETRIA_OD}} mmHg / OE {{TONOMETRIA_OE}} mmHg</p>'
                        . '<p>Análise da Curva Tensional indica Pressão Ocular Média acima de 18 mmHg, com índice de variação (desvio padrão) superior a 1,5.</p>'
                        . '<p><strong>CONCLUSÃO:</strong> Curva Tensional apresenta parâmetros sugestivos de Glaucoma Crônico de Ângulo Aberto em A/O.</p>',
                ],
            ],

            'RETINOGRAFIA COLORIDA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>OLHO DIREITO</strong></p>'
                        . '<p>Meios: Transparentes e normais.</p>'
                        . '<p>Papila Óptica: Escavação, bordos, limites e coloração respeitando os padrões de normalidade. Faixa neural normal.</p>'
                        . '<p>Vasos: Contornos, calibre e trajeto dentro do padrão da normalidade; relação arteriovenosa respeitando padrões da normalidade.</p>'
                        . '<p>Retina: Colada em toda a sua extensão, textura normal, ausência de exsudatos e/ou hemorragias retinianas.</p>'
                        . '<p>Mácula: Brilho e reflexos regulares.</p>'
                        . '<p>Coróide: Normal.</p>'
                        . '<p><strong>OLHO ESQUERDO</strong></p>'
                        . '<p>Meios: Transparentes e normais.</p>'
                        . '<p>Papila Óptica: Escavação, bordos, limites e coloração respeitando os padrões de normalidade. Faixa neural normal.</p>'
                        . '<p>Vasos: Contornos, calibre e trajeto dentro do padrão da normalidade; relação arteriovenosa respeitando padrões da normalidade.</p>'
                        . '<p>Retina: Colada em toda a sua extensão, textura normal, ausência de exsudatos e/ou hemorragias retinianas.</p>'
                        . '<p>Mácula: Brilho e reflexos regulares.</p>'
                        . '<p>Coróide: Normal.</p>',
                ],
            ],

            'LAUDO DE ANGIOFLUORESCEINOGRAFIA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>OLHO DIREITO</strong></p>'
                        . '<p>Meios: Transparentes e normais.</p>'
                        . '<p>Papila Óptica: Escavação, bordos, limites e coloração respeitando os padrões de normalidade. Faixa neural normal.</p>'
                        . '<p>Vasos: Contornos, calibre e trajeto dentro do padrão da normalidade; relação arteriovenosa respeitando padrões da normalidade.</p>'
                        . '<p>Retina: Colada em toda a sua extensão, textura normal, ausência de exsudatos e/ou hemorragias retinianas.</p>'
                        . '<p>Mácula: Brilho e reflexos regulares.</p>'
                        . '<p>Coróide: Normal.</p>'
                        . '<p><strong>OLHO ESQUERDO</strong></p>'
                        . '<p>Meios: Transparentes e normais.</p>'
                        . '<p>Papila Óptica: Escavação, bordos, limites e coloração respeitando os padrões de normalidade. Faixa neural normal.</p>'
                        . '<p>Vasos: Contornos, calibre e trajeto dentro do padrão da normalidade; relação arteriovenosa respeitando padrões da normalidade.</p>'
                        . '<p>Retina: Colada em toda a sua extensão, textura normal, ausência de exsudatos e/ou hemorragias retinianas.</p>'
                        . '<p>Mácula: Brilho e reflexos regulares.</p>'
                        . '<p>Coróide: Normal.</p>'
                        . '<p><strong>ANGIOGRAMA PADRÃO</strong></p>'
                        . '<ul>'
                        . '<li>Papila com limites precisos em A/O</li>'
                        . '<li>Relação A–V de 1:3 em A/O</li>'
                        . '<li>Morfologia do tapete retiniano normal em A/O</li>'
                        . '</ul>'
                        . '<p><strong>FLUORESCEINOGRAMA</strong></p>'
                        . '<ul>'
                        . '<li>Tempo braço-retina de 18 segundos</li>'
                        . '<li>Papila com perfusão normal em AO</li>'
                        . '<li>Fluorescência BackGround não evidencia lesões hiper/hipofluorescentes</li>'
                        . '<li>Arcadas foveolares íntegras</li>'
                        . '<li>Morfologia vascular e dos cruzamentos arteriovenosos normais</li>'
                        . '</ul>'
                        . '<p><strong>CONCLUSÃO:</strong> Exame dentro da normalidade.</p>',
                ],
            ],

            'OCT (TOMOGRAFIA DE COERÊNCIA ÓTICA)' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>Olho Direito</strong></p>'
                        . '<ul>'
                        . '<li>Cavidade vítrea: sinal hiper-refletivo junto à superfície retiniana.</li>'
                        . '<li>Interface vítreo-retiniana-macular: superfície normo-reflexiva, sem sinais óbvios de tração vítreo-macular patológica.</li>'
                        . '<li>Retina neuro-sensorial: altura foveal dentro dos limites da normalidade, contorno macular normo-reflexivo.</li>'
                        . '<li>Complexo EPR/Bruch: ausência de split E/B em região macular com homogeneidade de sinais.</li>'
                        . '<li>Complexo vascular coroídeo: Normal.</li>'
                        . '</ul>'
                        . '<p><strong>Olho Esquerdo</strong></p>'
                        . '<ul>'
                        . '<li>Cavidade vítrea: sinal hiper-refletivo junto à superfície retiniana.</li>'
                        . '<li>Interface vítreo-retiniana-macular: superfície normo-reflexiva, sem sinais óbvios de tração vítreo-macular patológica.</li>'
                        . '<li>Retina neuro-sensorial: altura foveal dentro dos limites da normalidade, contorno macular normo-reflexivo.</li>'
                        . '<li>Complexo EPR/Bruch: ausência de split E/B em região macular com homogeneidade de sinais.</li>'
                        . '<li>Complexo vascular coroídeo: Normal.</li>'
                        . '</ul>'
                        . '<p><strong>Conclusão</strong></p>'
                        . '<ul>'
                        . '<li>Olho Direito: OCT dentro dos limites normais.</li>'
                        . '<li>Olho Esquerdo: OCT dentro dos limites normais.</li>'
                        . '</ul>',
                ],
            ],

            'ESTUDO COMPUTADORIZADO DA LÁGRIMA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '{{CABECALHO_PACIENTE}}'
                        . '<p>Paciente apresenta intensa sintomatologia inerente ao olho seco para ambos os olhos, porém demais aspectos analisados para os dois olhos apresentam-se dentro dos limites da normalidade.</p>',
                ],
            ],

            // ── TOPOGRAFIA CORNEANA ──────────────────────────────────────────────
            'TOPOGRAFIA CORNEANA' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>OLHO DIREITO</strong></p>'
                        . '<ul>'
                        . '<li>Qualidade do exame: boa.</li>'
                        . '<li>Padrão topográfico: regular, com astigmatismo simétrico.</li>'
                        . '<li>Ceratometria simulada: K1 e K2 dentro dos limites da normalidade.</li>'
                        . '<li>Ausência de sinais de irregularidade ou ectasia corneana.</li>'
                        . '</ul>'
                        . '<p><strong>OLHO ESQUERDO</strong></p>'
                        . '<ul>'
                        . '<li>Qualidade do exame: boa.</li>'
                        . '<li>Padrão topográfico: regular, com astigmatismo simétrico.</li>'
                        . '<li>Ceratometria simulada: K1 e K2 dentro dos limites da normalidade.</li>'
                        . '<li>Ausência de sinais de irregularidade ou ectasia corneana.</li>'
                        . '</ul>'
                        . '<p><strong>CONCLUSÃO:</strong> Topografia corneana dentro dos padrões da normalidade em ambos os olhos.</p>',
                ],
            ],

            // ── PENTACAM ─────────────────────────────────────────────────────────
            'PENTACAM' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 0,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p><strong>PENTACAM — Análise Tomográfica da Córnea</strong></p>'
                        . '<p><em>Olho Direito:</em> Mapas paquimétrico, elevação anterior/posterior, curvatura e Belin-Ambrósio dentro dos padrões da normalidade.</p>'
                        . '<p><em>Olho Esquerdo:</em> Mapas paquimétrico, elevação anterior/posterior, curvatura e Belin-Ambrósio dentro dos padrões da normalidade.</p>'
                        . '<p><strong>CONCLUSÃO:</strong> Exame Pentacam dentro dos padrões da normalidade em ambos os olhos.</p>',
                ],
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'ceratocone_oval',
                    'label'      => 'Ceratocone Oval',
                    'sort_order' => 1,
                    'content'    => '<p>Astigmatismo regular assimétrico com curvatura inferior aumentada, compatível com ceratocone oval.</p>',
                ],
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'ceratocone_bow_tie',
                    'label'      => 'Ceratocone Bow Tie',
                    'sort_order' => 2,
                    'content'    => '<p>Astigmatismo regular simétrico com altas curvaturas centrais, compatível com ceratocone bow tie.</p>',
                ],
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'ceratocone_avancado',
                    'label'      => 'Ceratocone Avançado',
                    'sort_order' => 3,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '<p><strong>Mapa Geral</strong></p>'
                        . '<ul>'
                        . '<li>Qualidade do exame: boa.</li>'
                        . '<li>Superfície anterior da córnea: astigmatismo irregular de 7.0D.</li>'
                        . '<li>Ceratometria simulada: K1 50.5D e K2 57.6D — meridiano mais plano a 8.7°.</li>'
                        . '<li>Paquimetria do ápice da córnea: 414 μm.</li>'
                        . '<li>Paquimetria do ponto mais fino: 400 μm.</li>'
                        . '<li>Volume da câmara anterior: 209 mm³.</li>'
                        . '<li>Profundidade da câmara anterior: 3,55 mm.</li>'
                        . '<li>Diâmetro pupilar: 3,40 mm.</li>'
                        . '</ul>'
                        . '<p><strong>Mapa Topométrico</strong></p>'
                        . '<ul>'
                        . '<li>Kmáx: 69.0D.</li>'
                        . '<li>Índices de assimetria: alterados.</li>'
                        . '</ul>'
                        . '<p><strong>Belin-Ambrósio Enhanced Ectasia</strong></p>'
                        . '<ul>'
                        . '<li>Belin ABCD Keratoconus Staging: A4B4C2.</li>'
                        . '<li>Mapa de elevação anterior diferencial: alterado.</li>'
                        . '<li>Mapa de elevação posterior diferencial: alterado.</li>'
                        . '<li>Curva de distribuição paquimétrica: alterada.</li>'
                        . '<li>ARTmax: 91. BAD D: 15,80.</li>'
                        . '</ul>',
                ],
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'laudo_refrativo',
                    'label'      => 'Laudo Refrativo',
                    'sort_order' => 4,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '<p><strong>LAUDO REFRATIVO — Mapa Geral</strong></p>'
                        . '<ul>'
                        . '<li>Qualidade do exame: boa.</li>'
                        . '<li>Superfície anterior da córnea: astigmatismo regular oblíquo, com assimetria inferior, de 2.4D.</li>'
                        . '<li>Ceratometria simulada: K1 44.1D e K2 46.5D — meridiano mais plano a 27.3°.</li>'
                        . '<li>Paquimetria do ápice da córnea: 485 μm.</li>'
                        . '<li>Paquimetria do ponto mais fino: 483 μm.</li>'
                        . '<li>Volume da câmara anterior: 180 mm³.</li>'
                        . '<li>Profundidade da câmara anterior: 3,33 mm.</li>'
                        . '<li>Diâmetro pupilar: 3,46 mm.</li>'
                        . '</ul>'
                        . '<p><strong>Mapa Topométrico</strong></p>'
                        . '<ul>'
                        . '<li>Kmáx: 47.5D. Qvalue vertical: −0.20. IHD 0.014.</li>'
                        . '</ul>'
                        . '<p><strong>Belin-Ambrósio Enhanced Ectasia</strong></p>'
                        . '<ul>'
                        . '<li>Mapa de elevação anterior diferencial: normal.</li>'
                        . '<li>Mapa de elevação posterior diferencial: normal.</li>'
                        . '<li>ARTmax: 449. BAD D: 1.94.</li>'
                        . '</ul>',
                ],
            ],

            // ────────────────────────────────────────────────────────────────────
            // PRESCRIÇÕES
            // ────────────────────────────────────────────────────────────────────

            'PRESCRIÇÃO DE LENTES' => [
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'dinamica_longe',
                    'label'      => 'Dinâmica (Longe)',
                    'sort_order' => 1,
                    'content'    => '<table>'
                        . '<thead><tr><th></th><th>Esférico</th><th>Cilíndrico</th><th>Eixo</th></tr></thead>'
                        . '<tbody>'
                        . '<tr><td><strong>Olho Direito</strong></td><td>{{REFRACAO_DINAMICA_OD_ESF}}</td><td>{{REFRACAO_DINAMICA_OD_CIL}}</td><td>{{REFRACAO_DINAMICA_OD_EIXO}}</td></tr>'
                        . '<tr><td><strong>Olho Esquerdo</strong></td><td>{{REFRACAO_DINAMICA_OE_ESF}}</td><td>{{REFRACAO_DINAMICA_OE_CIL}}</td><td>{{REFRACAO_DINAMICA_OE_EIXO}}</td></tr>'
                        . '</tbody></table>'
                        . '<p>Confeccionar lentes {{LENTE_LONGE}} para longe.</p>'
                        . '<p>Observação: {{LENTE_OBSERVACAO}}.</p>',
                ],
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'estatica_longe',
                    'label'      => 'Estática (Longe)',
                    'sort_order' => 2,
                    'content'    => '<table>'
                        . '<thead><tr><th></th><th>Esférico</th><th>Cilíndrico</th><th>Eixo</th></tr></thead>'
                        . '<tbody>'
                        . '<tr><td><strong>Olho Direito</strong></td><td>{{REFRACAO_ESTATICA_OD_ESF}}</td><td>{{REFRACAO_ESTATICA_OD_CIL}}</td><td>{{REFRACAO_ESTATICA_OD_EIXO}}</td></tr>'
                        . '<tr><td><strong>Olho Esquerdo</strong></td><td>{{REFRACAO_ESTATICA_OE_ESF}}</td><td>{{REFRACAO_ESTATICA_OE_CIL}}</td><td>{{REFRACAO_ESTATICA_OE_EIXO}}</td></tr>'
                        . '</tbody></table>'
                        . '<p>Confeccionar lentes {{LENTE_LONGE}} para longe.</p>'
                        . '<p>Observação: {{LENTE_OBSERVACAO}}.</p>',
                ],
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'longe_perto_presbiopia',
                    'label'      => 'Longe e Perto (Presbiopia)',
                    'sort_order' => 3,
                    'content'    => '<p><strong>LONGE</strong></p>'
                        . '<table>'
                        . '<thead><tr><th></th><th>Esférico</th><th>Cilíndrico</th><th>Eixo</th></tr></thead>'
                        . '<tbody>'
                        . '<tr><td><strong>Olho Direito</strong></td><td>{{REFRACAO_DINAMICA_OD_ESF}}</td><td>{{REFRACAO_DINAMICA_OD_CIL}}</td><td>{{REFRACAO_DINAMICA_OD_EIXO}}</td></tr>'
                        . '<tr><td><strong>Olho Esquerdo</strong></td><td>{{REFRACAO_DINAMICA_OE_ESF}}</td><td>{{REFRACAO_DINAMICA_OE_CIL}}</td><td>{{REFRACAO_DINAMICA_OE_EIXO}}</td></tr>'
                        . '</tbody></table>'
                        . '<p><strong>PERTO</strong></p>'
                        . '<table>'
                        . '<thead><tr><th></th><th>Esférico</th><th>Cilíndrico</th><th>Eixo</th></tr></thead>'
                        . '<tbody>'
                        . '<tr><td><strong>Olho Direito</strong></td><td>{{REFRACAO_ESTATICA_OD_ESF}}</td><td>{{REFRACAO_ESTATICA_OD_CIL}}</td><td>{{REFRACAO_ESTATICA_OD_EIXO}}</td></tr>'
                        . '<tr><td><strong>Olho Esquerdo</strong></td><td>{{REFRACAO_ESTATICA_OE_ESF}}</td><td>{{REFRACAO_ESTATICA_OE_CIL}}</td><td>{{REFRACAO_ESTATICA_OE_EIXO}}</td></tr>'
                        . '</tbody></table>'
                        . '<p>Confeccionar lentes {{LENTE_LONGE}} para longe e {{LENTE_PERTO}} para perto.</p>'
                        . '<p>Observação: {{LENTE_OBSERVACAO}}.</p>',
                ],
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'perto_presbiopia',
                    'label'      => 'Perto Apenas (Presbiopia)',
                    'sort_order' => 4,
                    'content'    => '<p><strong>PERTO</strong></p>'
                        . '<table>'
                        . '<thead><tr><th></th><th>Esférico</th><th>Cilíndrico</th><th>Eixo</th></tr></thead>'
                        . '<tbody>'
                        . '<tr><td><strong>Olho Direito</strong></td><td>{{REFRACAO_DINAMICA_OD_ESF}}</td><td>{{REFRACAO_DINAMICA_OD_CIL}}</td><td>{{REFRACAO_DINAMICA_OD_EIXO}}</td></tr>'
                        . '<tr><td><strong>Olho Esquerdo</strong></td><td>{{REFRACAO_DINAMICA_OE_ESF}}</td><td>{{REFRACAO_DINAMICA_OE_CIL}}</td><td>{{REFRACAO_DINAMICA_OE_EIXO}}</td></tr>'
                        . '</tbody></table>'
                        . '<p>Confeccionar lentes {{LENTE_PERTO}} para perto.</p>',
                ],
            ],

            'PRESCRIÇÃO DE MEDICAMENTOS' => [
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p><strong>Prescrição Medicamentosa</strong></p>'
                        . '<p>Paciente: {{PACIENTE_NOME}}</p>'
                        . '<p>Data: {{DATA_ATUAL}}</p>'
                        . '<p>{{LISTA_MEDICAMENTOS}}</p>',
                ],
            ],

            'RECEITUÁRIO DE PTERÍGIO' => [
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'pos_operatorio',
                    'label'      => 'Pós-Operatório',
                    'sort_order' => 1,
                    'content'    => '<p><strong>Uso Externo:</strong></p>'
                        . '<p>1- Cylocort (colírio) — Instilar 01 gota no olho operado 04 vezes ao dia durante 07 dias.</p>'
                        . '<p>2- Lacrifilm (colírio) — Instilar 01 gota nos olhos 06 vezes ao dia.</p>'
                        . '<p><strong>Uso Interno:</strong></p>'
                        . '<p>1- Voltaren (comprimido 50mg) — Tomar 01 comprimido de 12 em 12 horas durante 05 dias.</p>'
                        . '<p><strong>Instruções após cirurgia:</strong></p>'
                        . '<ul>'
                        . '<li>Dieta livre.</li>'
                        . '<li>Evitar poeira e banhos de piscina no primeiro mês.</li>'
                        . '<li>Atividades normais no trabalho e em casa.</li>'
                        . '<li>O uso de lubrificantes oculares será necessário à vida toda, especialmente nos 06 primeiros meses após a cirurgia.</li>'
                        . '</ul>',
                ],
            ],

            // ────────────────────────────────────────────────────────────────────
            // PROCEDIMENTOS / DECLARAÇÕES
            // ────────────────────────────────────────────────────────────────────

            'SOLICITAÇÃO DE PROCEDIMENTOS' => [
                [
                    'type'       => DocumentationType::Procedure,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p><strong>Solicitação de Procedimentos</strong></p>'
                        . '<p>Paciente: {{PACIENTE_NOME}}</p>'
                        . '<p>Data: {{DATA_ATUAL}}</p>'
                        . '<p>{{PROCEDIMENTOS_SOLICITADOS}}</p>',
                ],
            ],

            'RELATÓRIO OFTALMOLÓGICO' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'declaracao_medica',
                    'label'      => 'Declaração Médica',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '{{CABECALHO_PACIENTE}}'
                        . '<p>{{CONTEUDO_LIVRE}}</p>',
                ],
            ],

            // ── RECEITUÁRIO DE CATARATA ──────────────────────────────────────────
            'RECEITUÁRIO DE CATARATA' => [
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'pre_operatorio',
                    'label'      => 'Pré-Operatório',
                    'sort_order' => 1,
                    'content'    => '<p><strong>PRÉ-OPERATÓRIO</strong></p>'
                        . '<p><strong>Uso Externo:</strong></p>'
                        . '<p>1) Mydriacyl (colírio) — Instilar 01 gota no olho a ser operado de 20 em 20 minutos, a partir de 02 horas antes do início previsto da cirurgia.</p>'
                        . '<p>2) Fenilefrina 10% (colírio) — Mesma posologia do item 1.</p>'
                        . '<p>3) Cetrolac (colírio) — Instilar 01 gota no olho a ser operado 03 vezes ao dia, a partir de 01 dia antes da cirurgia; continuar por 30 dias após.</p>'
                        . '<p>4) Vigamox — moxifloxacino 0,5% (colírio) — Instilar 01 gota no olho operado 04 vezes ao dia, a partir de 01 dia antes da cirurgia; continuar por 07 dias após.</p>'
                        . '<p><strong>Olho a ser operado: {{OLHO_OPERADO}}.</strong></p>',
                ],
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'pos_operatorio',
                    'label'      => 'Pós-Operatório',
                    'sort_order' => 2,
                    'content'    => '<p><strong>PÓS-OPERATÓRIO</strong></p>'
                        . '<p><strong>Uso Externo:</strong></p>'
                        . '<p>1) Ster MD (colírio) — Instilar 01 gota no olho operado:</p>'
                        . '<ul>'
                        . '<li>03 em 03 horas durante 07 dias;</li>'
                        . '<li>Após, de 06 em 06 horas durante 07 dias;</li>'
                        . '<li>Após, de 08 em 08 horas durante 07 dias;</li>'
                        . '<li>E 01 vez ao dia durante 05 dias.</li>'
                        . '</ul>'
                        . '<p><strong>Uso Interno:</strong></p>'
                        . '<p>1) Diclofenaco de sódio (comprimido 50mg) — Tomar 01 comprimido de 12 em 12 horas durante 07 dias.</p>'
                        . '<p>2) Cefadroxil (comprimido 500mg) — Tomar 01 comprimido de 12 em 12 horas durante 05 dias.</p>'
                        . '<p><strong>Olho operado: {{OLHO_OPERADO}}.</strong></p>',
                ],
                [
                    'type'       => DocumentationType::Prescription,
                    'slug'       => 'instrucoes_cirurgicas',
                    'label'      => 'Instruções Cirúrgicas',
                    'sort_order' => 3,
                    'content'    => '<p><strong>INSTRUÇÕES PRÉ-CIRÚRGICAS</strong></p>'
                        . '<p>Dia anterior à cirurgia:</p>'
                        . '<ul>'
                        . '<li>Lavar o rosto com sabão neutro; não utilizar creme ou pintura na face.</li>'
                        . '<li>Continuar suas medicações prescritas normalmente.</li>'
                        . '</ul>'
                        . '<p><strong>Data da Cirurgia: {{DATA_CIRURGIA}} às {{HORA_CIRURGIA}}.</strong></p>'
                        . '<p><strong>Olho a ser operado: {{OLHO_OPERADO}}.</strong></p>'
                        . '<p>Observações:</p>'
                        . '<ul>'
                        . '<li>Fazer refeições leves.</li>'
                        . '<li>Chegar 01 hora antes do início previsto da cirurgia.</li>'
                        . '</ul>'
                        . '<p><strong>INSTRUÇÕES PÓS-CIRÚRGICAS</strong></p>'
                        . '<ul>'
                        . '<li>Não dormir do lado operado nos primeiros dois dias.</li>'
                        . '<li>Evitar abaixar a cabeça ou fazer movimentos bruscos.</li>'
                        . '<li>Assistir televisão normalmente.</li>'
                        . '<li>Após o 2º dia, pode lavar o cabelo normalmente.</li>'
                        . '<li>Em caso de dúvidas, contate a clínica.</li>'
                        . '</ul>',
                ],
            ],

            // ────────────────────────────────────────────────────────────────────
            // ATESTADOS
            // ────────────────────────────────────────────────────────────────────

            'ATESTADO MÉDICO' => [
                [
                    'type'       => DocumentationType::Certificate,
                    'slug'       => 'afastamento',
                    'label'      => 'Afastamento',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '<p>Atesto para os devidos fins de direito que {{PACIENTE_NOME}} está sob meus cuidados médicos, devendo o(a) mesmo(a) ser afastado(a) de suas atividades profissionais por um período de {{DIAS_AFASTAMENTO}} ({{DIAS_AFASTAMENTO_EXTENSO}}) dia(s), a partir de {{DATA_ATUAL}}, por motivo de tratamento oftalmológico.</p>',
                ],
            ],

            'ATESTADO DE COMPARECIMENTO' => [
                [
                    'type'       => DocumentationType::Certificate,
                    'slug'       => 'comparecimento',
                    'label'      => 'Comparecimento',
                    'sort_order' => 1,
                    'content'    => '<p style="text-align:right">{{LOCAL_DATA}}</p>'
                        . '<p>&nbsp;</p>'
                        . '<p>Atesto para os devidos fins de direito que {{PACIENTE_NOME}} esteve nesta clínica sob meus cuidados médicos no dia {{DATA_ATUAL}}, para procedimentos clínicos oftalmológicos.</p>',
                ],
            ],

            // ────────────────────────────────────────────────────────────────────
            // TRIAGEM
            // ────────────────────────────────────────────────────────────────────

            'TESTE DO OLHINHO' => [
                [
                    'type'       => DocumentationType::Report,
                    'slug'       => 'padrao',
                    'label'      => 'Padrão',
                    'sort_order' => 1,
                    'content'    => '<p><strong>OLHO DIREITO</strong><br>Reflexo vermelho retiniano presente.</p>'
                        . '<p><strong>OLHO ESQUERDO</strong><br>Reflexo vermelho retiniano presente.</p>'
                        . '<p><strong>Observação:</strong> A realização do Teste do Olhinho não exclui a necessidade do exame oftalmológico anual de rotina.</p>',
                ],
            ],
        ];
    }
}
