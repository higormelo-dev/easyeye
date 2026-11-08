{{-- Inter 4.1 servida pelo próprio EasyEye (public/fonts; licença OFL em
     public/fonts/Inter-LICENSE.txt): sem Google Fonts, o IP de quem acessa site,
     painel e portais não vai a terceiro (LGPD arts. 33–36). Declarada aqui, com
     asset(), e não no CSS do Vite: em dev o CSS vem do servidor do Vite (sem
     public/) e, com ASSET_URL, um caminho absoluto no CSS apontaria para outro
     lugar que o preload. O itálico só é baixado se houver texto em itálico. --}}
<link rel="preload" href="{{ asset('fonts/InterVariable.woff2') }}" as="font" type="font/woff2" crossorigin>
<style>
    @font-face {
        font-family: 'Inter';
        font-style: normal;
        font-weight: 100 900;
        font-display: swap;
        src: url('{{ asset('fonts/InterVariable.woff2') }}') format('woff2');
    }

    @font-face {
        font-family: 'Inter';
        font-style: italic;
        font-weight: 100 900;
        font-display: swap;
        src: url('{{ asset('fonts/InterVariable-Italic.woff2') }}') format('woff2');
    }
</style>
