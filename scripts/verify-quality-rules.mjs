// Adapted from soumatheusgomes/vibe-coding-toolkit, de66fe371a49f5a4d3b521759e5c401f390b6424.
// MIT; copyright (c) 2026 Matheus Gomes. See eslint-rules/LICENSE and README.md.
// Self-check for the quality/* rules. Uses ESLint's own RuleTester and
// nothing else -- no test framework, no dev dependency beyond ESLint.
//
// Usage, from the root of a project that installed the rules:
//   node scripts/verify-quality-rules.mjs
// Pass a path to check a copy that lives somewhere else:
//   node scripts/verify-quality-rules.mjs ./eslint-rules/index.cjs
//
// RuleTester.run() throws on the first failing case, so a non-zero exit is
// the failure signal; there is no assertion library to configure. Test
// sources are plain JavaScript with .ts/.tsx filenames on purpose: the
// rules inspect filenames and syntax espree already
// understands, so the check needs no TypeScript parser.
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { RuleTester } from 'eslint';
import vueParser from 'vue-eslint-parser';

const target = process.argv[2] ?? './eslint-rules/index.cjs';
const plugin = (await import(pathToFileURL(path.resolve(target)).href)).default;

const ruleTester = new RuleTester();
const testFilenames = [
    'src/order.e2e-spec.ts',
    'src/order-spec.ts',
    'src/order-test.js',
    'src/order.spec.mts',
    'src/order.test.cts',
];
// Unique identifiers per line: repeating one declaration would collide
// with itself and fail as a parse error before any rule ever runs.
const lines = (count) => Array.from({ length: count }, (_, i) => `const value${i} = ${i};`).join('\n') + '\n';

ruleTester.run('quality/max-lines', plugin.rules['max-lines'], {
    valid: [
        ...testFilenames.map((filename) => ({
            name: `test suffix is exempt by default: ${filename}`,
            code: lines(20),
            filename,
            options: [{ max: 10 }],
        })),
        {
            name: 'a non-index barrel is exempt by content',
            code: Array.from({ length: 20 }, (_, i) => `export * from "./module${i}";`).join('\n'),
            filename: 'src/public-api.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'empty statements do not turn a forwarding module into runtime logic',
            code: 'import { value } from "./module";\nexport { value };\n' + ';\n'.repeat(20),
            filename: 'src/public-api.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'a file under the budget',
            code: lines(3),
            filename: 'src/service.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'test files are exempt by default',
            code: lines(20),
            filename: 'src/service.test.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'files inside a test directory are exempt by default',
            code: lines(20),
            filename: 'src/__tests__/helpers.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'declaration files are never checked',
            code: lines(20),
            filename: 'src/types.d.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'a pure re-export barrel is exempt by content',
            code: Array.from({ length: 20 }, (_, i) => `export { item${i} } from "./module${i}";`).join('\n'),
            filename: 'src/index.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'generated output is never checked',
            code: lines(20),
            filename: 'src/generated/client.ts',
            options: [{ max: 10 }],
        },
        {
            name: 'a baseline entry silences a known offender',
            code: lines(20),
            filename: 'src/legacy.ts',
            options: [{ max: 10, ignore: ['src/legacy.ts'] }],
        },
    ],
    invalid: [
        ...testFilenames.map((filename) => ({
            name: `includeTests opts in a test suffix: ${filename}`,
            code: lines(20),
            filename,
            options: [{ max: 10, includeTests: true }],
            errors: [{ messageId: 'tooLong' }],
        })),
        {
            name: 'a similar production suffix is still checked',
            code: lines(20),
            filename: 'src/order-specification.ts',
            options: [{ max: 10 }],
            errors: [{ messageId: 'tooLong' }],
        },
        ...[
            'src/index.ts',
            'src/components/index.tsx',
            'src/types.ts',
            'src/constants.ts',
            'C:\\repo\\src\\index.ts',
        ].map((filename) => ({
            name: `runtime code is checked regardless of the basename: ${filename}`,
            code: lines(20),
            filename,
            options: [{ max: 10 }],
            errors: [{ messageId: 'tooLong' }],
        })),
        {
            name: 'a file over the budget',
            code: lines(20),
            filename: 'src/service.ts',
            options: [{ max: 10 }],
            errors: [{ messageId: 'tooLong' }],
        },
        {
            name: 'includeTests brings test files back under the budget',
            code: lines(20),
            filename: 'src/service.test.ts',
            options: [{ max: 10, includeTests: true }],
            errors: [{ messageId: 'tooLong' }],
        },
    ],
});

console.log('quality/max-lines: ok');

