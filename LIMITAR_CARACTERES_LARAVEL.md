# 🔤 Limitar Caracteres no Laravel

## 🎯 Soluções para `auth()->user()->name`

---

## 1️⃣ **Helper `Str::limit()` (RECOMENDADO)**

### No Blade:
```blade
{{ Str::limit(auth()->user()->name, 20) }}
```

**Resultado:**
- Nome: "João Pedro Silva Santos"
- Exibe: "João Pedro Silva San..."

### Com sufixo customizado:
```blade
{{ Str::limit(auth()->user()->name, 20, '…') }}
```

---

## 2️⃣ **Helper `str()` (Laravel 9+)**

```blade
{{ str(auth()->user()->name)->limit(20) }}
```

**Com fluent syntax:**
```blade
{{ str(auth()->user()->name)->limit(20)->upper() }}
```

---

## 3️⃣ **Função `substr()` do PHP**

```blade
{{ substr(auth()->user()->name, 0, 20) }}
```

**Problema:** Não adiciona "..." automaticamente.

**Solução completa:**
```blade
{{ strlen(auth()->user()->name) > 20 ? substr(auth()->user()->name, 0, 20) . '...' : auth()->user()->name }}
```

---

## 4️⃣ **Helper `Str::words()` (Por Palavras)**

Limita por número de palavras em vez de caracteres:

```blade
{{ Str::words(auth()->user()->name, 2) }}
```

**Resultado:**
- Nome: "João Pedro Silva Santos"
- Exibe: "João Pedro..."

---

## 5️⃣ **Accessor no Model (Melhor para reutilização)**

No model `User.php`:

```php
use Illuminate\Support\Str;

class User extends Authenticatable
{
    // Accessor para nome curto
    public function getShortNameAttribute()
    {
        return Str::limit($this->name, 20);
    }

    // Accessor para nome médio
    public function getMediumNameAttribute()
    {
        return Str::limit($this->name, 30);
    }

    // Accessor para primeiro nome
    public function getFirstNameAttribute()
    {
        return Str::before($this->name, ' ');
    }
}
```

### Usando no Blade:
```blade
{{ auth()->user()->short_name }}
{{ auth()->user()->medium_name }}
{{ auth()->user()->first_name }}
```

---

## 6️⃣ **Blade Component (Reutilizável)**

Criar: `resources/views/components/truncate.blade.php`

```blade
@props(['text', 'limit' => 20])

<span {{ $attributes }}>
    {{ Str::limit($text, $limit) }}
</span>
```

### Usando:
```blade
<x-truncate :text="auth()->user()->name" :limit="25" />

<!-- Com classes CSS -->
<x-truncate :text="auth()->user()->name" :limit="20" class="fw-bold" />
```

---

## 7️⃣ **Directive Blade Customizada**

No `AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

public function boot()
{
    Blade::directive('limit', function ($expression) {
        return "<?php echo Str::limit($expression); ?>";
    });
}
```

### Usando:
```blade
@limit(auth()->user()->name, 20)
```

---

## 8️⃣ **Com Tooltip (Mostrar nome completo ao passar mouse)**

```blade
<span
    data-bs-toggle="tooltip"
    data-bs-placement="top"
    title="{{ auth()->user()->name }}"
    style="cursor: help;">
    {{ Str::limit(auth()->user()->name, 20) }}
</span>
```

---

## 📊 Comparação de Métodos

| Método | Facilidade | Performance | Reutilizável | Recomendado |
|--------|-----------|-------------|--------------|-------------|
| `Str::limit()` | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ✅ |
| `str()->limit()` | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ | ✅ |
| `substr()` | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐ | ⚠️ |
| Accessor | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅✅ |
| Component | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ✅ |
| Directive | ⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⚠️ |

---

## 💡 Exemplos Práticos

### No Header/Navbar:
```blade
<div class="navbar-text">
    Olá, {{ Str::limit(auth()->user()->name, 15) }}
</div>
```

### Em DataTables:
```php
// No DataTable
Column::make('name')
    ->title('Nome')
    ->render(function($user) {
        return Str::limit($user->name, 25);
    }),
```

### Em Cards:
```blade
<div class="card">
    <div class="card-header">
        <h5 class="card-title" title="{{ auth()->user()->name }}">
            {{ Str::limit(auth()->user()->name, 20) }}
        </h5>
    </div>
</div>
```

### Em Listas:
```blade
<ul class="list-group">
    @foreach($users as $user)
        <li class="list-group-item">
            {{ Str::limit($user->name, 30) }}
        </li>
    @endforeach
</ul>
```

---

## 🔧 Funções Úteis Extras

### Apenas Primeiro Nome:
```blade
{{ Str::before(auth()->user()->name, ' ') }}
```

### Apenas Último Nome:
```blade
{{ Str::afterLast(auth()->user()->name, ' ') }}
```

### Iniciais:
```blade
@php
    $name = auth()->user()->name;
    $initials = collect(explode(' ', $name))
        ->map(fn($word) => strtoupper(substr($word, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
{{ $initials }}
```

**Resultado:** "João Pedro Silva" → "JP"

### Nome Abreviado (Primeiro + Inicial do Último):
```blade
@php
    $parts = explode(' ', auth()->user()->name);
    $firstName = $parts[0];
    $lastInitial = isset($parts[count($parts)-1]) ? strtoupper(substr($parts[count($parts)-1], 0, 1)) . '.' : '';
@endphp
{{ $firstName }} {{ $lastInitial }}
```

**Resultado:** "João Pedro Silva Santos" → "João S."

---

## ✅ Solução Completa Recomendada

### 1. Criar Accessor no Model `User.php`:

```php
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /**
     * Nome limitado a 20 caracteres
     */
    public function getShortNameAttribute(): string
    {
        return Str::limit($this->name, 20);
    }

    /**
     * Apenas primeiro nome
     */
    public function getFirstNameAttribute(): string
    {
        return Str::before($this->name, ' ');
    }

    /**
     * Iniciais (2 letras)
     */
    public function getInitialsAttribute(): string
    {
        return collect(explode(' ', $this->name))
            ->map(fn($word) => strtoupper(substr($word, 0, 1)))
            ->take(2)
            ->implode('');
    }
}
```

### 2. Usar nos Blades:

```blade
<!-- Nome completo limitado -->
{{ auth()->user()->short_name }}

<!-- Primeiro nome -->
{{ auth()->user()->first_name }}

<!-- Iniciais -->
{{ auth()->user()->initials }}
```

---

## 📱 Responsivo com CSS + Laravel

Combine com CSS para diferentes telas:

```blade
<span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
<span class="d-inline d-md-none">{{ auth()->user()->short_name }}</span>
```

**Resultado:**
- **Desktop:** Nome completo
- **Mobile:** Nome limitado

---

**Data:** 2026-03-10
**Laravel:** 11.x
**Recomendação:** Use `Str::limit()` para casos simples ou Accessor para reutilização
