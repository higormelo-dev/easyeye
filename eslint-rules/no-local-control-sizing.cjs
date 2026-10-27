// Local rule, written for EasyEye (not part of the upstream vibe-coding-toolkit copy).
// Design contract: design-system/MASTER.md section 6 and docs/internal/planos/design-system-adocao-plano.md (D3).
'use strict';

// Size classes the density region already decides for the control (`.btn-sm`, `.form-control-sm`, ... are
// neutralised inside `[data-ee-density]`). `btn-md` does not even exist in the CSS.
const SIZE_CLASSES = new Set([
    'btn-sm',
    'btn-lg',
    'btn-md',
    'form-control-sm',
    'form-select-sm',
    'input-group-sm',
]);

// Font utilities that override the region's `--ee-control-font-size` when put on a control.
const FONT_CLASS = /^fs-(?:11|13)$/;

// What the region leaves alone by contract: a minimal chip action, a text link and the close icon.
const EXEMPT_CLASSES = new Set(['btn-xs', 'btn-link', 'btn-close']);

// An element is a control by its tag, or by wearing a control class (an `<a class="btn">` is a button).
const CONTROL_TAGS = new Set(['button', 'input', 'select', 'textarea']);
const CONTROL_COMPONENTS = new Set(['searchselect', 'searchinput', 'moneyinput']);
const CONTROL_CLASSES = new Set(['btn', 'form-control', 'form-select']);

const STYLE_PROPERTIES = new Set(['height', 'min-height', 'font-size']);

function isControl(rawName, classNames) {
    const tag = rawName.toLowerCase();
    return (
        CONTROL_TAGS.has(tag) ||
        CONTROL_COMPONENTS.has(tag.replace(/-/g, '')) ||
        classNames.some((name) => CONTROL_CLASSES.has(name))
    );
}

