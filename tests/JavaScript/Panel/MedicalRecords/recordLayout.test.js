import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { recordColumnOrder } from '@/Pages/Panel/MedicalRecords/Components/recordLayout.js';

/**
 * Prontuário, coluna esquerda: A/V sem correção, A/V com correção e
 * Tonometria são blocos "inline" que dividem a linha quando cabem — A/V com
 * correção logo depois de A/V sem correção e sem vão em branco ao lado —,
 * seguidos de Dinâmica/Estática. Quem salvou um "Meu prontuário" antes vê as
 * seções no lugar padrão, não no fim da coluna nem sumidas.
 */

const LEFT  = ['cromatica_ppc_cover', 'av_sem_tono', 'av_com', 'tonometria', 'dinamica', 'estatica'];
const RIGHT = ['adicao', 'biomicroscopia', 'fundoscopia', 'obs_geral'];

describe('recordColumnOrder — "Meu prontuário" salvo antes da mudança', () => {
    it('A/V com correção e Tonometria entram logo depois de A/V sem correção, mesmo com a ordem do médico', () => {
        // Modelo antigo: av_com estava na direita; a Tonometria não era seção; Estática no topo.
        const savedLeft  = ['estatica', 'cromatica_ppc_cover', 'av_sem_tono', 'dinamica'];
        const savedRight = ['av_com', 'adicao', 'biomicroscopia', 'fundoscopia', 'obs_geral'];

        expect(recordColumnOrder(LEFT, savedLeft)).toEqual(['estatica', 'cromatica_ppc_cover', 'av_sem_tono', 'av_com', 'tonometria', 'dinamica']);
        expect(recordColumnOrder(RIGHT, savedRight)).toEqual(['adicao', 'biomicroscopia', 'fundoscopia', 'obs_geral']);
    });

    it('sem modelo salvo: ordem padrão', () => {
        expect(recordColumnOrder(LEFT, undefined)).toEqual(LEFT);
        expect(recordColumnOrder(LEFT, null)).toEqual(LEFT);
    });

    it('modelo já novo: respeita a ordem do médico; ignora chaves desconhecidas e repetidas', () => {
        expect(recordColumnOrder(LEFT, ['dinamica', 'tonometria', 'av_com', 'x', 'dinamica', 'estatica', 'av_sem_tono', 'cromatica_ppc_cover']))
            .toEqual(['dinamica', 'tonometria', 'av_com', 'estatica', 'av_sem_tono', 'cromatica_ppc_cover']);
    });

    it('seção sem antecessora no padrão entra no topo', () => {
        expect(recordColumnOrder(LEFT, ['dinamica', 'estatica'])).toEqual(LEFT);
    });
});