// Vue uses the complete SFC source, not just the JavaScript Program body.
const vueRuleTester = new RuleTester({ languageOptions: { parser: vueParser } });
const template = (count) => ['<template>', ...Array(count).fill('<span>value</span>'), '</template>'].join('\n');
vueRuleTester.run('quality/max-lines (Vue)', plugin.rules['max-lines'], {
    valid: [
        {
            name: 'a template-only Vue SFC at the budget',
            filename: 'src/ExactBudget.vue',
            code: template(8),
            options: [{ max: 10 }],
        },
        {
            name: 'a Vue SFC with script setup below the budget',
            filename: 'src/SmallComponent.vue',
            code: '<script setup>const value = 1;</script>\n<template><span>{{ value }}</span></template>',
            options: [{ max: 10 }],
        },
    ],
    invalid: [
        {
            name: 'template-only Vue is checked even with an empty JavaScript Program',
            filename: 'src/LongTemplate.vue',
            code: template(9),
            options: [{ max: 10 }],
            errors: [{ messageId: 'tooLong', data: { lines: 11, max: 10 } }],
        },
        {
            name: 'Vue with runtime script counts template lines too',
            filename: 'src/RuntimeComponent.vue',
            code: '<script setup>const value = 1;</script>\n' + template(8),
            options: [{ max: 10 }],
            errors: [{ messageId: 'tooLong', data: { lines: 11, max: 10 } }],
        },
        {
            name: 'an import-only Vue script does not exempt a large template',
            filename: 'src/ImportOnlyComponent.vue',
            code: '<script setup>import Widget from "./Widget.vue";</script>\n' + template(8),
            options: [{ max: 10 }],
            errors: [{ messageId: 'tooLong', data: { lines: 11, max: 10 } }],
        },
        {
            name: 'style lines are part of the complete Vue file budget',
            filename: 'src/StyledComponent.vue',
            code: template(1) + '\n<style>\n' + '.item { color: red; }\n'.repeat(6) + '</style>',
            options: [{ max: 10 }],
            errors: [{ messageId: 'tooLong', data: { lines: 11, max: 10 } }],
        },
    ],
});
console.log('quality/max-lines (Vue): ok');

// Local rule (EasyEye density contract): it reads the Vue template, so it only runs under vue-eslint-parser.
// The full matrix lives in tests/JavaScript/eslint-rules/no-local-control-sizing.test.js; this is the smoke
// that `quality:rules` keeps the rule loadable, flagging, and silent on legitimate geometry.
const sizing = (body) => `<template>\n${body}\n</template>`;
vueRuleTester.run('quality/no-local-control-sizing (Vue)', plugin.rules['no-local-control-sizing'], {
    valid: [
        {
            name: 'a control without local sizing',
            filename: 'src/Page.vue',
            code: sizing('<button class="btn btn-primary">ok</button>'),
        },
        {
            name: 'image geometry is not control sizing',
            filename: 'src/Page.vue',
            code: sizing('<img src="a.png" alt="" style="width: 30px; height: 30px" />'),
        },
        {
            name: 'canvas geometry is not control sizing',
            filename: 'src/Page.vue',
            code: sizing('<canvas :style="{ height: `${h}px` }"></canvas>'),
        },
        {
            name: 'btn-xs, btn-link and btn-close stay',
            filename: 'src/Page.vue',
            code: sizing(
                '<button class="btn btn-xs">a</button><button class="btn btn-link fs-11">b</button><button class="btn-close"></button>',
            ),
        },
        {
            name: 'a badge keeps its font utility',
            filename: 'src/Page.vue',
            code: sizing('<span class="badge fs-11">ok</span>'),
        },
    ],
    invalid: [
        {
            name: 'btn-sm on a button',
            filename: 'src/Page.vue',
            code: sizing('<button class="btn btn-sm">ok</button>'),
            errors: [{ messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } }],
        },
        {
            name: 'btn-sm in a dynamic class',
            filename: 'src/Page.vue',
            code: sizing('<button class="btn" :class="{ \'btn-sm\': compact }">ok</button>'),
            errors: [{ messageId: 'sizeClass', data: { name: 'btn-sm', tag: 'button' } }],
        },
        {
            name: 'fs-13 on a field',
            filename: 'src/Page.vue',
            code: sizing('<input class="form-control fs-13" />'),
            errors: [{ messageId: 'fontClass', data: { name: 'fs-13', tag: 'input' } }],
        },
        {
            name: 'inline height on a control component',
            filename: 'src/Page.vue',
            code: sizing('<SearchSelect style="height: 30px" />'),
            errors: [{ messageId: 'style', data: { property: 'height', tag: 'SearchSelect' } }],
        },
    ],
});
console.log('quality/no-local-control-sizing (Vue): ok');

