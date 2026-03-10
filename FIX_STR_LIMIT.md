# 🔧 Corrigir Limitação de Caracteres

## ❌ Problema

```blade
{{ Str::words(auth()->user()->name, 10) }}
```

**Problema:** `Str::words()` limita por **PALAVRAS**, não caracteres!

Se o nome tem **menos de 10 palavras**, ele mostra TUDO.

---

## ✅ Soluções Corretas

### 1️⃣ **Usar `Str::limit()` para limitar CARACTERES**

```blade
{{-- Limita a 20 CARACTERES --}}
{{ Str::limit(auth()->user()->name, 20) }}
```

**Exemplos:**
- Nome: "João Pedro Silva Santos"
- `Str::limit($name, 20)` → "João Pedro Silva S..."
- `Str::limit($name, 10)` → "João Pedro..."

---

### 2️⃣ **Combinar palavras + caracteres**

```blade
{{-- Máximo 2 palavras OU 30 caracteres --}}
{{ Str::limit(Str::words(auth()->user()->name, 2, ''), 30) }}
```

---

### 3️⃣ **Helper personalizado**

Crie em `app/Helpers/TextHelper.php`:

```php
<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class TextHelper
{
    /**
     * Limita nome por palavras E caracteres
     */
    public static function limitName($name, $maxWords = 2, $maxChars = 30)
    {
        // Primeiro limita por palavras
        $limited = Str::words($name, $maxWords, '');

        // Depois limita por caracteres se ainda estiver grande
        if (strlen($limited) > $maxChars) {
            $limited = Str::limit($limited, $maxChars);
        }

        return trim($limited);
    }
}
```

**Registrar helper em `composer.json`:**

```json
{
    "autoload": {
        "files": [
            "app/Helpers/TextHelper.php"
        ]
    }
}
```

**Depois rode:**
```bash
composer dump-autoload
```

**Usar no Blade:**
```blade
{{ App\Helpers\TextHelper::limitName(auth()->user()->name) }}
```

---

## 📊 Diferença entre words() e limit()

### `Str::words()`

Limita por **PALAVRAS**:

```php
$name = "João Pedro Silva Santos";

Str::words($name, 1)  // "João..."
Str::words($name, 2)  // "João Pedro..."
Str::words($name, 10) // "João Pedro Silva Santos" (tem só 4 palavras!)
```

### `Str::limit()`

Limita por **CARACTERES**:

```php
$name = "João Pedro Silva Santos";

Str::limit($name, 10)  // "João Pedro..."
Str::limit($name, 20)  // "João Pedro Silva S..."
Str::limit($name, 50)  // "João Pedro Silva Santos" (menor que 50)
```

---

## 🎯 Soluções Práticas

### Para Navbar/Header (Máximo 20 caracteres):

```blade
<span class="navbar-text">
    {{ Str::limit(auth()->user()->name, 20) }}
</span>
```

### Para DataTables (Máximo 30 caracteres):

```php
Column::make('name')
    ->render(function($user) {
        return Str::limit($user->name, 30);
    })
```

### Para Cards (Máximo 2 palavras):

```blade
<h5 class="card-title">
    {{ Str::words(auth()->user()->name, 2) }}
</h5>
```

### Responsivo (Diferentes tamanhos):

```blade
{{-- Desktop: 40 caracteres --}}
<span class="d-none d-md-inline">
    {{ Str::limit(auth()->user()->name, 40) }}
</span>

{{-- Mobile: 15 caracteres --}}
<span class="d-inline d-md-none">
    {{ Str::limit(auth()->user()->name, 15) }}
</span>
```

---

## 💡 Accessor Recomendado

No `app/Models/User.php`:

```php
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /**
     * Nome curto (20 caracteres)
     */
    public function getShortNameAttribute(): string
    {
        return Str::limit($this->name, 20);
    }

    /**
     * Primeiras 2 palavras
     */
    public function getTwoWordsNameAttribute(): string
    {
        return Str::words($this->name, 2, '');
    }

    /**
     * Nome inteligente: 2 palavras OU 25 caracteres
     */
    public function getSmartNameAttribute(): string
    {
        $twoWords = Str::words($this->name, 2, '');

        return strlen($twoWords) > 25
            ? Str::limit($twoWords, 25)
            : $twoWords;
    }
}
```

**Usar:**
```blade
{{ auth()->user()->short_name }}
{{ auth()->user()->two_words_name }}
{{ auth()->user()->smart_name }}
```

---

## 🔍 Debug

Para ver o que está acontecendo:

```blade
@php
    $name = auth()->user()->name;
    $totalWords = str_word_count($name);
    $totalChars = strlen($name);
@endphp

<div class="alert alert-info">
    <strong>Nome:</strong> {{ $name }}<br>
    <strong>Total palavras:</strong> {{ $totalWords }}<br>
    <strong>Total caracteres:</strong> {{ $totalChars }}<br>
    <strong>Str::words(10):</strong> {{ Str::words($name, 10) }}<br>
    <strong>Str::limit(20):</strong> {{ Str::limit($name, 20) }}
</div>
```

---

## ✅ Solução Final Recomendada

**Para limitar CARACTERES (não palavras):**

```blade
{{-- ERRADO (limita palavras) ❌ --}}
{{ Str::words(auth()->user()->name, 10) }}

{{-- CORRETO (limita caracteres) ✅ --}}
{{ Str::limit(auth()->user()->name, 20) }}
```

**Ou use Accessor no Model:**

```php
// User.php
public function getDisplayNameAttribute(): string
{
    return Str::limit($this->name, 25);
}
```

```blade
{{-- Blade --}}
{{ auth()->user()->display_name }}
```

---

**Data:** 2026-03-10
**Problema:** `Str::words()` limita por palavras, não caracteres
**Solução:** Use `Str::limit()` para limitar caracteres
