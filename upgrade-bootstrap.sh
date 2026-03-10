#!/bin/bash

echo "🔄 Upgrade Bootstrap 5.0 → 5.3"
echo "================================"

# Limpar instalação corrompida
echo "🧹 Limpando node_modules corrompido..."
find node_modules -type l -delete 2>/dev/null
rm -rf node_modules 2>/dev/null
rm -f package-lock.json 2>/dev/null

# Limpar cache do npm
echo "🧹 Limpando cache do npm..."
npm cache clean --force

# Fazer backup do package.json original
if [ ! -f package.json.backup ]; then
    echo "💾 Fazendo backup do package.json original..."
    cp package.json package.json.backup
fi

# Instalar dependências
echo "📦 Instalando dependências atualizadas..."
npm install

# Verificar se instalou corretamente
if [ $? -eq 0 ]; then
    echo ""
    echo "✅ SUCESSO! Bootstrap atualizado para 5.3.8"
    echo ""
    echo "📋 Resumo das atualizações:"
    echo "   • Bootstrap: 5.0.2 → 5.3.8"
    echo "   • jQuery: 3.3.1 → 3.7.1"
    echo "   • DataTables: 1.13 → 2.3 (agora com Bootstrap 5 support)"
    echo "   • DataTables-BS4 → DataTables-BS5"
    echo "   • Perfect-scrollbar: 0.7.1 → 1.5.5"
    echo "   • SweetAlert2: 11.0.0 → 11.15.3"
    echo ""
    echo "⚠️  ATENÇÃO: Mudanças necessárias no código:"
    echo "   1. Trocar 'datatables.net-bs4' por 'datatables.net-bs5' nos imports"
    echo "   2. Trocar 'datatables.net-responsive-bs4' por 'datatables.net-responsive-bs5'"
    echo ""
    echo "🧪 Para testar, execute: npm run dev"
else
    echo ""
    echo "❌ ERRO na instalação!"
    echo "📝 Verifique os logs acima para detalhes"
    exit 1
fi
