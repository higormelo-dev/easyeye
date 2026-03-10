# 🎨 Guia de Estilos de Botões - Bootstrap 5.3

## 📋 Classes Disponíveis para Botões Menores e Discretos

### 1️⃣ `.btn-xs` - Extra Small (Menor Disponível)

Botão extra pequeno, ideal para ações secundárias em tabelas ou cards compactos.

```html
<!-- Antes (grande) -->
<button class="btn waves-effect waves-light btn-secondary m-1">
    <i class="fas fa-edit"></i> Editar
</button>

<!-- Depois (extra pequeno e discreto) -->
<button class="btn btn-xs waves-effect waves-light btn-secondary m-1">
    <i class="fas fa-edit"></i> Editar
</button>
```

**Especificações:**
- Padding: `0.15rem × 0.4rem`
- Tamanho da fonte: `0.75rem` (12px)
- Ícones reduzidos automaticamente

---

### 2️⃣ `.btn-sm-discrete` - Small Discreto

Botão pequeno com aparência mais sutil, fonte mais leve.

```html
<button class="btn btn-sm-discrete waves-effect waves-light btn-secondary m-1">
    <i class="fas fa-eye"></i> Visualizar
</button>
```

**Especificações:**
- Padding: `0.25rem × 0.5rem`
- Tamanho da fonte: `0.8125rem` (13px)
- Font-weight: `400` (mais leve)

---

### 3️⃣ `.btn-discrete` - Secundário Discreto

Torna botões secundários ainda mais discretos com cores suaves.

```html
<button class="btn waves-effect waves-light btn-secondary btn-discrete m-1">
    <i class="fas fa-trash"></i> Excluir
</button>
```

**Cores:**
- Background: Cinza muito claro (`#f8f9fa`)
- Borda: Cinza suave (`#dee2e6`)
- Texto: Cinza médio (`#6c757d`)
- Hover: Cinza um pouco mais escuro

---

### 4️⃣ `.btn-soft` - Outline Suave

Botões outline com bordas e cores mais suaves.

```html
<button class="btn btn-outline-secondary btn-soft">
    <i class="fas fa-times"></i> Cancelar
</button>
```

---

## 🎯 Combinações Recomendadas

### ✅ Para DataTables (Ações em Linhas)

```html
<!-- Opção 1: Extra pequeno -->
<button class="btn btn-xs btn-secondary">
    <i class="fas fa-edit"></i>
</button>

<!-- Opção 2: Extra pequeno + discreto -->
<button class="btn btn-xs btn-secondary btn-discrete">
    <i class="fas fa-trash"></i>
</button>

<!-- Opção 3: Small outline suave -->
<button class="btn btn-sm btn-outline-secondary btn-soft">
    <i class="fas fa-eye"></i> Ver
</button>
```

### ✅ Para Toolbars

```html
<!-- Grupo de botões pequenos -->
<div class="btn-group btn-group-sm" role="group">
    <button class="btn btn-outline-secondary btn-soft">
        <i class="fa fa-list"></i> Lista
    </button>
    <button class="btn btn-outline-secondary btn-soft">
        <i class="fa fa-th"></i> Cards
    </button>
</div>
```

### ✅ Para Cards

```html
<!-- Botões no footer do card -->
<div class="card-footer text-end">
    <button class="btn btn-sm-discrete btn-outline-secondary">
        Cancelar
    </button>
    <button class="btn btn-sm btn-primary">
        Confirmar
    </button>
</div>
```

---

## 📊 Comparação de Tamanhos

| Classe | Tamanho Fonte | Padding Vertical | Padding Horizontal |
|--------|---------------|------------------|-------------------|
| `.btn` (padrão) | 1rem (16px) | 0.375rem | 0.75rem |
| `.btn-sm` | 0.875rem (14px) | 0.25rem | 0.5rem |
| `.btn-sm-discrete` | 0.8125rem (13px) | 0.25rem | 0.5rem |
| `.btn-xs` | 0.75rem (12px) | 0.15rem | 0.4rem |

---

## 🔧 Exemplos Práticos de Migração

### Antes (Grandes e Destacados)

```html
<button class="btn waves-effect waves-light btn-secondary btn-xs m-1">
    <i class="fas fa-edit"></i> Editar
</button>
```

**Problema:** Mesmo com `btn-xs`, o botão ainda parece grande porque o Bootstrap 5 não suporta nativamente `btn-xs`.

### Depois (Opções de Correção)

#### **Opção 1: Usar o novo `.btn-xs` customizado**
```html
<button class="btn btn-xs waves-effect waves-light btn-secondary btn-discrete m-1">
    <i class="fas fa-edit"></i> Editar
</button>
```

#### **Opção 2: Apenas ícone (mais compacto)**
```html
<button class="btn btn-xs btn-secondary btn-discrete m-1" title="Editar">
    <i class="fas fa-edit"></i>
</button>
```

#### **Opção 3: Outline suave com tamanho pequeno**
```html
<button class="btn btn-sm btn-outline-secondary btn-soft m-1">
    <i class="fas fa-edit"></i>
</button>
```

---

## 💡 Dicas de Uso

### ✅ Faça:
- Use `.btn-xs` para ações em tabelas DataTables
- Combine `.btn-discrete` com `.btn-secondary` para botões mais suaves
- Use apenas ícones em botões muito pequenos
- Adicione `title` ou `aria-label` quando remover texto

### ❌ Evite:
- Combinar `.btn-xs` com muitos ícones grandes
- Usar texto muito longo em `.btn-xs`
- Misturar tamanhos diferentes no mesmo grupo de botões
- Esquecer de testar acessibilidade

---

## 🎨 Customização Adicional

Se precisar ajustar ainda mais, você pode criar classes específicas:

```css
/* No seu arquivo CSS customizado */
.btn-micro {
    --bs-btn-padding-y: 0.1rem;
    --bs-btn-padding-x: 0.3rem;
    --bs-btn-font-size: 0.7rem;
}

.btn-ghost {
    background: transparent;
    border: none;
    color: #6c757d;
}
.btn-ghost:hover {
    background: #f8f9fa;
    color: #495057;
}
```

---

## 📱 Responsividade

Para tamanhos diferentes em mobile vs desktop:

```html
<button class="btn btn-xs btn-sm-md btn-secondary">
    <i class="fas fa-edit"></i>
    <span class="d-none d-md-inline">Editar</span>
</button>
```

Adicione ao CSS:
```css
@media (min-width: 768px) {
    .btn-sm-md {
        --bs-btn-padding-y: 0.25rem;
        --bs-btn-padding-x: 0.5rem;
        --bs-btn-font-size: 0.875rem;
    }
}
```

---

## ✅ Checklist de Implementação

- [ ] Adicionar classes customizadas no `resources/css/app.css` ✅ (Já feito)
- [ ] Compilar assets com `npm run dev`
- [ ] Identificar botões que precisam ser menores
- [ ] Substituir classes antigas por novas
- [ ] Testar visualmente em diferentes telas
- [ ] Verificar acessibilidade (contraste, tamanho mínimo de toque)
- [ ] Testar em mobile (mínimo 44×44px para toque)

---

**Data:** 2026-03-10
**Bootstrap:** 5.3.8
**Status:** ✅ Implementado
