import { describe, it, expect } from 'vitest';
import { maskCpf, maskCnpj, maskCpfCnpj, maskPhone, maskCep, MASKS, MASK_KEEP, isFormattable } from '@/utils/masks.js';

describe('maskCpf', () => {
    it.each([
        ['', ''],
        [null, ''],
        [undefined, ''],
        ['123', '123'],
        ['1234', '123.4'],
        ['1234567', '123.456.7'],
        ['12345678901', '123.456.789-01'],
        ['123.456.789-01', '123.456.789-01'],
        ['123456789012345', '123.456.789-01'], // excedente descartado
        ['12a3.4b5', '123.45'], // não-dígitos descartados
    ])('%s → %s', (input, expected) => {
        expect(maskCpf(input)).toBe(expected);
    });

    it('não deixa separador pendurado ao apagar (backspace após o ponto)', () => {
        expect(maskCpf('123.')).toBe('123');
    });
});

describe('maskCnpj', () => {
    it.each([
        ['12', '12'],
        ['123', '12.3'],
        ['12345678', '12.345.678'],
        ['123456789', '12.345.678/9'],
        ['12345678000190', '12.345.678/0001-90'],
        ['12.345.678/0001-90', '12.345.678/0001-90'],
    ])('%s → %s', (input, expected) => {
        expect(maskCnpj(input)).toBe(expected);
    });
});

describe('maskCnpj alfanumérico (IN RFB 2.229/2024)', () => {
    it.each([
        ['12ABC34501DE35', '12.ABC.345/01DE-35'],
        ['12.abc.345/01de-35', '12.ABC.345/01DE-35'], // minúsculas viram maiúsculas
        ['12ABC', '12.ABC'],
        ['12ABC34501DEX5', '12.ABC.345/01DE-5'], // DV nunca aceita letra
    ])('%s → %s', (input, expected) => {
        expect(maskCnpj(input)).toBe(expected);
    });
});

describe('maskCpfCnpj', () => {
    it('vira CNPJ assim que aparece letra (CPF nunca tem letra)', () => {
        expect(maskCpfCnpj('12A')).toBe('12.A');
        expect(maskCpfCnpj('12ABC34501DE35')).toBe('12.ABC.345/01DE-35');
    });

    it('formata como CPF até 11 dígitos', () => {
        expect(maskCpfCnpj('12345678901')).toBe('123.456.789-01');
    });

    it('vira CNPJ a partir do 12º dígito', () => {
        expect(maskCpfCnpj('123456789012')).toBe('12.345.678/9012');
        expect(maskCpfCnpj('12345678000190')).toBe('12.345.678/0001-90');
    });

    it('aceita CNPJ já formatado', () => {
        expect(maskCpfCnpj('12.345.678/0001-90')).toBe('12.345.678/0001-90');
    });
});

