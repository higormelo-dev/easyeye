import { RuleTester } from 'eslint';
import vueParser from 'vue-eslint-parser';
import quality from '../../../eslint-rules/index.cjs';

/**
 * quality/no-local-control-sizing (design-system/MASTER.md §6; plano de adoção, D3).
 *
 * Dentro de uma região `data-ee-density` quem decide a medida do controle é a região: classe de tamanho do
 * Bootstrap, `fs-*` e `style` de altura/fonte num botão ou campo voltam a competir com ela. A regra só olha o
 * template e só controles; geometria de imagem, gráfico, badge e ícone é legítima e precisa passar.
 */
const rule = quality.rules['no-local-control-sizing'];
const tester = new RuleTester({ languageOptions: { parser: vueParser } });

const page = (body) => `<template>\n${body}\n</template>`;
const filename = 'resources/js/Pages/Panel/Doctors/Sample.vue';

const valid = (name, body) => ({ name, filename, code: page(body) });
const invalid = (name, body, errors) => ({ name, filename, code: page(body), errors });

tester.run('quality/no-local-control-sizing', rule, {
    valid: [
        valid('botão sem classe de tamanho', '<button class="btn btn-primary">Salvar</button>'),
        valid('campo sem classe de tamanho', '<input class="form-control" type="text" />'),
        valid('select sem classe de tamanho', '<select class="form-select"><option>a</option></select>'),
        valid('classe que só começa igual a uma banida', '<button class="btn btn-small btn-sm-custom">ok</button>'),

        // O que a região mantém por contrato (MASTER §1): ação mínima, link, fechar.
        valid('.btn-xs fica', '<button class="btn btn-xs btn-outline-primary">x</button>'),
        valid('.btn-link fica, com fonte própria', '<button class="btn btn-link fs-11 p-0">ver</button>'),
        valid('.btn-close fica', '<button class="btn-close" aria-label="Fechar"></button>'),
        valid('.btn-xs com style de altura fica', '<button class="btn btn-xs" style="height: 20px">x</button>'),

        // Geometria legítima: imagem, gráfico, ilustração, documento.
        valid('img com altura', '<img src="a.png" alt="" style="width: 30px; height: 30px; object-fit: cover" />'),
        valid(
            'img com :style de altura',
            '<img :src="a" alt="" :style="{ width: size + `px`, height: size + `px` }" />',
        ),
        valid('canvas com altura', '<canvas ref="c" :style="{ height: h + `px` }"></canvas>'),
        valid('svg com tamanho de fonte', '<svg style="font-size: 12px"><text>1</text></svg>'),
        valid('div de documento com altura', '<div class="a4-page" style="min-height: 297mm; height: 1123px">x</div>'),
        valid('bolinha de cor', '<span :style="{ background: d.color, width: `12px`, height: `12px` }"></span>'),

        // Badge e ícone não são controle: o `fs-*` fica.
        valid('badge com fs-11', '<span class="badge badge-soft-success fs-11 fw-medium">ok</span>'),
        valid('badge com fs-13', '<span class="badge fs-13">ok</span>'),
        valid('ícone com fs-12', '<i class="ti ti-search fs-12" aria-hidden="true"></i>'),
        valid('ícone dinâmico com fs-11', '<i :class="icon" class="ms-1 fs-11" aria-hidden="true"></i>'),

        // Style de controle que não mexe em altura nem fonte.
        valid('botão com style de largura', '<button class="btn" style="width: 120px">ok</button>'),
        valid('campo com style de cor', '<input class="form-control" style="color: red" />'),
        valid('max-height não é altura do controle', '<select class="form-select" style="max-height: 200px"></select>'),

        // Expressão que a regra não consegue ler é aceita (limite documentado no README).
        valid('classe vinda de variável', '<button :class="buttonClass">ok</button>'),
        valid('style vindo de variável', '<input class="form-control" :style="inputStyle" />'),
        valid('argumento dinâmico', '<button :[attr]="`btn-sm`">ok</button>'),

        // Só o template conta.
        {
            name: 'string no script não é classe',
            filename,
            code: `<script setup>\nconst size = 'btn-sm';\n</script>\n${page('<button class="btn">ok</button>')}`,
        },
        valid('texto visível não é classe', '<p>use btn-sm aqui</p>'),
        valid('atributo que não é class', '<button class="btn" title="btn-sm" data-size="btn-sm">ok</button>'),
        valid('comparação de string não é classe', '<button :class="size === `btn-sm` ? `a` : `b`">ok</button>'),
    ],

    invalid: [
        // Classes de tamanho: valem em qualquer elemento (a classe sozinha já é a medida local).
        ...['btn-sm', 'btn-lg', 'btn-md'].map((name) =>
            invalid(`${name} no botão`, `<button class="btn btn-primary ${name}">ok</button>`, [
                { messageId: 'sizeClass', data: { name, tag: 'button' } },
            ]),
        ),
        invalid('form-control-sm no campo', '<input class="form-control form-control-sm" />', [
            { messageId: 'sizeClass', data: { name: 'form-control-sm', tag: 'input' } },
        ]),
        invalid('form-select-sm no select', '<select class="form-select form-select-sm"></select>', [
            { messageId: 'sizeClass', data: { name: 'form-select-sm', tag: 'select' } },
        ]),
        invalid(
            'input-group-sm no grupo',
            '<div class="input-group input-group-sm"><input class="form-control" /></div>',
            [{ messageId: 'sizeClass', data: { name: 'input-group-sm', tag: 'div' } }],
        ),
        invalid('btn-sm num link', '<a href="#" class="btn btn-outline-primary btn-sm">ir</a>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'a' } },
        ]),
        invalid('btn-sm num componente', '<Link :href="url" class="btn btn-sm">ir</Link>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'Link' } },
        ]),
        invalid('btn-sm com .btn-link continua banido', '<button class="btn btn-link btn-sm">ver</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
        ]),
        invalid('classe separada por quebra de linha', '<button class="btn\n    btn-sm">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
        ]),
        invalid('dois tamanhos no mesmo elemento', '<button class="btn btn-sm btn-lg">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
            { messageId: 'sizeClass', data: { name: 'btn-lg', tag: 'button' } },
        ]),
        invalid(
            'dentro de slot e de v-for',
            '<Tbl><template #row><tr v-for="r in rows" :key="r"><td><button class="btn btn-sm">x</button></td></tr></template></Tbl>',
            [{ messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } }],
        ),

        // Classe dinâmica com literal.
        invalid('objeto com chave literal', '<button class="btn" :class="{ \'btn-sm\': compact }">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
        ]),
        invalid('v-bind:class explícito', '<button v-bind:class="{ \'btn-sm\': compact }">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
        ]),
        invalid('array com ternário', "<button :class=\"['btn', compact ? 'btn-sm' : 'btn-md']\">ok</button>", [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
            { messageId: 'sizeClass', data: { name: 'btn-md', tag: 'button' } },
        ]),
        invalid('&& com literal à direita', '<button :class="compact && \'btn-sm\'">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
        ]),
        invalid('template literal', '<button :class="`btn ${variant} btn-sm`">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
        ]),
        invalid('concatenação', "<button :class=\"'btn ' + 'btn-sm'\">ok</button>", [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
        ]),
        invalid('array dentro de objeto', '<button :class="[{ \'form-control-sm\': a }, b]">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'form-control-sm', tag: 'button' } },
        ]),

        // fs-* em controle.
        invalid('fs-13 no botão', '<button class="btn btn-primary fs-13">ok</button>', [
            { messageId: 'fontClass', data: { name: 'fs-13', tag: 'button' } },
        ]),
        invalid('fs-11 no campo', '<input class="form-control fs-11" />', [
            { messageId: 'fontClass', data: { name: 'fs-11', tag: 'input' } },
        ]),
        invalid('fs-13 no select', '<select class="form-select fs-13"></select>', [
            { messageId: 'fontClass', data: { name: 'fs-13', tag: 'select' } },
        ]),
        invalid('fs-13 em componente de campo', '<SearchSelect class="fs-13" v-model="x" />', [
            { messageId: 'fontClass', data: { name: 'fs-13', tag: 'SearchSelect' } },
        ]),
        invalid('fs-11 em link com .btn', '<a class="btn btn-outline-primary fs-11">ir</a>', [
            { messageId: 'fontClass', data: { name: 'fs-11', tag: 'a' } },
        ]),
        invalid('fs-13 dinâmico em botão', '<button class="btn" :class="{ \'fs-13\': a }">ok</button>', [
            { messageId: 'fontClass', data: { name: 'fs-13', tag: 'button' } },
        ]),

        // style de altura e de fonte em controle.
        invalid('height no botão', '<button class="btn" style="height: 30px">ok</button>', [
            { messageId: 'style', data: { property: 'height', tag: 'button' } },
        ]),
        invalid('min-height no campo', '<input class="form-control" style="min-height:30px" />', [
            { messageId: 'style', data: { property: 'min-height', tag: 'input' } },
        ]),
        invalid('font-size no select', '<select class="form-select" style="color: red; font-size: 12px"></select>', [
            { messageId: 'style', data: { property: 'font-size', tag: 'select' } },
        ]),
        invalid('height no textarea', '<textarea class="form-control" style="height: 80px"></textarea>', [
            { messageId: 'style', data: { property: 'height', tag: 'textarea' } },
        ]),
        invalid('height em input sem classe', '<input type="text" style="height: 30px" />', [
            { messageId: 'style', data: { property: 'height', tag: 'input' } },
        ]),
        invalid('height em link com .btn', '<a class="btn" style="height: 30px">ir</a>', [
            { messageId: 'style', data: { property: 'height', tag: 'a' } },
        ]),
        invalid('height em SearchSelect', '<SearchSelect style="height: 30px" v-model="x" />', [
            { messageId: 'style', data: { property: 'height', tag: 'SearchSelect' } },
        ]),
        invalid('min-height em search-select (kebab)', "<search-select :style=\"{ 'min-height': '30px' }\" />", [
            { messageId: 'style', data: { property: 'min-height', tag: 'search-select' } },
        ]),
        invalid('font-size em SearchInput', '<SearchInput style="font-size: 12px" />', [
            { messageId: 'style', data: { property: 'font-size', tag: 'SearchInput' } },
        ]),
        invalid('height em MoneyInput', '<MoneyInput :style="{ height: h }" v-model="v" />', [
            { messageId: 'style', data: { property: 'height', tag: 'MoneyInput' } },
        ]),
        invalid('objeto com chave em camelCase', '<input class="form-control" :style="{ minHeight: h }" />', [
            { messageId: 'style', data: { property: 'min-height', tag: 'input' } },
        ]),
        invalid('objeto com fontSize', '<input class="form-control" :style="{ fontSize: `12px` }" />', [
            { messageId: 'style', data: { property: 'font-size', tag: 'input' } },
        ]),
        invalid('style como string literal', '<button class="btn" :style="\'height: 30px\'">ok</button>', [
            { messageId: 'style', data: { property: 'height', tag: 'button' } },
        ]),
        invalid('style como template literal', '<button class="btn" :style="`height: ${h}px`">ok</button>', [
            { messageId: 'style', data: { property: 'height', tag: 'button' } },
        ]),
        invalid('style como array de objetos', '<input class="form-control" :style="[base, { height: h }]" />', [
            { messageId: 'style', data: { property: 'height', tag: 'input' } },
        ]),
        invalid('altura e fonte juntas', '<button class="btn" style="height: 30px; font-size: 12px">ok</button>', [
            { messageId: 'style', data: { property: 'height', tag: 'button' } },
            { messageId: 'style', data: { property: 'font-size', tag: 'button' } },
        ]),

        // Todos os tipos de achado no mesmo controle.
        invalid('classe, fonte e style juntos', '<button class="btn btn-sm fs-13" style="height: 30px">ok</button>', [
            { messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } },
            { messageId: 'fontClass', data: { name: 'fs-13', tag: 'button' } },
            { messageId: 'style', data: { property: 'height', tag: 'button' } },
        ]),
    ],
});
