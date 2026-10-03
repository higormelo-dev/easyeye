<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Layouts conhecidos de imagens de exame exportadas pelos equipamentos — para
 * tarjar os dados do paciente antes de a imagem ir para a IA
 * (ExamImageDeidentifier). Retângulos em pixels da resolução de referência
 * ([x, y, largura, altura]):
 *
 * - anchors: regiões FIXAS da tela (título, rótulos, logotipo — nunca dado do
 *   paciente) com a impressão digital (grade de luminância) que reconhece o
 *   layout. Mesmo layout entre pacientes diferentes: distância 0; entre
 *   layouts diferentes: ≥ 18 (limite de reconhecimento: 12).
 * - redact: campos do paciente (nome, nascimento, ID, data/hora do exame,
 *   texto livre do exame) pintados de preto.
 *
 * Calibrado em 03/10/2026 com exportações reais (Pentacam com telas em
 * português; Keratograph, Pachycam e EM-3000 em inglês). Outro idioma, outra
 * resolução ou outro relatório com a ficha em outro lugar não é reconhecido
 * e a imagem fica fora da IA (bloqueio). Para incluir um layout: rode
 * `php artisan ai:exam-image-layout <imagem> --anchor=x,y,w,h` numa amostra,
 * confira as regiões e a tarja e acrescente aqui com o teste.
 */