ruleTester.run('quality/no-direct-console', plugin.rules['no-direct-console'], {
    valid: [
        ...testFilenames.map((filename) => ({
            name: `test suffix may use console: ${filename}`,
            code: "console.log('test');",
            filename,
        })),
        {
            name: 'a logging helper is fine',
            code: "logger.info('hello');",
            filename: 'src/service.ts',
        },
        {
            name: 'test files may log freely',
            code: "console.log('hello');",
            filename: 'src/service.test.ts',
        },
        {
            name: 'an allowed method is not reported',
            code: "console.error('boom');",
            filename: 'src/service.ts',
            options: [{ allow: ['error'] }],
        },
        {
            name: 'a method that is not a console method is not reported',
            code: "console.render('x');",
            filename: 'src/service.ts',
        },
        {
            name: 'an identifier that merely ends in console is not the console',
            code: "fakeconsole.log('x');",
            filename: 'src/service.ts',
        },
    ],
    invalid: [
        {
            name: 'a direct console call in production code',
            code: "console.log('hello');",
            filename: 'src/service.ts',
            errors: [
                {
                    messageId: 'banned',
                    data: { method: 'log', logger: 'the project logging helper' },
                },
            ],
        },
        {
            name: 'the logger option names the replacement in the message',
            code: "console.warn('hello');",
            filename: 'src/service.ts',
            options: [{ logger: 'logger.warn()' }],
            errors: [{ messageId: 'banned', data: { method: 'warn', logger: 'logger.warn()' } }],
        },
    ],
});

console.log('quality/no-direct-console: ok');

const dataAccess = {
    modules: ['@/db', '@/db/index'],
    layers: ['/src/app/', '/src/components/'],
    extensions: ['.tsx'],
};

ruleTester.run('quality/no-direct-data-access', plugin.rules['no-direct-data-access'], {
    valid: [
        ...testFilenames.map((filename) => ({
            name: `test suffix may access data: ${filename}`,
            code: "import { db } from '@/db';",
            filename: `/repo/${filename}`,
            options: [{ ...dataAccess, layers: ['/src/'] }],
        })),
        {
            name: 'a guarded layer importing something other than the client',
            code: "import { userColumns } from '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [dataAccess],
        },
        {
            name: 'a layer that is not guarded may import the client',
            code: "import { db } from '@/db';",
            filename: '/repo/src/server/user-repository.ts',
            options: [dataAccess],
        },
        {
            name: 'a module that is not the data module',
            code: "import { db } from './local-cache';",
            filename: '/repo/src/app/page.ts',
            options: [dataAccess],
        },
        {
            name: 'test files are exempt',
            code: "import { db } from '@/db';",
            filename: '/repo/src/app/page.test.ts',
            options: [dataAccess],
        },
        {
            name: 'a side-effect import pulls no binding',
            code: "import '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [dataAccess],
        },
        {
            name: 'a custom binding list does not match the default name',
            code: "import { db } from '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [{ ...dataAccess, bindings: ['prisma'] }],
        },
    ],
    invalid: [
        {
            name: 'a guarded layer importing the client by name',
            code: "import { db } from '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [dataAccess],
            errors: [{ messageId: 'forbidden', data: { module: '@/db' } }],
        },
        {
            name: 'the extensions list guards a file outside the layer paths',
            code: "import { db } from '@/db';",
            filename: '/repo/src/widgets/table.tsx',
            options: [dataAccess],
            errors: [{ messageId: 'forbidden', data: { module: '@/db' } }],
        },
        {
            name: 'a default import always counts as pulling the client',
            code: "import anything from '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [dataAccess],
            errors: [{ messageId: 'forbidden', data: { module: '@/db' } }],
        },
        {
            name: 'a namespace import always counts as pulling the client',
            code: "import * as everything from '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [dataAccess],
            errors: [{ messageId: 'forbidden', data: { module: '@/db' } }],
        },
        {
            name: 'a renamed import is matched on the imported name, not the local one',
            code: "import { db as database } from '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [dataAccess],
            errors: [{ messageId: 'forbidden', data: { module: '@/db' } }],
        },
        {
            name: 'a custom binding list matches its own name',
            code: "import { prisma } from '@/db';",
            filename: '/repo/src/app/page.ts',
            options: [{ ...dataAccess, bindings: ['prisma'] }],
            errors: [{ messageId: 'forbidden', data: { module: '@/db' } }],
        },
    ],
});

console.log('quality/no-direct-data-access: ok');