function kebab(name) {
    return name.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`).toLowerCase();
}

// Class names of a text. `open`/`close` say whether the text is glued to an interpolation on that side
// (`btn-${size}`): the fragment touching it is not a whole class name, so it is dropped.
function classNamesOf(text, { glueStart = false, glueEnd = false } = {}) {
    const names = text.split(/\s+/).filter(Boolean);
    if (glueStart && names.length && !/^\s/.test(text)) names.shift();
    if (glueEnd && names.length && !/\s$/.test(text)) names.pop();
    return names;
}

// Literal strings that can end up in `:class`, with the node to report on. Anything that cannot be read
// statically (identifiers, calls) is skipped on purpose: a false positive is worse than a documented gap.
function collectClassTexts(node, out) {
    if (!node) return;
    switch (node.type) {
        case 'Literal':
            if (typeof node.value === 'string') out.push({ node, names: classNamesOf(node.value) });
            break;
        case 'TemplateLiteral':
            node.quasis.forEach((quasi, index) => {
                const names = classNamesOf(quasi.value.cooked ?? quasi.value.raw, {
                    glueStart: index > 0,
                    glueEnd: index < node.quasis.length - 1,
                });
                out.push({ node: quasi, names });
            });
            node.expressions.forEach((expression) => collectClassTexts(expression, out));
            break;
        case 'ArrayExpression':
            node.elements.forEach((element) => collectClassTexts(element, out));
            break;
        case 'ObjectExpression':
            node.properties.forEach((property) => {
                if (property.type !== 'Property') return;
                if (property.computed) collectClassTexts(property.key, out);
                else if (property.key.type === 'Literal') collectClassTexts(property.key, out);
            });
            break;
        case 'ConditionalExpression':
            collectClassTexts(node.consequent, out);
            collectClassTexts(node.alternate, out);
            break;
        case 'LogicalExpression':
            // `a && 'btn-sm'`: the left side is only a condition.
            if (node.operator !== '&&') collectClassTexts(node.left, out);
            collectClassTexts(node.right, out);
            break;
        case 'BinaryExpression':
            if (node.operator === '+') {
                collectClassTexts(node.left, out);
                collectClassTexts(node.right, out);
            }
            break;
        default:
    }
}

// CSS properties declared in a piece of inline style text.
function declaredProperties(text) {
    return text
        .split(';')
        .filter((declaration) => declaration.includes(':'))
        .map((declaration) => declaration.slice(0, declaration.indexOf(':')).trim().toLowerCase());
}

// Properties set by a `:style` expression: object keys (camelCase or kebab-case) and declarations inside
// string literals and template literals, through arrays and conditionals.
function collectStyleProperties(node, out) {
    if (!node) return;
    switch (node.type) {
        case 'Literal':
            if (typeof node.value === 'string') {
                declaredProperties(node.value).forEach((property) => out.push({ node, property }));
            }
            break;
        case 'TemplateLiteral':
            node.quasis.forEach((quasi) => {
                declaredProperties(quasi.value.cooked ?? quasi.value.raw).forEach((property) =>
                    out.push({ node: quasi, property }),
                );
            });
            break;
        case 'ArrayExpression':
            node.elements.forEach((element) => collectStyleProperties(element, out));
            break;
        case 'ObjectExpression':
            node.properties.forEach((property) => {
                if (property.type !== 'Property' || property.computed) return;
                const key = property.key.type === 'Identifier' ? property.key.name : property.key.value;
                if (typeof key === 'string') out.push({ node: property.key, property: kebab(key) });
            });
            break;
        case 'ConditionalExpression':
            collectStyleProperties(node.consequent, out);
            collectStyleProperties(node.alternate, out);
            break;
        case 'LogicalExpression':
            if (node.operator !== '&&') collectStyleProperties(node.left, out);
            collectStyleProperties(node.right, out);
            break;
        default:
    }
}

// `class` / `:class` / `v-bind:class` (static argument only) and the same for `style`.
function attributeKind(attribute) {
    if (!attribute.directive) return attribute.key.name === 'class' || attribute.key.name === 'style' ? attribute.key.name : null;
    const { name, argument } = attribute.key;
    if (name.name !== 'bind' || !argument || argument.type !== 'VIdentifier') return null;
    return argument.name === 'class' || argument.name === 'style' ? argument.name : null;
}

const noLocalControlSizing = {
    meta: {
        type: 'problem',
        docs: {
            description:
                'Disallow local size classes, font utilities and inline height/font-size on controls: the density region decides.',
        },
        messages: {
            sizeClass:
                'Local size class "{{name}}" on <{{tag}}>: the density region (data-ee-density, MASTER section 6) decides the control size. Remove it.',
            fontClass:
                'Local font utility "{{name}}" on control <{{tag}}>: the density region decides the control font size. Remove it.',
            style: 'Inline "{{property}}" on control <{{tag}}>: the density region decides the control size. Remove it.',
        },
        schema: [],
    },
    create(context) {
        const parserServices = context.sourceCode.parserServices;
        // Not a Vue SFC (or not parsed by vue-eslint-parser): nothing to read.
        if (!parserServices || typeof parserServices.defineTemplateBodyVisitor !== 'function') return {};

        return parserServices.defineTemplateBodyVisitor({
            VElement(element) {
                const tag = element.rawName;
                const classTexts = [];
                const styleProperties = [];

                for (const attribute of element.startTag.attributes) {
                    const kind = attributeKind(attribute);
                    if (!kind) continue;
                    const value = attribute.value;
                    if (!value) continue;

                    if (!attribute.directive) {
                        if (value.type !== 'VLiteral') continue;
                        if (kind === 'class') classTexts.push({ node: value, names: classNamesOf(value.value) });
                        else declaredProperties(value.value).forEach((property) => styleProperties.push({ node: value, property }));
                    } else if (value.type === 'VExpressionContainer' && value.expression) {
                        if (kind === 'class') collectClassTexts(value.expression, classTexts);
                        else collectStyleProperties(value.expression, styleProperties);
                    }
                }

                const classNames = classTexts.flatMap((text) => text.names);
                // A chip action, a text link or the close icon is left as it is, whatever else it carries.
                const exempt = classNames.some((name) => EXEMPT_CLASSES.has(name));
                const control = isControl(tag, classNames);

                for (const { node, names } of classTexts) {
                    for (const name of names) {
                        if (SIZE_CLASSES.has(name)) {
                            context.report({ node, messageId: 'sizeClass', data: { name, tag } });
                        } else if (control && !exempt && FONT_CLASS.test(name)) {
                            context.report({ node, messageId: 'fontClass', data: { name, tag } });
                        }
                    }
                }

                if (!control || exempt) return;
                for (const { node, property } of styleProperties) {
                    if (STYLE_PROPERTIES.has(property)) {
                        context.report({ node, messageId: 'style', data: { property, tag } });
                    }
                }
            },
        });
    },
};

module.exports = { noLocalControlSizing };
