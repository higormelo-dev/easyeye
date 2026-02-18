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
        $surgeries = [
            'Facoemulsificação com implante de lente intraocular',
            'Extração extracapsular de catarata',
            'Extração intracapsular de catarata',
            'Implante secundário de lente intraocular',
            'Troca de lente intraocular',
            'Reposicionamento de lente intraocular',
            'Capsulotomia posterior YAG Laser',
            'Vitrectomia posterior',
            'Vitrectomia anterior',
            'Vitrectomia via pars plana',
            'Membranectomia epirretiniana',
            'Cirurgia de descolamento de retina',
            'Retinopexia pneumática',
            'Implante de óleo de silicone',
            'Remoção de óleo de silicone',
            'Fotocoagulação da retina',
            'Crioterapia retinal',
            'Injeção intravítrea',
            'Remoção de corpo estranho intraocular',
            'LASIK',
            'PRK',
            'SMILE',
            'LASEK',
            'Implante de lente intraocular fácica',
            'Implante de anel intracorneano',
            'Ceratotomia radial',
            'Ceratotomia astigmática',
            'Trabeculectomia',
            'Implante de válvula de glaucoma',
            'Implante de dispositivo de drenagem',
            'Iridotomia a laser',
            'Iridectomia cirúrgica',
            'Trabeculoplastia a laser',
            'Ciclofotocoagulação',
            'Cirurgia MIGS',
            'Transplante de córnea penetrante',
            'Transplante de córnea lamelar anterior',
            'Transplante endotelial de córnea',
            'Crosslinking corneano',
            'Remoção de pterígio',
            'Ceratectomia superficial',
            'Sutura corneana',
            'Blefaroplastia superior',
            'Blefaroplastia inferior',
            'Correção de ptose palpebral',
            'Correção de entrópio',
            'Correção de ectrópio',
            'Remoção de calázio',
            'Remoção de tumor palpebral',
            'Reconstrução palpebral',
            'Cirurgia de estrabismo',
            'Recuo muscular ocular',
            'Ressecção muscular ocular',
            'Transposição muscular ocular',
            'Dacriocistorrinostomia',
            'Sondagem de vias lacrimais',
            'Implante de stent lacrimal',
            'Reconstrução de vias lacrimais',
            'Descompressão orbitária',
            'Remoção de tumor orbitário',
            'Biópsia orbitária',
            'Reconstrução orbitária',
            'Reparo de perfuração ocular',
            'Reparo de laceração ocular',
            'Reparação de trauma ocular',
            'Enucleação',
            'Evisceração',
            'Implante de prótese ocular',
            'Capsulotomia a laser',
            'Iridotomia a laser',
            'Trabeculoplastia a laser',
            'Fotocoagulação a laser',
        ];

        foreach ($surgeries as $surgery) {
            SurgeryType::query()->firstOrCreate(
                ['name' => $surgery],
                ['active' => true]
            );
        }
    }
}
