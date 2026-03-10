# 🚀 Guia de Migração: Bootstrap 5.0 → 5.3

## ✅ O que foi atualizado automaticamente

O `package.json` foi atualizado com as seguintes versões:

| Pacote | Versão Anterior | Versão Nova |
|--------|----------------|-------------|
| bootstrap | ^5.0.2 | ^5.3.8 |
| jquery | ^3.3.1 | ^3.7.1 |
| datatables.net | ~1.13 | ~2.3 |
| datatables.net-bs4 | ~1.13 | **REMOVIDO** |
| datatables.net-bs5 | - | ~2.3 ✨ |
| datatables.net-responsive | ~2.5 | ~3.0 |
| datatables.net-responsive-bs4 | ~2.5 | **REMOVIDO** |
| datatables.net-responsive-bs5 | - | ~3.0 ✨ |
| perfect-scrollbar | ^0.7.1 | ^1.5.5 |
| sweetalert2 | ^11.0.0 | ^11.15.3 |

---

## 📋 PASSOS PARA COMPLETAR A MIGRAÇÃO

### 1️⃣ Executar o script de instalação

**IMPORTANTE:** Execute este comando **DENTRO DO WSL**, não do Windows:

```bash
# Entre no WSL
wsl

# Navegue até o projeto
cd /home/higor/Projetos/Pessoal/MinhaEmpresa/medicare

# Execute o script
./upgrade-bootstrap.sh
```

Se você já está no terminal do WSL, apenas execute:
```bash
./upgrade-bootstrap.sh
```

---

### 2️⃣ Atualizar imports JavaScript

Você precisa trocar as referências de DataTables BS4 para BS5:

#### ❌ ANTES (BS4):
```javascript
import 'datatables.net-bs4';
import 'datatables.net-responsive-bs4';
```

#### ✅ DEPOIS (BS5):
```javascript
import 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';
```

---

### 3️⃣ Arquivos que precisam ser verificados

Os seguintes arquivos podem ter referências a DataTables que precisam ser atualizadas:

```
resources/js/vendor.js (se existir)
resources/views/system/users/index.blade.php
resources/views/system/patients/index.blade.php
resources/views/system/doctors/index.blade.php
resources/views/system/manager/entity_integrators/index.blade.php
resources/views/system/manager/entities/index.blade.php
resources/views/vendor/datatables/print.blade.php
```

---

### 4️⃣ Atualizar CSS do DataTables

Se você estiver importando CSS do DataTables manualmente, atualize:

#### ❌ ANTES:
```html
<link rel="stylesheet" href="node_modules/datatables.net-bs4/css/dataTables.bootstrap4.css">
```

#### ✅ DEPOIS:
```html
<link rel="stylesheet" href="node_modules/datatables.net-bs5/css/dataTables.bootstrap5.css">
```

Ou se estiver usando imports no JavaScript:

#### ❌ ANTES:
```javascript
import 'datatables.net-bs4/css/dataTables.bootstrap4.css';
```

#### ✅ DEPOIS:
```javascript
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';
```

---

## 🔍 Breaking Changes do Bootstrap 5.3

### Mudanças Menores (provavelmente não afetam seu código):

1. **Links coloridos** - Agora têm `!important` novamente
2. **Dark mode** - Cores derivadas de theme colors (se você usar dark mode)
3. **Display utilities** - Novo `.d-inline-grid`
4. **CSS Variables** - Algumas variáveis CSS foram removidas/consolidadas

### ✅ Boa notícia:
Bootstrap 5.0 → 5.3 é uma **minor update**, não uma major. Isso significa:
- ✅ Sem breaking changes significativos
- ✅ Classes CSS são 100% compatíveis
- ✅ JavaScript API é compatível
- ✅ Componentes funcionam da mesma forma

---

## 🧪 Testando a Migração

### 1. Build do projeto
```bash
npm run build
```

### 2. Servidor de desenvolvimento
```bash
npm run dev
```

### 3. Verificações importantes:

- [ ] Tabelas DataTables aparecem corretamente
- [ ] Paginação funciona
- [ ] Filtros e busca funcionam
- [ ] Modais abrem/fecham corretamente
- [ ] Dropdowns funcionam
- [ ] Tooltips aparecem
- [ ] Formulários validam
- [ ] SweetAlert2 exibe alertas corretamente
- [ ] Scrollbar personalizado funciona

---

## ⚠️ Problemas Conhecidos

### Morris.js - Warning de Engine
```
npm warn EBADENGINE package: 'morris.js@0.5.0'
```

**Causa:** Morris.js é muito antigo (2014) e não é mais mantido.

**Impacto:** Apenas um warning, não impede o funcionamento.

**Solução futura:** Considere migrar para uma biblioteca moderna como:
- Chart.js
- ApexCharts
- Recharts

---

## 🔄 Rollback (se necessário)

Se algo der errado, você pode voltar à versão anterior:

```bash
# Restaurar package.json original
cp package.json.backup package.json

# Reinstalar dependências antigas
rm -rf node_modules package-lock.json
npm install
```

---

## 📚 Recursos Adicionais

- [Bootstrap 5.3 Release Notes](https://getbootstrap.com/docs/5.3/migration/)
- [DataTables Bootstrap 5 Documentation](https://datatables.net/examples/styling/bootstrap5)
- [jQuery 3.7 Release Notes](https://blog.jquery.com/2023/05/11/jquery-3-7-0-released-staying-in-order/)

---

## ✅ Checklist Final

Antes de fazer commit das mudanças:

- [ ] Script `./upgrade-bootstrap.sh` executado com sucesso
- [ ] `npm run dev` funciona sem erros
- [ ] Imports de datatables.net-bs4 trocados para bs5
- [ ] Todos os componentes testados visualmente
- [ ] Build de produção criado com `npm run build`
- [ ] Aplicação testada em navegadores principais

---

## 🆘 Suporte

Se encontrar problemas:

1. Verifique se executou o script **dentro do WSL**
2. Limpe completamente: `rm -rf node_modules package-lock.json && npm install`
3. Verifique os logs de erro do navegador (F12 → Console)
4. Compare com este guia para garantir que seguiu todos os passos

---

**Data da migração:** 2026-03-10
**Versão do Bootstrap:** 5.0.2 → 5.3.8
**Status:** ⏳ Pendente (aguardando instalação das dependências)