final class ExamImageLayouts
{
    /**
     * @return list<array{key: string, label: string, width: int, height: int, anchors: list<array{rect: array{int, int, int, int}, signature: string}>, redact: list<array{int, int, int, int}>}>
     */
    public static function all(): array
    {
        return [
            [
                'key'     => 'oculus_pentacam_panel_a_pt',
                'label'   => 'Oculus Pentacam (PT) — ficha à esquerda (Mapas, 4 Mapas…)',
                'width'   => 800,
                'height'  => 646,
                'anchors' => [
                    ['rect' => [10, 4, 390, 30], 'signature' => 'f0bb9cd69ea3b6d3a8e2b6f0b295adf0f0f0f0f0a195aaa8afbbb69a9ad69cd89cbbaac797dfa8f090f067b9d390c078d890f08b90a6f0f0daf0f07a867fa8a17186c0c08b7390f0d37d82596e84f0b29cca979aa892af9eb29aad929cf0f0daf0f0adf0a6a8a8d684cece9aa8ad9ca19c95aa76ad'],
                    ['rect' => [5, 45, 71, 96], 'signature' => 'd6d5ccd0d3d2eee0d2cdd3ced1edc8b2b3d2f0f0f0e1deeeeef0f0f0d5c8f0f0f0f0f0d0d1d8ecf0f0f0dfcad4eaf0f0f0c9b5b5b5b6c2ade0e8deeeedeeedd6bacebaafe9f0'],
                ],
                'redact' => [[76, 44, 152, 98]],
            ],
            [
                'key'     => 'oculus_pentacam_panel_b_pt',
                'label'   => 'Oculus Pentacam (PT) — ficha à esquerda (Av. Refrativa, Anéis…)',
                'width'   => 800,
                'height'  => 646,
                'anchors' => [
                    ['rect' => [10, 4, 390, 30], 'signature' => 'f0bb9cd69ea3b6d3a8e2b6f0b295adf0f0f0f0f0a195aaa8afbbb69a9ad69cd89cbbaac797dfa8f090f067b9d390c078d890f08b90a6f0f0daf0f07a867fa8a17186c0c08b7390f0d37d82596e84f0b29cca979aa892af9eb29aad929cf0f0daf0f0adf0a6a8a8d684cece9aa8ad9ca19c95aa76ad'],
                    ['rect' => [5, 44, 79, 90], 'signature' => 'e5e0e1e4e3e5eff2e1c4c3c2c5bdeef0d7d0cdddf0f0f0f0ead3d4e5f0f0f0f0cdd3f0f0f0f0f0f0ede6f0f0f0f0f0f0d0aebce7f0f0f0f0e6edeeebe8eaf0f0dabab6b9bfc7aab8'],
                ],
                'redact' => [[85, 44, 178, 90]],
            ],
            [
                'key'     => 'oculus_pentacam_belin_pt',
                'label'   => 'Oculus Pentacam (PT) — Ectasia Reforçada Belin',
                'width'   => 800,
                'height'  => 646,
                'anchors' => [
                    ['rect' => [10, 4, 390, 30], 'signature' => 'f0bb9cd69ea3b6d3a8e2b6f0b295adf0f0f0f0f0a195aaa8afbbb69a9ad69cd89cbbaac797dfa8f090f067b9d390c078d890f08b90a6f0f0daf0f07a867fa8a17186c0c08b7390f0d37d82596e84f0b29cca979aa892af9eb29aad929cf0f0daf0f0adf0a6a8a8d684cece9aa8ad9ca19c95aa76ad'],
                    ['rect' => [419, 45, 48, 80], 'signature' => 'dacdd1ccd2dacdd5d9d7dab9b1d3f0c3cdf0f0f0d4c4d0edf0dacccad8dddbc2b9c6cac4b9bfb0a2'],
                ],
                'redact' => [[467, 45, 101, 80]],
            ],
            [
                'key'     => 'oculus_keratograph_overview_en',
                'label'   => 'Oculus Keratograph (EN) — Topo Overview',
                'width'   => 1480,
                'height'  => 979,
                'anchors' => [
                    ['rect' => [12, 15, 82, 52], 'signature' => 'ece8eaecebececece5afa7b3bda8acd2ddddddddddddddddd6c0bac3bab6b6cdcfbbb2bbbfb2bfc5'],
                    ['rect' => [1110, 5, 360, 75], 'signature' => 'f3f3f3f3f3d5e0f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f1edededededacb2ededededededededededededededededededededededededededededebe6e6e6e6b99fcbc8e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e5e0d9d1d07a73cba8d0d0dae0d9d3d8e0d5d3dedde0dddde0e0dde0dedfd5d5ded0e0e0dfd9bb67717f617badb590c6d98eb393a1abb3a9a1d9a2a0d9d9a0d9af9eaeb0adb1d9d9d9d2d2927f806294a3caa4d2d29cd2b185d2d2c99cd29c9bd2d29bd2aab19b9da9d2d2d2d3ccb9997f958bcccc979bbecc919c8fb1979ca2939c8da59ca3989c8daf9a9caacccccccdc8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8cb'],
                ],
                'redact' => [[92, 15, 983, 52]],
            ],
            [
                'key'     => 'oculus_keratograph_4maps_en',
                'label'   => 'Oculus Keratograph (EN) — Topo 4-Maps',
                'width'   => 1480,
                'height'  => 979,
                'anchors' => [
                    ['rect' => [12, 25, 72, 60], 'signature' => 'f5ebf0f5f3f5f5f3bdb2bcc8b0b4f2f2f2f2f2f2f2f1f1f1f1f1f1f1f0d4c9d4cdc6c8efd9d1dcdfd0de'],
                    ['rect' => [30, 900, 340, 75], 'signature' => 'efefefefefe2efefefefefefefefefefefefefefefefefefefefefefefefefefefefefefefefefaabeefefefefefefefefefefefefefefefefefefefefefefefefefefefe8e8e8e8c6adb3e4e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e2e2e2e28188deace1e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2dbab7f7c78628eb6a88fcdd89aa999baa5a7b6b3dbb3b1dbdbb1dbb3b8a8a5b2aadbd5d37a8080778399d2a0d5c3afd5a199d4d5ce9ed59e9dd5d49cd59eb19e9ec0d3d5ceba8c708069bdce8ca1c6c689a5859ea2a4a183a58694a3ae87a583a7a4a2a2cecec7c4c1c2c4c5c7c7c2c0c6c7c3c0c4c7c1c0c6c4c0c4c5c2c4c4c0c3c7c1c1c7c7c7'],
                ],
                'redact' => [[85, 25, 295, 175]],
            ],
            [
                'key'     => 'oculus_pachycam_en',
                'label'   => 'Oculus Pachycam (EN)',
                'width'   => 1535,
                'height'  => 1114,
                'anchors' => [
                    ['rect' => [0, 4, 150, 22], 'signature' => 'e5ebdfeaebffe5e8e5e7e4e5e2feffe1e7d1dbe4f5e6d5dddae8d2dcfdff'],
                    ['rect' => [25, 40, 60, 48], 'signature' => 'e5eaf0f0f0f0dab1b4cdf0f0f0f0f0f0f0f0d9c3c0d8c1ede7dfe2e3e3eb'],
                ],
                'redact' => [[86, 38, 745, 52], [1385, 38, 100, 52]],
            ],
            [
                'key'     => 'tomey_em3000',
                'label'   => 'Tomey EM-3000 (microscopia especular)',
                'width'   => 700,
                'height'  => 529,
                'anchors' => [
                    ['rect' => [184, 8, 66, 34], 'signature' => '8f8d9a9a9a9a9a795e5d62617d9a908d7d87898d9a'],
                    ['rect' => [281, 62, 113, 195], 'signature' => 'bcd7e5c7eae7e3fdfffffeb4ab9894bfa3cffefffffecbbbd7e9e9e9d1e7e8d9d0bfabc9fffffecf9c74afd1d9d6e3e3e9eae9dfdad9d69fbd9dc4feffffdfa5c1c7d2becbcae9eaeac5b0bad1c7bbd8ffffffffeecdd3d0c7b5dcffffffffca9abcd8c7c0d0eaeaeaeaeae9c8ccb9afdafffffffffffed4bdd7dae8eaeaeaeaeae9e2d8849cbbd5feffffd793bad0cac4c7d8e9eaeaccc0c6d29ab1dae5ffffffe9c0cecba4b4c4c9e9eaeaba95abc9'],
                ],
                'redact' => [[30, 9, 150, 32], [250, 9, 362, 32]],
            ],
        ];
    }
}
