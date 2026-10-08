<?php

/*
|--------------------------------------------------------------------------
| Segurança clínica — Fase 2 / Bloco A (A2 e A4)
|--------------------------------------------------------------------------
|
| ARQUIVO DE DADOS ÚNICO das regras clínicas — para o médico VALIDAR.
| Nada aqui é lógica: o código (App\Support\ClinicalSafety\*) só compara.
|
|  - Princípios ativos sempre em minúsculas e SEM acento (a comparação
|    normaliza o que vem do catálogo CMED, do cadastro e do texto livre do
|    mesmo jeito — TermNormalizer).
|  - Um termo de várias palavras casa como sequência inteira ("acido
|    zoledronico"); as palavras de `ingredient_stopwords` (sais, hidratação,
|    conectivos) são ignoradas dos dois lados — "diclofenaco sódico" casa
|    com "diclofenaco potássico" e com "diclofenaco de sódio".
|  - `group` e `alert` vêm nos dois idiomas da tela (pt_BR e en).
|  - `source`: referência que embasa o alerta (o médico confere).
|
| Mudou uma regra? Rode `./vendor/bin/pest --filter=ClinicalSafetyRules`.
*/

return [
    /*
    | Palavras ignoradas ao comparar princípios ativos e textos. NÃO incluir
    | palavras que sozinhas identificam substância ("sodio", "cloreto").
    */
    'ingredient_stopwords' => [
        'de', 'do', 'da', 'dos', 'das', 'e', 'com',
        'sodico', 'sodica', 'potassico', 'potassica', 'calcico', 'calcica', 'magnesico', 'dissodico', 'dissodica',
        'cloridrato', 'dicloridrato', 'bromidrato', 'maleato', 'acetato', 'fosfato', 'sulfato', 'succinato',
        'propionato', 'dipropionato', 'valerato', 'tartarato', 'hemitartarato', 'mesilato', 'besilato', 'citrato',
        'monoidratado', 'monoidratada', 'diidratado', 'diidratada', 'trihidratado', 'triidratado', 'tri',
        'hidratado', 'hidratada', 'anidro', 'anidra', 'base',
    ],

    /*
    |--------------------------------------------------------------------------
    | A2 — Alertas de risco ocular por princípio ativo (medicações em uso)
    |--------------------------------------------------------------------------
    |
    | `persists_after_stop`: o risco continua depois de SUSPENDER o remédio?
    |   true  → a medicação suspensa segue alertando (sem prazo);
    |   int   → segue alertando por N dias depois da suspensão;
    |   ausente/false → só medicação ativa alerta.
    | Medicação DESCARTADA (registro indevido) nunca alerta. O alerta mostra
    | "suspensa em dd/mm/aaaa". Decisões (médico validar):
    |   - alfa-1 bloqueadores: sempre — IFIS descrita anos após parar (Chang 2005);
    |   - hidroxicloroquina/cloroquina: sempre — toxicidade cumulativa; a
    |     maculopatia pode progredir após parar (AAO, Marmor 2016);
    |   - amiodarona: 180 dias — meia-vida longa (~2 meses, acúmulo em tecido);
    |     a ceratopatia regride em meses após parar;
    |   - etambutol: não — sem risco novo depois de parar; dano já instalado é
    |     achado de exame, não alerta de medicação.
    */
    'ocular_risk_rules' => [
        'alpha1_blockers' => [
            'group' => [
                'pt_BR' => 'Alfa-1 bloqueadores',
                'en'    => 'Alpha-1 blockers',
            ],
            'ingredients' => ['tansulosina', 'tamsulosina', 'alfuzosina', 'doxazosina', 'silodosina', 'terazosina', 'prazosina'],
            'alert'       => [
                'pt_BR' => 'Risco de síndrome da íris flácida intraoperatória (IFIS) na cirurgia de catarata — o risco persiste mesmo após suspender o medicamento.',
                'en'    => 'Risk of intraoperative floppy iris syndrome (IFIS) in cataract surgery — the risk persists even after the drug is stopped.',
            ],
            'source'              => 'Chang DF, Campbell JR. Intraoperative floppy iris syndrome associated with tamsulosin. J Cataract Refract Surg. 2005;31(4):664-673.',
            'persists_after_stop' => true,
        ],

        'anticoagulants' => [
            'group' => [
                'pt_BR' => 'Anticoagulantes',
                'en'    => 'Anticoagulants',
            ],
            'ingredients' => ['varfarina', 'rivaroxabana', 'apixabana', 'dabigatrana', 'edoxabana', 'enoxaparina', 'heparina'],
            'alert'       => [
                'pt_BR' => 'Risco hemorrágico em bloqueio anestésico (retro/peribulbar), cirurgia e injeção intravítrea — avaliar suspensão/ponte com o médico assistente.',
                'en'    => 'Bleeding risk in anesthetic blocks (retro/peribulbar), surgery and intravitreal injection — discuss interruption/bridging with the prescribing physician.',
            ],
            'source' => 'Kong KL, Khan J. Ophthalmic patients on antithrombotic drugs: a review and guide to perioperative management. Br J Ophthalmol. 2015;99(8):1025-1030. Royal College of Anaesthetists & Royal College of Ophthalmologists. Local Anaesthesia for Ophthalmic Surgery (joint guidelines), 2012.',
        ],

        'antiplatelets' => [
            'group' => [
                'pt_BR' => 'Antiagregantes plaquetários',
                'en'    => 'Antiplatelet agents',
            ],
            'ingredients' => ['clopidogrel', 'ticagrelor', 'prasugrel'],
            'alert'       => [
                'pt_BR' => 'Risco hemorrágico em bloqueio anestésico (retro/peribulbar), cirurgia e injeção intravítrea — avaliar com o médico assistente.',
                'en'    => 'Bleeding risk in anesthetic blocks (retro/peribulbar), surgery and intravitreal injection — discuss with the prescribing physician.',
            ],
            'source' => 'Kong KL, Khan J. Ophthalmic patients on antithrombotic drugs: a review and guide to perioperative management. Br J Ophthalmol. 2015;99(8):1025-1030.',
        ],

        'antimalarials' => [
            'group' => [
                'pt_BR' => 'Antimaláricos (hidroxicloroquina/cloroquina)',
                'en'    => 'Antimalarials (hydroxychloroquine/chloroquine)',
            ],
            'ingredients' => ['hidroxicloroquina', 'cloroquina'],
            'alert'       => [
                'pt_BR' => 'Rastreio de maculopatia tóxica: exame basal e anual após 5 anos de uso (antes, se fator de risco) — campo visual 10-2 e OCT macular.',
                'en'    => 'Screen for toxic maculopathy: baseline exam and yearly after 5 years of use (earlier with risk factors) — 10-2 visual field and macular OCT.',
            ],
            'source'              => 'Marmor MF, Kellner U, Lai TY, Melles RB, Mieler WF; American Academy of Ophthalmology. Recommendations on Screening for Chloroquine and Hydroxychloroquine Retinopathy (2016 Revision). Ophthalmology. 2016;123(6):1386-1394.',
            'persists_after_stop' => true,
        ],

        'systemic_corticosteroids' => [
            'group' => [
                'pt_BR' => 'Corticoides sistêmicos',
                'en'    => 'Systemic corticosteroids',
            ],
            'ingredients' => ['prednisona', 'prednisolona', 'dexametasona', 'metilprednisolona', 'betametasona', 'deflazacorte', 'hidrocortisona'],
            'alert'       => [
                'pt_BR' => 'Risco de aumento da pressão intraocular (glaucoma cortisônico) e de catarata subcapsular posterior — acompanhar PIO.',
                'en'    => 'Risk of raised intraocular pressure (steroid-induced glaucoma) and posterior subcapsular cataract — monitor IOP.',
            ],
            'source' => 'Jones R 3rd, Rhee DJ. Corticosteroid-induced ocular hypertension and glaucoma: a brief review and update of the literature. Curr Opin Ophthalmol. 2006;17(2):163-167. Black RL, Oglesby RB, von Sallmann L, Bunim JJ. Posterior subcapsular cataracts induced by corticosteroids in patients with rheumatoid arthritis. JAMA. 1960;174:166-171.',
        ],

        'topiramate' => [
            'group' => [
                'pt_BR' => 'Topiramato',
                'en'    => 'Topiramate',
            ],
            'ingredients' => ['topiramato'],
            'alert'       => [
                'pt_BR' => 'Risco de glaucoma agudo de ângulo fechado bilateral e miopia aguda (efeito ciliocoroidal), em geral no primeiro mês de uso.',
                'en'    => 'Risk of bilateral acute angle-closure glaucoma and acute myopia (ciliochoroidal effusion), usually within the first month of use.',
            ],
            'source' => 'Fraunfelder FW, Fraunfelder FT, Keates EU. Topiramate-associated acute, bilateral, secondary angle-closure glaucoma. Ophthalmology. 2004;111(1):109-111.',
        ],

        'amiodarone' => [
            'group' => [
                'pt_BR' => 'Amiodarona',
                'en'    => 'Amiodarone',
            ],
            'ingredients' => ['amiodarona'],
            'alert'       => [
                'pt_BR' => 'Ceratopatia em vórtice (córnea verticilata) e risco de neuropatia óptica.',
                'en'    => 'Vortex keratopathy (cornea verticillata) and risk of optic neuropathy.',
            ],
            'source'              => 'Mäntyjärvi M, Tuppurainen K, Ikäheimo K. Ocular side effects of amiodarone. Surv Ophthalmol. 1998;42(4):360-366.',
            'persists_after_stop' => 180,
        ],

        'ethambutol' => [
            'group' => [
                'pt_BR' => 'Etambutol',
                'en'    => 'Ethambutol',
            ],
            'ingredients' => ['etambutol'],
            'alert'       => [
                'pt_BR' => 'Risco de neuropatia óptica tóxica — acompanhar acuidade visual, visão de cores e campo visual.',
                'en'    => 'Risk of toxic optic neuropathy — monitor visual acuity, color vision and visual field.',
            ],
            'source'              => 'Chamberlain PD, Sadaka A, Berry S, Lee AG. Ethambutol optic neuropathy. Curr Opin Ophthalmol. 2017;28(6):545-551.',
            'persists_after_stop' => false,
        ],

        'isotretinoin' => [
            'group' => [
                'pt_BR' => 'Isotretinoína',
                'en'    => 'Isotretinoin',
            ],
            'ingredients' => ['isotretinoina'],
            'alert'       => [
                'pt_BR' => 'Olho seco (disfunção das glândulas de Meibomius), intolerância a lente de contato e cegueira noturna.',
                'en'    => 'Dry eye (meibomian gland dysfunction), contact lens intolerance and night blindness.',
            ],
            'source' => 'Fraunfelder FT, Fraunfelder FW, Edwards R. Ocular side effects possibly associated with isotretinoin usage. Am J Ophthalmol. 2001;132(3):299-305.',
        ],

        'tamoxifen' => [
            'group' => [
                'pt_BR' => 'Tamoxifeno',
                'en'    => 'Tamoxifen',
            ],
            'ingredients' => ['tamoxifeno'],
            'alert'       => [
                'pt_BR' => 'Risco de retinopatia cristalina e de catarata — avaliar a retina periodicamente.',
                'en'    => 'Risk of crystalline retinopathy and cataract — examine the retina periodically.',
            ],
            'source' => 'Gorin MB, Day R, Costantino JP, et al. Long-term tamoxifen citrate use and potential ocular toxicity. Am J Ophthalmol. 1998;125(4):493-501.',
        ],

        'pde5_inhibitors' => [
            'group' => [
                'pt_BR' => 'Inibidores da fosfodiesterase-5',
                'en'    => 'Phosphodiesterase-5 inhibitors',
            ],
            'ingredients' => ['sildenafila', 'sildenafil', 'tadalafila', 'tadalafil', 'vardenafila', 'vardenafil'],
            'alert'       => [
                'pt_BR' => 'Alteração da visão de cores (cianopsia) e risco de neuropatia óptica isquêmica anterior não arterítica.',
                'en'    => 'Color vision changes (cyanopsia) and risk of non-arteritic anterior ischemic optic neuropathy.',
            ],
            'source' => 'Laties AM. Vision disorders and phosphodiesterase type 5 inhibitors: a review of the evidence to date. Drug Saf. 2009;32(1):1-18.',
        ],

        'bisphosphonates' => [
            'group' => [
                'pt_BR' => 'Bifosfonatos',
                'en'    => 'Bisphosphonates',
            ],
            'ingredients' => ['alendronato', 'risedronato', 'acido zoledronico', 'zoledronato', 'ibandronato', 'pamidronato'],
            'alert'       => [
                'pt_BR' => 'Risco de uveíte anterior e esclerite (inflamação ocular), em geral nas primeiras semanas.',
                'en'    => 'Risk of anterior uveitis and scleritis (ocular inflammation), usually within the first weeks.',
            ],
            'source' => 'Fraunfelder FW, Fraunfelder FT. Bisphosphonates and ocular inflammation. N Engl J Med. 2003;348(12):1187-1188. Etminan M, Forooghian F, Maberley D. Inflammatory ocular adverse events with the use of oral bisphosphonates: a retrospective cohort study. CMAJ. 2012;184(8):E431-E434.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | A4 — Classes para a checagem de alergia na receita
    |--------------------------------------------------------------------------
    |
    | Alergia que bate com `members` ou `aliases` de uma classe (ex.: alergia
    | a "penicilina", "sulfa" ou "amoxicilina") alerta para QUALQUER membro da
    | classe na receita (alergia a amoxicilina → cefalexina avisa). Aviso, não
    | bloqueio: o médico segue com motivo (fica na auditoria).
    |
    | `eye_drops: true` — o conservante não aparece no princípio ativo do
    | catálogo: com alergia a ele, todo COLÍRIO receitado avisa para confirmar
    | a formulação sem conservante.
    */
    'allergy_classes' => [
        'beta_lactams' => [
            'label' => [
                'pt_BR' => 'Penicilinas / beta-lactâmicos (inclui cefalosporinas e carbapenêmicos — reatividade cruzada)',
                'en'    => 'Penicillins / beta-lactams (includes cephalosporins and carbapenems — cross-reactivity)',
            ],
            'aliases' => ['penicilinas', 'betalactamico', 'betalactamicos', 'beta lactamico', 'beta lactamicos', 'cefalosporina', 'cefalosporinas'],
            'members' => [
                'penicilina', 'benzilpenicilina', 'fenoximetilpenicilina', 'amoxicilina', 'ampicilina', 'oxacilina',
                'dicloxacilina', 'piperacilina', 'cefalexina', 'cefadroxila', 'cefadroxil', 'cefazolina', 'cefalotina',
                'cefaclor', 'cefuroxima', 'cefoxitina', 'ceftriaxona', 'cefotaxima', 'ceftazidima', 'cefepima',
                'imipenem', 'meropenem', 'ertapenem',
            ],
            'source' => 'Khan DA, Banerji A, Blumenthal KG, et al. Drug allergy: a 2022 practice parameter update. J Allergy Clin Immunol. 2022;150(6):1333-1393.',
        ],

        'sulfonamides' => [
            'label' => [
                'pt_BR' => 'Sulfonamidas (inclui inibidores da anidrase carbônica — a bula contraindica com alergia a sulfa)',
                'en'    => 'Sulfonamides (includes carbonic anhydrase inhibitors — labeled contraindication in sulfa allergy)',
            ],
            'aliases' => ['sulfa', 'sulfas', 'sulfonamida', 'sulfonamidas'],
            'members' => [
                'sulfametoxazol', 'sulfacetamida', 'sulfadiazina', 'sulfassalazina',
                'acetazolamida', 'dorzolamida', 'brinzolamida', 'metazolamida',
            ],
            'source' => 'Bulas (Anvisa) de dorzolamida, brinzolamida e acetazolamida: contraindicação/advertência em hipersensibilidade a sulfonamidas. Strom BL, et al. Absence of cross-reactivity between sulfonamide antibiotics and sulfonamide nonantibiotics. N Engl J Med. 2003;349(17):1628-1635.',
        ],

        'quinolones' => [
            'label' => [
                'pt_BR' => 'Quinolonas / fluoroquinolonas',
                'en'    => 'Quinolones / fluoroquinolones',
            ],
            'aliases' => ['quinolona', 'quinolonas', 'fluoroquinolona', 'fluoroquinolonas'],
            'members' => [
                'moxifloxacino', 'gatifloxacino', 'ciprofloxacino', 'ofloxacino', 'levofloxacino', 'norfloxacino',
                'besifloxacino', 'lomefloxacino',
            ],
            'source' => 'Khan DA, Banerji A, Blumenthal KG, et al. Drug allergy: a 2022 practice parameter update. J Allergy Clin Immunol. 2022;150(6):1333-1393.',
        ],

        'aminoglycosides' => [
            'label' => [
                'pt_BR' => 'Aminoglicosídeos',
                'en'    => 'Aminoglycosides',
            ],
            'aliases' => ['aminoglicosideo', 'aminoglicosideos'],
            'members' => ['tobramicina', 'gentamicina', 'neomicina', 'amicacina', 'estreptomicina'],
            'source'  => 'Bulas (Anvisa) de tobramicina, gentamicina e neomicina: contraindicadas em hipersensibilidade a aminoglicosídeos (reatividade cruzada na classe).',
        ],

        'nsaids' => [
            'label' => [
                'pt_BR' => 'Anti-inflamatórios não esteroidais (AINE)',
                'en'    => 'Non-steroidal anti-inflammatory drugs (NSAIDs)',
            ],
            'aliases' => ['aine', 'aines', 'antiinflamatorio', 'antiinflamatorios', 'anti inflamatorio', 'anti inflamatorios', 'aas'],
            'members' => [
                'cetorolaco', 'nepafenaco', 'bromfenaco', 'diclofenaco', 'ibuprofeno', 'flurbiprofeno', 'cetoprofeno',
                'naproxeno', 'nimesulida', 'piroxicam', 'meloxicam', 'indometacina', 'acido acetilsalicilico',
            ],
            'source' => 'Kowalski ML, et al. Classification and practical approach to the diagnosis and management of hypersensitivity to nonsteroidal anti-inflammatory drugs. Allergy. 2013;68(10):1219-1232.',
        ],

        'topical_anesthetics' => [
            'label' => [
                'pt_BR' => 'Anestésicos tópicos oculares (tipo éster)',
                'en'    => 'Topical ocular anesthetics (ester type)',
            ],
            'aliases' => ['anestesico topico', 'anestesicos topicos', 'colirio anestesico'],
            'members' => ['proximetacaina', 'proparacaina', 'tetracaina', 'oxibuprocaina', 'benoxinato'],
            'source'  => 'Bulas (Anvisa) de proximetacaína, tetracaína e oxibuprocaína: contraindicadas em hipersensibilidade ao fármaco ou a anestésicos do tipo éster.',
        ],

        'local_anesthetics_amide' => [
            'label' => [
                'pt_BR' => 'Anestésicos locais do tipo amida (bloqueio)',
                'en'    => 'Amide-type local anesthetics (blocks)',
            ],
            'aliases' => ['anestesico local', 'anestesicos locais'],
            'members' => ['lidocaina', 'xilocaina', 'bupivacaina', 'ropivacaina', 'mepivacaina', 'levobupivacaina'],
            'source'  => 'Bulas (Anvisa) de lidocaína e bupivacaína: contraindicadas em hipersensibilidade a anestésicos locais do tipo amida.',
        ],

        'iodine' => [
            'label' => [
                'pt_BR' => 'Iodo / iodopovidona',
                'en'    => 'Iodine / povidone-iodine',
            ],
            'aliases' => ['iodo', 'iodado', 'iodados'],
            'members' => ['iodopovidona', 'povidona iodo', 'polivinilpirrolidona iodo', 'pvpi'],
            'source'  => 'Bula (Anvisa) de iodopovidona: contraindicada em hipersensibilidade ao iodo.',
        ],

        'benzalkonium' => [
            'label' => [
                'pt_BR' => 'Conservante cloreto de benzalcônio (colírios multidose)',
                'en'    => 'Benzalkonium chloride preservative (multi-dose eye drops)',
            ],
            'aliases'   => ['bak', 'conservante'],
            'members'   => ['cloreto de benzalconio', 'benzalconio'],
            'eye_drops' => true,
            'source'    => 'Baudouin C, Labbé A, Liang H, Pauly A, Brignole-Baudouin F. Preservatives in eyedrops: the good, the bad and the ugly. Prog Retin Eye Res. 2010;29(4):312-334.',
        ],
    ],
];
