#!/usr/bin/env python3
"""Gera vectors.json — vetores dourados do App\\Support\\CanonicalJson v1.

Implementação INDEPENDENTE da do PHP (json.dumps do Python + NFC + sha256), para que
o teste compare duas implementações e não o PHP consigo mesmo. Os vetores são
CONGELADOS: se um valor daqui mudar, o algoritmo mudou — isso é uma NOVA versão
(CanonicalJson::VERSION), nunca um ajuste de vetor.

Uso: python3 tests/Fixtures/canonical-json/generate_vectors.py > tests/Fixtures/canonical-json/vectors.json
"""
import hashlib
import json
import unicodedata


def nfc(value):
    if isinstance(value, str):
        return unicodedata.normalize('NFC', value)
    if isinstance(value, list):
        return [nfc(item) for item in value]
    if isinstance(value, dict):
        out = {}
        for key, item in value.items():
            name = unicodedata.normalize('NFC', key)
            assert name not in out, 'chave repetida depois do NFC'
            out[name] = nfc(item)
        return out
    return value


def canonical(value):
    return json.dumps(nfc(value), ensure_ascii=False, separators=(',', ':'), sort_keys=True)


def digest(domain, text):
    return '1:' + hashlib.sha256((domain + '\n' + text).encode('utf-8')).hexdigest()


PROTOCOL = {
    'schema_version': 1,
    'key': 'glaucoma_poag',
    'entry': {'cid': {'strong': ['H40.1', 'H40.2'], 'weak': [], 'exclude': ['H40.0']}, 'medications': []},
    'tasks': [
        {
            'key': 'consulta',
            'kind': 'consultation',
            'interval': {'unit': 'months', 'default': 4, 'min': 3, 'max': 6},
            'lead_days': 30,
            'grace_days': 30,
            'lost_after_days': 180,
            'recurs': True,
            'required': True,
        }
    ],
    'references': [{'title': 'EGS 5ª ed. 2021', 'year': 2021}],
    'rationale': 'Texto com acentuação: revisão semestral ≈ 4–6 meses.',
}

CASES = [
    ('basic-order', 'easyeye.test.v1', {'b': 1, 'a': {'y': True, 'x': None}, 'c': [3, 1, 2], 'd': 'texto'}),
    (
        'escapes',
        'easyeye.test.v1',
        'aspas" barra\\ solta/ lf\n cr\r tab\t bs\b ff\f nul\u0000 us\u001f del\u007f ls  ps  emoji\U0001F600 acento é',
    ),
    ('nfc-values-and-keys', 'easyeye.test.v1', {'é': 'á̂', 'z': ['ö', 'ç']}),
    (
        'key-order-by-code-point',
        'easyeye.test.v1',
        {'\U00010000': 1, '￿': 2, 'é': 3, 'z': 4, 'a': 5, '10': 6, '9': 7, 'A': 8},
    ),
    ('ints-bools-null', 'easyeye.test.v1', [0, -1, 9007199254740991, -9007199254740991, True, False, None]),
    ('empty-containers', 'easyeye.test.v1', {'a': [], 'b': [[]], 'c': [{'d': []}]}),
    ('protocol-like', 'easyeye.recall.protocol.v1', PROTOCOL),
    ('protocol-like-other-domain', 'easyeye.clinical_review.set.v1', PROTOCOL),
]

vectors = []
for name, domain, value in CASES:
    text = canonical(value)
    vectors.append({'name': name, 'domain': domain, 'input': value, 'canonical': text, 'hash': digest(domain, text)})

print(json.dumps(vectors, ensure_ascii=True, indent=2))