describe('MedicalRecordForm — organização da coluna esquerda', () => {
    const source  = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue'), 'utf8');
    const styles  = readFileSync(resolve(process.cwd(), 'resources/css/system/_medical-records.scss'), 'utf8');
    const left    = source.slice(source.indexOf('<!-- COLUNA ESQUERDA -->'), source.indexOf('<!-- COLUNA DIREITA -->'));
    const right   = source.slice(source.indexOf('<!-- COLUNA DIREITA -->'));
    const at      = (block, key) => block.indexOf(`sectionStyle('${key}')`);
    /** Marcação da seção (do seu <div> até a próxima seção). */
    const section = (key) => {
        const start = left.lastIndexOf('<div class="pmr-section', at(left, key));
        const next  = left.indexOf('<div class="pmr-section', at(left, key));

        return left.slice(start, next === -1 ? undefined : next);
    };

    it('ordem: A/V sem correção → A/V com correção → Tonometria → Dinâmica → Estática', () => {
        expect(at(left, 'av_sem_tono')).toBeGreaterThan(-1);
        expect(at(left, 'av_com')).toBeGreaterThan(at(left, 'av_sem_tono'));
        expect(at(left, 'tonometria')).toBeGreaterThan(at(left, 'av_com'));
        expect(at(left, 'dinamica')).toBeGreaterThan(at(left, 'tonometria'));
        expect(at(left, 'estatica')).toBeGreaterThan(at(left, 'dinamica'));
    });

    it('A/V sem, A/V com e Tonometria são blocos em linha num painel em fluxo (sem vão ao lado de A/V com)', () => {
        expect(left).toContain('class="pmr-main-panel pmr-main-panel--flow"');
        for (const key of ['av_sem_tono', 'av_com', 'tonometria']) {
            expect(section(key)).toContain('pmr-section--inline');
        }
        expect(section('dinamica')).not.toContain('pmr-section--inline');

        // O bloco que sobra sozinho numa linha cresce até a largura toda.
        expect(styles).toMatch(/\.pmr-main-panel--flow\s*\{[^}]*flex-wrap:\s*wrap/);
        expect(styles).toMatch(/\.pmr-section--inline\s*\{[^}]*flex:\s*1 1/);
    });

    it('Tonometria é seção própria; A/V sem correção não carrega mais os campos dela', () => {
        expect(section('tonometria')).toContain('v-model="form.tonometer_right"');
        expect(section('av_sem_tono')).not.toContain('tonometer_right');
        expect(section('av_sem_tono')).toContain('v-model="form.visual_acuity_without_correction_right_id"');
    });

    it('campos da Tonometria ocupam a largura do bloco (sem largura fixa)', () => {
        const tono = section('tonometria');

        expect(tono).not.toMatch(/max-width\s*:/);
        expect(tono.split('pmr-tono-eye')).toHaveLength(3); // OD e OE
        expect(tono).toContain('pmr-tono-time');
        expect(styles).toMatch(/\.pmr-tono-eye\s*\{[^}]*flex:\s*1 1/);
        expect(styles).toMatch(/\.pmr-tono-time\s*\{[^}]*flex:\s*1 1/);
    });

    it('os campos continuam os mesmos, uma vez só na tela, e nenhum ficou na coluna direita', () => {
        for (const field of [
            'visual_acuity_without_correction_right_id', 'visual_acuity_without_correction_left_id',
            'visual_acuity_with_correction_right_id', 'visual_acuity_with_correction_left_id',
            'tonometer_right', 'tonometer_left',
        ]) {
            expect(source.split(`v-model="form.${field}"`)).toHaveLength(2);
            expect(left).toContain(`v-model="form.${field}"`);
        }
        expect(at(right, 'av_com')).toBe(-1);
        expect(at(right, 'biomicroscopia')).toBeGreaterThan(-1);
        expect(at(right, 'fundoscopia')).toBeGreaterThan(at(right, 'biomicroscopia'));
        expect(at(right, 'obs_geral')).toBeGreaterThan(at(right, 'fundoscopia'));
    });

    it('Adição, Longe, Perto e botões na mesma linha quando cabem; lentes não aumentam a altura', () => {
        const start = right.lastIndexOf('<div class="pmr-section', at(right, 'adicao'));
        const lens  = right.slice(start, right.indexOf('<div class="pmr-section', at(right, 'adicao')));

        // Antes: col-6 (2 por linha) — Perto e o lápis sempre na linha de baixo.
        expect(lens).not.toContain('col-6');
        expect(lens.split('class="pmr-lens-field')).toHaveLength(4); // Adição, Longe, Perto
        expect(lens).toContain('pmr-lens-field--short');
        // Botões na altura dos campos: espaço do rótulo, invisível para leitores de tela.
        expect(lens).toMatch(/<div class="col-auto">\s*<!--[^>]*-->\s*<span class="pmr-label d-inline-block invisible" aria-hidden="true">/);

        // Base mínima + crescimento (>= 1, o campo sozinho vai até a borda);
        // a linha quebra só quando falta espaço.
        expect(styles).toMatch(/\.pmr-lens-field\s*\{[^}]*flex:\s*1\.3 1 \d+px;[^}]*min-width:\s*0/);
        expect(styles).toMatch(/\.pmr-lens-field--short\s*\{[^}]*flex:\s*1 1 \d+px/);
        // Longe/Perto: SearchSelect multiple (resumo numa linha — ver
        // SearchSelectMultiple.test.js); nenhuma regra solta a altura fixa.
        expect(lens.split(/<SearchSelect[^>]*?\bmultiple\b/)).toHaveLength(3);
        expect(styles).not.toMatch(/\.pmr-lens-field[^{]*\{[^}]*height:\s*auto/);

        // Mesmos campos, uma vez só.
        for (const field of ['addition_type_id', 'lens_away_ids', 'lens_near_ids', 'observation_of_lenses']) {
            expect(source.split(`v-model="form.${field}"`)).toHaveLength(2);
            expect(lens).toContain(`v-model="form.${field}"`);
        }
    });

    it('registro do "Meu prontuário": Tonometria depois de A/V com correção, rótulos pelas traduções', () => {
        const defs = source.slice(source.indexOf('const SECTION_DEFS'), source.indexOf('];', source.indexOf('const SECTION_DEFS')));

        expect(defs).toMatch(/key: 'av_com',\s+col: 'left'/);
        expect(defs).toMatch(/key: 'tonometria',\s+col: 'left',\s+labelKeys: \['tonometry'\]/);
        expect(defs.indexOf("key: 'av_com'")).toBeGreaterThan(defs.indexOf("key: 'av_sem_tono'"));
        expect(defs.indexOf("key: 'tonometria'")).toBeGreaterThan(defs.indexOf("key: 'av_com'"));
        expect(defs.indexOf("key: 'tonometria'")).toBeLessThan(defs.indexOf("key: 'dinamica'"));
    });
});