describe('maskPhone', () => {
    it.each([
        ['', ''],
        [null, ''],
        ['6', '(6'],
        ['61', '(61'],
        ['619', '(61) 9'],
        ['613333', '(61) 3333'],
        ['6133334', '(61) 3333-4'],
        ['6133334444', '(61) 3333-4444'], // fixo
        ['61999998888', '(61) 99999-8888'], // celular
        ['(61) 99999-8888', '(61) 99999-8888'],
        ['619999988887777', '(61) 99999-8888'], // excedente descartado
    ])('%s → %s', (input, expected) => {
        expect(maskPhone(input)).toBe(expected);
    });

    it('descarta DDI +55 de número colado (ex.: WhatsApp)', () => {
        expect(maskPhone('+55 61 99999-8888')).toBe('(61) 99999-8888');
        expect(maskPhone('+5561999998888')).toBe('(61) 99999-8888');
    });

    it('não confunde DDD 55 (RS) com DDI quando não há "+"', () => {
        expect(maskPhone('55999998888')).toBe('(55) 99999-8888');
        expect(maskPhone('5533334444')).toBe('(55) 3333-4444');
    });

    it('descarta DDI de legado gravado só com dígitos (12/13 dígitos começando por 55)', () => {
        expect(maskPhone('5561999998888')).toBe('(61) 99999-8888');
        expect(maskPhone('556133334444')).toBe('(61) 3333-4444');
    });

    it('"+55" digitado caractere a caractere vira número BR sem o DDI', () => {
        expect(['+', '+5', '+55', '+556', '+5561', '+55619'].map(maskPhone)).toEqual([
            '+',
            '+5',
            '+55',
            '(6',
            '(61',
            '(61) 9',
        ]);
    });

    it.each([
        ['+34 912 345 678', '+34912345678'],
        ['+1 (305) 555-0100', '+13055550100'],
        ['+351912345678', '+351912345678'],
    ])('DDI estrangeiro %s não vira número BR → %s', (input, expected) => {
        expect(maskPhone(input)).toBe(expected);
    });

    it('digitar além do limite num número já formatado descarta o excedente (não trata como DDI)', () => {
        expect(maskPhone('(55) 99999-88887')).toBe('(55) 99999-8888');
    });

    it.each([
        ['0800', '0800'],
        ['08001', '0800 1'],
        ['08001234567', '0800 123 4567'],
        ['0300 123 4567', '0300 123 4567'],
        ['40040001', '4004-0001'],
        ['3003-1234', '3003-1234'],
        ['400400019', '4004-0001'],
    ])('não geográfico %s → %s (não existe DDD 0x, 30 ou 40)', (input, expected) => {
        expect(maskPhone(input)).toBe(expected);
    });

    it('não deixa separador pendurado ao apagar', () => {
        expect(maskPhone('(61) ')).toBe('(61');
        expect(maskPhone('(')).toBe('');
    });
});

describe('maskCep', () => {
    it.each([
        ['01310', '01310'],
        ['013101', '01310-1'],
        ['01310100', '01310-100'],
        ['01310-100', '01310-100'],
        ['0131010099', '01310-100'],
    ])('%s → %s', (input, expected) => {
        expect(maskCep(input)).toBe(expected);
    });
});

describe('isFormattable (valor vindo do servidor)', () => {
    it.each([
        ['phone', '61999998888'],
        ['phone', '6133334444'],
        ['phone', '5561999998888'],
        ['phone', '08001234567'],
        ['phone', '40040001'],
        ['phone', '+34912345678'],
        ['cpf', '12345678901'],
        ['cnpj', '12ABC34501DE35'],
        ['cpfCnpj', '12345678000190'],
        ['cep', '01310100'],
    ])('%s %s → formata', (mask, value) => {
        expect(isFormattable(mask, value)).toBe(true);
    });

    it.each([
        ['phone', '(61) 3333-4444 r.21'], // ramal
        ['phone', '6133334444 / 61999998888'], // dois números
        ['phone', '33334444'], // sem DDD
        ['phone', '011987654321'], // prefixo de operadora
        ['phone', '351912345678'], // estrangeiro sem "+"
        ['cpf', '1234'],
        ['cep', '0131'],
        ['phone', ''],
        ['inexistente', '123'],
    ])('%s %s → mantém como está', (mask, value) => {
        expect(isFormattable(mask, value)).toBe(false);
    });
});

describe('MASKS', () => {
    it('expõe todos os formatadores por nome e é imutável', () => {
        expect(Object.keys(MASKS).sort()).toEqual(['cep', 'cnpj', 'cpf', 'cpfCnpj', 'phone']);
        expect(Object.isFrozen(MASKS)).toBe(true);
    });

    it('MASK_KEEP aceita letras só nas máscaras de CNPJ', () => {
        expect(MASK_KEEP.cnpj.test('A')).toBe(true);
        expect(MASK_KEEP.cpfCnpj.test('A')).toBe(true);
        expect(MASK_KEEP.cpf).toBeUndefined();
    });
});
