// Paleta isolada da agenda: não modifica badges globais do Bootstrap.
export const EVENT_PALETTE = {
    'bg-primary': '#0b5ed7',
    'bg-secondary': '#4b5563',
    'bg-success': '#15803d',
    'bg-info': '#0e7490',
    'bg-warning': '#92400e',
    'bg-danger': '#b91c1c',
    'bg-dark': '#212529',
    'bg-purple': '#6d28d9',
    'bg-orange': '#c2410c',
    'bg-teal': '#0f766e',
};
const legacy = {
    '#0d6efd': 'bg-primary',
    '#6c757d': 'bg-secondary',
    '#198754': 'bg-success',
    '#0aa2c0': 'bg-info',
    '#b7791f': 'bg-warning',
    '#dc3545': 'bg-danger',
    '#7c3aed': 'bg-purple',
    '#ea580c': 'bg-orange',
    '#0e9384': 'bg-teal',
};
function hex(color) {
    if (typeof color !== 'string') return null;
    const value = color.trim().toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(value)) return value;
    if (/^#[0-9a-f]{3}$/.test(value)) return '#' + [...value.slice(1)].map((c) => c + c).join('');
    return null;
}
function luminance(color) {
    const rgb = hex(color)
        .slice(1)
        .match(/../g)
        .map((c) => parseInt(c, 16) / 255)
        .map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
}
function contrast(a, b) {
    const x = luminance(a),
        y = luminance(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}
export function bestTextColor(background) {
    const color = hex(background) || EVENT_PALETTE['bg-secondary'];
    return contrast(color, '#ffffff') >= contrast(color, '#111827') ? '#ffffff' : '#111827';
}
export function resolveEventPalette({ badge, color } = {}) {
    const status = String(badge ?? '')
        .split(/\s+/)
        .find((key) => EVENT_PALETTE[key]);
    const normalized = hex(color);
    let backgroundColor =
        EVENT_PALETTE[status] || EVENT_PALETTE[legacy[normalized]] || normalized || EVENT_PALETTE['bg-secondary'];
    let textColor = bestTextColor(backgroundColor);
    // Algumas cores intermediárias não atingem 4,5 com nenhum dos dois textos.
    if (contrast(backgroundColor, textColor) < 4.5) {
        backgroundColor = EVENT_PALETTE['bg-secondary'];
        textColor = '#ffffff';
    }
    return { backgroundColor, textColor };
}
