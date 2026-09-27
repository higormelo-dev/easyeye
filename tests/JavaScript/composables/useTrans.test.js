import { describe, it, expect } from 'vitest';
import { useTrans } from '@/composables/useTrans';

describe('useTrans.tx', () => {
    it('placeholder com prefixo de outro não corrompe o texto (:page × :pages)', () => {
        const { tx } = useTrans({ pager: 'Página :page de :pages' });

        expect(tx('pager', { page: 1, pages: 12 })).toBe('Página 1 de 12');
    });

    it('valor que contém ":x" não é substituído de novo', () => {
        const { tx } = useTrans({ msg: ':a e :b' });

        expect(tx('msg', { a: ':b', b: 'B' })).toBe(':b e B');
    });

    it('mantém placeholders sem valor e textos com ":" que não são parâmetros', () => {
        const { tx } = useTrans({ msg: 'Horário 10:30 — :name / :missing' });

        expect(tx('msg', { name: 'Ana' })).toBe('Horário 10:30 — Ana / :missing');
    });

    it('chave inexistente volta a própria chave; getter reativo lê o objeto atual', () => {
        let t = { a: 'A' };
        const { tx } = useTrans(() => t);

        expect(tx('b')).toBe('b');
        t = { a: 'A2' };
        expect(tx('a')).toBe('A2');
    });
});
