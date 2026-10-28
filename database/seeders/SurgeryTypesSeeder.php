<?php

namespace Database\Seeders;

use App\Models\SurgeryType;
use Illuminate\Database\Seeder;

class SurgeryTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorisSurgeries = [
            1 => [
                'Facoemulsificação com implante de lente intraocular (LIO)',
                'Extração extracapsular de catarata (EECC)',
                'Extração intracapsular de catarata (EICC)',
                'Implante secundário de lente intraocular',
                'Reposicionamento de lente intraocular',
                'Troca de lente intraocular',
                'Fixação escleral de lente intraocular',
                'Capsulotomia posterior com YAG laser',
            ],
            2 => [
                'LASIK',
                'PRK (Photorefractive Keratectomy)',
                'SMILE',
                'LASEK',
                'Epi-LASIK',
                'Implante de lente intraocular fácica (ICL)',
                'Implante de anel intracorneano (Anel de Ferrara)',
                'Ceratotomia radial',
                'Ceratotomia astigmática',
            ],
            3 => [
                'Vitrectomia posterior',
                'Vitrectomia anterior',
                'Cirurgia de descolamento de retina',
                'Retinopexia pneumática',
                'Introflexão escleral',
                'Fotocoagulação a laser da retina',
                'Injeção intravítrea',
                'Remoção de membrana epirretiniana',
                'Remoção de corpo estranho intraocular',
                'Implante de óleo de silicone',
                'Remoção de óleo de silicone',
            ],
            4 => [
                'Trabeculectomia',
                'Implante de válvula de Ahmed',
                'Implante de válvula de Baerveldt',
                'Implante de válvula de Molteno',
                'Trabeculoplastia a laser',
                'Iridotomia a laser',
                'Iridectomia cirúrgica',
                'Goniotomia',
                'Canaloplastia',
                'Cirurgia MIGS (Minimally Invasive Glaucoma Surgery)',
                'Ciclofotocoagulação',
            ],
            5 => [
                'Transplante de córnea penetrante',
                'Transplante lamelar anterior profundo (DALK)',
                'Transplante endotelial (DMEK)',
                'Transplante endotelial (DSAEK)',
                'Crosslinking corneano',
                'Ceratotomia terapêutica',
                'Ceratoplastia tectônica',
            ],
            6 => [
                'Exérese de pterígio simples',
                'Exérese de pterígio com enxerto conjuntival',
                'Exérese de pterígio com membrana amniótica',
            ],
            7 => [
                'Blefaroplastia superior',
                'Blefaroplastia inferior',
                'Correção de ptose palpebral',
                'Correção de entrópio',
                'Correção de ectrópio',
                'Exérese de tumor palpebral',
                'Reconstrução palpebral',
                'Remoção de calázio',
            ],
            8 => [
                'Dacriocistorrinostomia (DCR)',
                'Sondagem lacrimal',
                'Intubação lacrimal',
                'Dacriocistectomia',
            ],
            9 => [
                'ecuo muscular',
                'essecção muscular',
                'ransposição muscular',
                'juste muscular ocular',
            ],
            10 => [
                'Descompressão orbitária',
                'Biópsia orbitária',
                'Remoção de tumor orbitário',
            ],
            11 => [
                'Sutura de córnea',
                'Sutura escleral',
                'Reparação de perfuração ocular',
                'Reconstrução ocular',
            ],
            12 => [
                'Enucleação',
                'Evisceração',
                'Exenteração orbital',
            ],
            13 => [
                'Capsulotomia YAG laser',
                'Iridotomia laser',
                'Fotocoagulação laser',
                'Trabeculoplastia laser',
            ],
        ];

        foreach ($categorisSurgeries as $category => $surgeries) {
            foreach ($surgeries as $surgery) {
                SurgeryType::query()->firstOrCreate(
                    ['name' => $surgery, 'category' => $category],
                    ['active' => true],
                );
            }
        }
    }
}
