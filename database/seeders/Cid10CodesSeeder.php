<?php

namespace Database\Seeders;

use App\Models\Cid10Code;
use Illuminate\Database\Seeder;

class Cid10CodesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->codes() as $code) {
            Cid10Code::firstOrCreate(
                ['code' => $code['code']],
                ['description' => $code['description'], 'category' => $code['category']],
            );
        }
    }

    /** @return array<int, array{code: string, description: string, category: string}> */
    private function codes(): array
    {
        return [
            // ── H00–H06 Pálpebras ─────────────────────────────────────────────
            ['code' => 'H00.0', 'description' => 'Hordeolo e outras inflamações profundas das pálpebras', 'category' => 'Pálpebras'],
            ['code' => 'H00.1', 'description' => 'Calázio', 'category' => 'Pálpebras'],
            ['code' => 'H01.0', 'description' => 'Blefarite', 'category' => 'Pálpebras'],
            ['code' => 'H01.1', 'description' => 'Dermatose não infecciosa da pálpebra', 'category' => 'Pálpebras'],
            ['code' => 'H02.0', 'description' => 'Entrópio e triquíase palpebral', 'category' => 'Pálpebras'],
            ['code' => 'H02.1', 'description' => 'Ectrópio da pálpebra', 'category' => 'Pálpebras'],
            ['code' => 'H02.2', 'description' => 'Lagoftalmo', 'category' => 'Pálpebras'],
            ['code' => 'H02.3', 'description' => 'Blefarocalaze', 'category' => 'Pálpebras'],
            ['code' => 'H02.4', 'description' => 'Ptose da pálpebra', 'category' => 'Pálpebras'],
            ['code' => 'H02.5', 'description' => 'Outros transtornos que afetam a função palpebral', 'category' => 'Pálpebras'],
            ['code' => 'H02.6', 'description' => 'Xantelasma da pálpebra', 'category' => 'Pálpebras'],
            ['code' => 'H04.0', 'description' => 'Dacrioadenite', 'category' => 'Pálpebras'],
            ['code' => 'H04.1', 'description' => 'Outros transtornos da glândula lacrimal', 'category' => 'Pálpebras'],
            ['code' => 'H04.2', 'description' => 'Epífora', 'category' => 'Pálpebras'],
            ['code' => 'H04.3', 'description' => 'Inflamação aguda e não especificada das vias lacrimais', 'category' => 'Pálpebras'],
            ['code' => 'H04.4', 'description' => 'Inflamação crônica das vias lacrimais', 'category' => 'Pálpebras'],
            ['code' => 'H04.5', 'description' => 'Estenose e insuficiência das vias lacrimais', 'category' => 'Pálpebras'],
            ['code' => 'H05.0', 'description' => 'Celulite orbitária', 'category' => 'Pálpebras'],
            ['code' => 'H05.1', 'description' => 'Transtornos inflamatórios crônicos da órbita', 'category' => 'Pálpebras'],
            ['code' => 'H05.2', 'description' => 'Condições exoftálmicas', 'category' => 'Pálpebras'],

            // ── H10–H13 Conjuntiva ────────────────────────────────────────────
            ['code' => 'H10.0', 'description' => 'Conjuntivite mucopurulenta', 'category' => 'Conjuntiva'],
            ['code' => 'H10.1', 'description' => 'Conjuntivite atópica aguda', 'category' => 'Conjuntiva'],
            ['code' => 'H10.2', 'description' => 'Outras conjuntivites agudas', 'category' => 'Conjuntiva'],
            ['code' => 'H10.3', 'description' => 'Conjuntivite aguda não especificada', 'category' => 'Conjuntiva'],
            ['code' => 'H10.4', 'description' => 'Conjuntivite crônica', 'category' => 'Conjuntiva'],
            ['code' => 'H10.5', 'description' => 'Blefaroconjuntivite', 'category' => 'Conjuntiva'],
            ['code' => 'H11.0', 'description' => 'Pterígio', 'category' => 'Conjuntiva'],
            ['code' => 'H11.1', 'description' => 'Degenerações e depósitos conjuntivais', 'category' => 'Conjuntiva'],
            ['code' => 'H11.2', 'description' => 'Cicatrizes conjuntivais', 'category' => 'Conjuntiva'],
            ['code' => 'H11.3', 'description' => 'Hemorragia conjuntival', 'category' => 'Conjuntiva'],
            ['code' => 'H11.4', 'description' => 'Outros transtornos vasculares conjuntivais e cistos', 'category' => 'Conjuntiva'],
            ['code' => 'H13.1', 'description' => 'Conjuntivite em doenças infecciosas e parasitárias', 'category' => 'Conjuntiva'],

            // ── H15–H22 Esclera, Córnea, Íris e Corpo Ciliar ─────────────────
            ['code' => 'H15.0', 'description' => 'Esclerite', 'category' => 'Córnea e Esclera'],
            ['code' => 'H15.1', 'description' => 'Episclerite', 'category' => 'Córnea e Esclera'],
            ['code' => 'H16.0', 'description' => 'Úlcera de córnea', 'category' => 'Córnea e Esclera'],
            ['code' => 'H16.1', 'description' => 'Outras ceratites superficiais sem conjuntivite', 'category' => 'Córnea e Esclera'],
            ['code' => 'H16.2', 'description' => 'Ceratoconjuntivite', 'category' => 'Córnea e Esclera'],
            ['code' => 'H16.3', 'description' => 'Ceratite intersticial e profunda', 'category' => 'Córnea e Esclera'],
            ['code' => 'H16.4', 'description' => 'Neovascularização da córnea', 'category' => 'Córnea e Esclera'],
            ['code' => 'H17.0', 'description' => 'Leucoma aderente', 'category' => 'Córnea e Esclera'],
            ['code' => 'H17.1', 'description' => 'Outras opacidades corneanas centrais', 'category' => 'Córnea e Esclera'],
            ['code' => 'H17.8', 'description' => 'Outras cicatrizes e opacidades corneanas', 'category' => 'Córnea e Esclera'],
            ['code' => 'H18.0', 'description' => 'Pigmentação e depósitos da córnea', 'category' => 'Córnea e Esclera'],
            ['code' => 'H18.1', 'description' => 'Ceratopatia bolhosa', 'category' => 'Córnea e Esclera'],
            ['code' => 'H18.2', 'description' => 'Outros edemas da córnea', 'category' => 'Córnea e Esclera'],
            ['code' => 'H18.3', 'description' => 'Alterações nas membranas da córnea', 'category' => 'Córnea e Esclera'],
            ['code' => 'H18.5', 'description' => 'Distrofias hereditárias da córnea', 'category' => 'Córnea e Esclera'],
            ['code' => 'H18.6', 'description' => 'Ceratocone', 'category' => 'Córnea e Esclera'],
            ['code' => 'H18.7', 'description' => 'Outras degenerações da córnea', 'category' => 'Córnea e Esclera'],
            ['code' => 'H20.0', 'description' => 'Iridociclite aguda e subaguda', 'category' => 'Íris e Corpo Ciliar'],
            ['code' => 'H20.1', 'description' => 'Iridociclite crônica', 'category' => 'Íris e Corpo Ciliar'],
            ['code' => 'H20.2', 'description' => 'Iridociclite induzida pelo cristalino', 'category' => 'Íris e Corpo Ciliar'],
            ['code' => 'H21.0', 'description' => 'Hifema', 'category' => 'Íris e Corpo Ciliar'],
            ['code' => 'H21.1', 'description' => 'Outros transtornos vasculares da íris e do corpo ciliar', 'category' => 'Íris e Corpo Ciliar'],
            ['code' => 'H21.2', 'description' => 'Degeneração da íris e do corpo ciliar', 'category' => 'Íris e Corpo Ciliar'],
            ['code' => 'H21.3', 'description' => 'Cistos da íris, do corpo ciliar e da câmara anterior', 'category' => 'Íris e Corpo Ciliar'],
            ['code' => 'H21.4', 'description' => 'Membranas pupilares', 'category' => 'Íris e Corpo Ciliar'],

            // ── H25–H28 Cristalino ────────────────────────────────────────────
            ['code' => 'H25.0', 'description' => 'Catarata senil cortical incipiente', 'category' => 'Cristalino'],
            ['code' => 'H25.1', 'description' => 'Catarata senil nuclear', 'category' => 'Cristalino'],
            ['code' => 'H25.2', 'description' => 'Catarata senil polar posterior', 'category' => 'Cristalino'],
            ['code' => 'H25.8', 'description' => 'Outras cataratas senis', 'category' => 'Cristalino'],
            ['code' => 'H25.9', 'description' => 'Catarata senil não especificada', 'category' => 'Cristalino'],
            ['code' => 'H26.0', 'description' => 'Catarata infantil e juvenil', 'category' => 'Cristalino'],
            ['code' => 'H26.1', 'description' => 'Catarata traumática', 'category' => 'Cristalino'],
            ['code' => 'H26.2', 'description' => 'Catarata complicada', 'category' => 'Cristalino'],
            ['code' => 'H26.3', 'description' => 'Catarata induzida por drogas', 'category' => 'Cristalino'],
            ['code' => 'H26.4', 'description' => 'Catarata residual', 'category' => 'Cristalino'],
            ['code' => 'H26.9', 'description' => 'Catarata não especificada', 'category' => 'Cristalino'],
            ['code' => 'H27.0', 'description' => 'Afacia', 'category' => 'Cristalino'],
            ['code' => 'H27.1', 'description' => 'Deslocamento do cristalino', 'category' => 'Cristalino'],
            ['code' => 'H28.0', 'description' => 'Catarata diabética', 'category' => 'Cristalino'],
            ['code' => 'H28.1', 'description' => 'Catarata em outras doenças endócrinas, nutricionais e metabólicas', 'category' => 'Cristalino'],

            // ── H30–H36 Coróide e Retina ──────────────────────────────────────
            ['code' => 'H30.0', 'description' => 'Coriorretinite focal', 'category' => 'Retina e Coróide'],
            ['code' => 'H30.1', 'description' => 'Coriorretinite disseminada', 'category' => 'Retina e Coróide'],
            ['code' => 'H31.0', 'description' => 'Cicatrizes coriorretinianas', 'category' => 'Retina e Coróide'],
            ['code' => 'H31.1', 'description' => 'Degeneração da coróide', 'category' => 'Retina e Coróide'],
            ['code' => 'H31.2', 'description' => 'Distrofia hereditária da coróide', 'category' => 'Retina e Coróide'],
            ['code' => 'H31.3', 'description' => 'Hemorragia e ruptura da coróide', 'category' => 'Retina e Coróide'],
            ['code' => 'H31.4', 'description' => 'Descolamento da coróide', 'category' => 'Retina e Coróide'],
            ['code' => 'H33.0', 'description' => 'Descolamento da retina com ruptura', 'category' => 'Retina e Coróide'],
            ['code' => 'H33.1', 'description' => 'Retinosquise e cistos da retina', 'category' => 'Retina e Coróide'],
            ['code' => 'H33.2', 'description' => 'Descolamento seroso da retina', 'category' => 'Retina e Coróide'],
            ['code' => 'H33.3', 'description' => 'Defeitos retinianos sem descolamento', 'category' => 'Retina e Coróide'],
            ['code' => 'H33.4', 'description' => 'Descolamento da retina por tração', 'category' => 'Retina e Coróide'],
            ['code' => 'H34.0', 'description' => 'Oclusão transitória da artéria retiniana', 'category' => 'Retina e Coróide'],
            ['code' => 'H34.1', 'description' => 'Oclusão da artéria central da retina', 'category' => 'Retina e Coróide'],
            ['code' => 'H34.2', 'description' => 'Outras oclusões da artéria retiniana', 'category' => 'Retina e Coróide'],
            ['code' => 'H34.8', 'description' => 'Outras oclusões vasculares retinianas', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.0', 'description' => 'Retinopatia de fundo e alterações vasculares da retina', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.1', 'description' => 'Retinopatia da prematuridade', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.2', 'description' => 'Outras retinopatias proliferativas', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.3', 'description' => 'Degeneração da mácula e do polo posterior', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.4', 'description' => 'Degenerações periféricas da retina', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.5', 'description' => 'Distrofias hereditárias da retina', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.6', 'description' => 'Hemorragia retiniana', 'category' => 'Retina e Coróide'],
            ['code' => 'H35.7', 'description' => 'Separação das camadas da retina', 'category' => 'Retina e Coróide'],
            ['code' => 'H36.0', 'description' => 'Retinopatia diabética', 'category' => 'Retina e Coróide'],

            // ── H40–H42 Glaucoma ──────────────────────────────────────────────
            ['code' => 'H40.0', 'description' => 'Suspeita de glaucoma', 'category' => 'Glaucoma'],
            ['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto', 'category' => 'Glaucoma'],
            ['code' => 'H40.2', 'description' => 'Glaucoma primário de ângulo fechado', 'category' => 'Glaucoma'],
            ['code' => 'H40.3', 'description' => 'Glaucoma secundário a traumatismo ocular', 'category' => 'Glaucoma'],
            ['code' => 'H40.4', 'description' => 'Glaucoma secundário a processo inflamatório ocular', 'category' => 'Glaucoma'],
            ['code' => 'H40.5', 'description' => 'Glaucoma secundário a outros transtornos do olho', 'category' => 'Glaucoma'],
            ['code' => 'H40.6', 'description' => 'Glaucoma secundário a drogas', 'category' => 'Glaucoma'],
            ['code' => 'H40.8', 'description' => 'Outro glaucoma', 'category' => 'Glaucoma'],
            ['code' => 'H40.9', 'description' => 'Glaucoma não especificado', 'category' => 'Glaucoma'],
            ['code' => 'H42.0', 'description' => 'Glaucoma em doenças endócrinas, nutricionais e metabólicas', 'category' => 'Glaucoma'],
            ['code' => 'H42.8', 'description' => 'Glaucoma em outras doenças classificadas em outra parte', 'category' => 'Glaucoma'],

            // ── H43–H45 Corpo Vítreo e Globo Ocular ──────────────────────────
            ['code' => 'H43.0', 'description' => 'Prolapso do vítreo', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H43.1', 'description' => 'Hemorragia do vítreo', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H43.2', 'description' => 'Depósitos cristalinos no vítreo', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H43.3', 'description' => 'Outras opacidades do vítreo', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H44.0', 'description' => 'Endoftalmite purulenta', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H44.1', 'description' => 'Outra endoftalmite', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H44.2', 'description' => 'Miopia degenerativa', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H44.3', 'description' => 'Outras doenças degenerativas do globo ocular', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H44.5', 'description' => 'Afecções degenerativas do globo ocular', 'category' => 'Vítreo e Globo Ocular'],
            ['code' => 'H44.6', 'description' => 'Corpo estranho magnético retido no globo ocular', 'category' => 'Vítreo e Globo Ocular'],

            // ── H46–H48 Nervo Óptico e Vias Visuais ──────────────────────────
            ['code' => 'H46', 'description' => 'Neurite óptica', 'category' => 'Nervo Óptico'],
            ['code' => 'H47.0', 'description' => 'Transtornos do nervo óptico não classificados em outra parte', 'category' => 'Nervo Óptico'],
            ['code' => 'H47.1', 'description' => 'Papiledema', 'category' => 'Nervo Óptico'],
            ['code' => 'H47.2', 'description' => 'Atrofia óptica', 'category' => 'Nervo Óptico'],
            ['code' => 'H47.3', 'description' => 'Outros transtornos do disco óptico', 'category' => 'Nervo Óptico'],
            ['code' => 'H47.4', 'description' => 'Transtornos do quiasma óptico', 'category' => 'Nervo Óptico'],
            ['code' => 'H47.5', 'description' => 'Transtornos de outras vias ópticas', 'category' => 'Nervo Óptico'],
            ['code' => 'H48.0', 'description' => 'Atrofia óptica em doenças classificadas em outra parte', 'category' => 'Nervo Óptico'],

            // ── H49–H52 Músculos Oculares e Distúrbios da Refração ───────────
            ['code' => 'H49.0', 'description' => 'Paralisia do nervo oculomotor (III par)', 'category' => 'Motilidade e Refração'],
            ['code' => 'H49.1', 'description' => 'Paralisia do nervo troclear (IV par)', 'category' => 'Motilidade e Refração'],
            ['code' => 'H49.2', 'description' => 'Paralisia do nervo abducente (VI par)', 'category' => 'Motilidade e Refração'],
            ['code' => 'H49.3', 'description' => 'Oftalmoplegia total (externa)', 'category' => 'Motilidade e Refração'],
            ['code' => 'H49.4', 'description' => 'Oftalmoplegia externa progressiva', 'category' => 'Motilidade e Refração'],
            ['code' => 'H50.0', 'description' => 'Esotropia concomitante', 'category' => 'Motilidade e Refração'],
            ['code' => 'H50.1', 'description' => 'Exotropia concomitante', 'category' => 'Motilidade e Refração'],
            ['code' => 'H50.2', 'description' => 'Estrabismo vertical', 'category' => 'Motilidade e Refração'],
            ['code' => 'H50.3', 'description' => 'Heterotropia intermitente', 'category' => 'Motilidade e Refração'],
            ['code' => 'H50.4', 'description' => 'Heteroforia', 'category' => 'Motilidade e Refração'],
            ['code' => 'H50.5', 'description' => 'Estrabismo paralítico', 'category' => 'Motilidade e Refração'],
            ['code' => 'H51.0', 'description' => 'Paralisia da conjugação do olhar', 'category' => 'Motilidade e Refração'],
            ['code' => 'H51.1', 'description' => 'Insuficiência de convergência e excesso', 'category' => 'Motilidade e Refração'],
            ['code' => 'H52.0', 'description' => 'Hipermetropia', 'category' => 'Motilidade e Refração'],
            ['code' => 'H52.1', 'description' => 'Miopia', 'category' => 'Motilidade e Refração'],
            ['code' => 'H52.2', 'description' => 'Astigmatismo', 'category' => 'Motilidade e Refração'],
            ['code' => 'H52.3', 'description' => 'Anisometropia e anisoeiconia', 'category' => 'Motilidade e Refração'],
            ['code' => 'H52.4', 'description' => 'Presbiopia', 'category' => 'Motilidade e Refração'],
            ['code' => 'H52.5', 'description' => 'Transtornos da acomodação', 'category' => 'Motilidade e Refração'],
            ['code' => 'H52.6', 'description' => 'Outros transtornos da refração', 'category' => 'Motilidade e Refração'],

            // ── H53–H54 Distúrbios Visuais e Cegueira ────────────────────────
            ['code' => 'H53.0', 'description' => 'Ambliopia ex-anopsia', 'category' => 'Visão e Cegueira'],
            ['code' => 'H53.1', 'description' => 'Distúrbios visuais subjetivos', 'category' => 'Visão e Cegueira'],
            ['code' => 'H53.2', 'description' => 'Diplopia', 'category' => 'Visão e Cegueira'],
            ['code' => 'H53.3', 'description' => 'Outros transtornos da visão binocular', 'category' => 'Visão e Cegueira'],
            ['code' => 'H53.4', 'description' => 'Defeitos do campo visual', 'category' => 'Visão e Cegueira'],
            ['code' => 'H53.5', 'description' => 'Anomalias da visão cromática', 'category' => 'Visão e Cegueira'],
            ['code' => 'H53.6', 'description' => 'Cegueira noturna', 'category' => 'Visão e Cegueira'],
            ['code' => 'H54.0', 'description' => 'Cegueira em ambos os olhos', 'category' => 'Visão e Cegueira'],
            ['code' => 'H54.1', 'description' => 'Cegueira em um olho e visão subnormal no outro', 'category' => 'Visão e Cegueira'],
            ['code' => 'H54.2', 'description' => 'Visão subnormal de ambos os olhos', 'category' => 'Visão e Cegueira'],

            // ── H55–H59 Outros Transtornos do Olho ───────────────────────────
            ['code' => 'H55', 'description' => 'Nistagmo e outros movimentos oculares irregulares', 'category' => 'Outros'],
            ['code' => 'H57.0', 'description' => 'Anomalias da função pupilar', 'category' => 'Outros'],
            ['code' => 'H57.1', 'description' => 'Dor ocular', 'category' => 'Outros'],
            ['code' => 'H57.8', 'description' => 'Outros transtornos especificados do olho e anexos', 'category' => 'Outros'],
            ['code' => 'H58.0', 'description' => 'Anomalias da função pupilar em doenças classificadas em outra parte', 'category' => 'Outros'],
            ['code' => 'H59.0', 'description' => 'Síndrome do vítreo após cirurgia de catarata', 'category' => 'Outros'],
            ['code' => 'H59.8', 'description' => 'Outros transtornos do olho e anexos após procedimentos', 'category' => 'Outros'],

            // ── Doenças sistêmicas com repercussão oftalmológica ──────────────
            ['code' => 'E10.3', 'description' => 'Diabetes mellitus tipo 1 com complicações oftálmicas', 'category' => 'Sistêmico'],
            ['code' => 'E11.3', 'description' => 'Diabetes mellitus tipo 2 com complicações oftálmicas', 'category' => 'Sistêmico'],
            ['code' => 'E14.3', 'description' => 'Diabetes mellitus não especificado com complicações oftálmicas', 'category' => 'Sistêmico'],
            ['code' => 'I10', 'description' => 'Hipertensão essencial (primária)', 'category' => 'Sistêmico'],
            ['code' => 'I12.9', 'description' => 'Doença renal hipertensiva sem insuficiência renal', 'category' => 'Sistêmico'],
            ['code' => 'B30', 'description' => 'Conjuntivite viral', 'category' => 'Sistêmico'],
            ['code' => 'B30.0', 'description' => 'Ceratoconjuntivite devida a adenovírus', 'category' => 'Sistêmico'],
            ['code' => 'B30.1', 'description' => 'Conjuntivite devida a adenovírus', 'category' => 'Sistêmico'],
            ['code' => 'B00.3', 'description' => 'Doença ocular herpética', 'category' => 'Sistêmico'],
            ['code' => 'A18.5', 'description' => 'Tuberculose do olho', 'category' => 'Sistêmico'],
            ['code' => 'M05.2', 'description' => 'Artrite reumatoide com vasculite', 'category' => 'Sistêmico'],
            ['code' => 'M32.1', 'description' => 'Lúpus eritematoso sistêmico com manifestações em outros órgãos e sistemas', 'category' => 'Sistêmico'],
            ['code' => 'G35', 'description' => 'Esclerose múltipla', 'category' => 'Sistêmico'],
            ['code' => 'D86.8', 'description' => 'Sarcoidose de outros sítios especificados', 'category' => 'Sistêmico'],

            // ── Malformações congênitas ───────────────────────────────────────
            ['code' => 'Q10.0', 'description' => 'Ptose congênita', 'category' => 'Congênito'],
            ['code' => 'Q10.3', 'description' => 'Outras malformações congênitas das pálpebras', 'category' => 'Congênito'],
            ['code' => 'Q11.0', 'description' => 'Olho cístico', 'category' => 'Congênito'],
            ['code' => 'Q11.1', 'description' => 'Outras anoftalmias', 'category' => 'Congênito'],
            ['code' => 'Q11.2', 'description' => 'Microftalmia', 'category' => 'Congênito'],
            ['code' => 'Q11.3', 'description' => 'Macroftalmia', 'category' => 'Congênito'],
            ['code' => 'Q12.0', 'description' => 'Catarata congênita', 'category' => 'Congênito'],
            ['code' => 'Q12.1', 'description' => 'Deslocamento congênito do cristalino', 'category' => 'Congênito'],
            ['code' => 'Q13.0', 'description' => 'Coloboma da íris', 'category' => 'Congênito'],
            ['code' => 'Q13.1', 'description' => 'Ausência de íris', 'category' => 'Congênito'],
            ['code' => 'Q13.4', 'description' => 'Outros defeitos congênitos da córnea', 'category' => 'Congênito'],
            ['code' => 'Q14.1', 'description' => 'Malformação congênita da retina', 'category' => 'Congênito'],
            ['code' => 'Q14.2', 'description' => 'Malformação congênita do vítreo', 'category' => 'Congênito'],
            ['code' => 'Q14.3', 'description' => 'Malformação congênita da coróide', 'category' => 'Congênito'],
            ['code' => 'Q15.0', 'description' => 'Glaucoma congênito', 'category' => 'Congênito'],
        ];
    }
}
