#!/usr/bin/env bash
set -e

echo "=========================================================="
echo "🚀 Digital Wallet Core API - Setup Automatizado"
echo "=========================================================="

# 1. Copiar .env se não existir
if [ ! -f .env ]; then
    echo "📄 Arquivo .env não encontrado. Copiando de .env.example..."
    cp .env.example .env
fi

# 2. Executar via Docker se disponível
if command -v docker &> /dev/null && docker compose version &> /dev/null; then
    echo "🐳 Docker detectado! Construindo e inicializando containers..."
    docker compose up -d --build

    echo "⏳ Aguardando serviços ficarem saudáveis..."
    sleep 5

    echo "📦 Instalando dependências via Composer..."
    docker compose exec -T app composer install --prefer-dist --no-interaction

    echo "🔑 Gerando Application Key..."
    docker compose exec -T app php artisan key:generate

    echo "🗄️ Executando migrações do banco de dados..."
    docker compose exec -T app php artisan migrate --force

    echo "🧪 Rodando testes automatizados..."
    docker compose exec -T app php artisan test

    echo ""
    echo "=========================================================="
    echo "✅ Setup concluído com sucesso!"
    echo "🌐 API disponível em: http://localhost:8000"
    echo "=========================================================="
else
    echo "💻 Docker não detectado. Executando comandos no host local..."
    composer install --prefer-dist --no-interaction
    php artisan key:generate
    php artisan migrate
    php artisan test

    echo ""
    echo "=========================================================="
    echo "✅ Setup local concluído com sucesso!"
    echo "=========================================================="
fi
