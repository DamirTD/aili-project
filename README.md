# MVP: Классификация медицинских данных

Проект реализует простой сервис предварительной медицинской оценки:

- `Laravel 13` как API и веб-слой
- `Vue 3` как интерфейс ввода симптомов и загрузки фото
- `Groq AI` для анализа симптомов
- внешние источники (MedlinePlus/Wikipedia) для обогащения контекста

## Быстрый старт

1. Установить зависимости:

```bash
composer install
npm install
```

2. Поднять инфраструктуру:

```bash
docker compose up -d
```

3. Настроить окружение:

```bash
copy .env.example .env
php artisan key:generate
php artisan config:clear
```

4. Выполнить миграции (если используете PostgreSQL):

```bash
php artisan migrate
```

5. Запустить приложение:

```bash
php artisan serve
npm run dev
```

## Архитектура API

- `POST /api/diagnosis/analyze` - анализ текста симптомов и/или фото

## Переменные окружения

- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_DATABASE=laravel`
- `DB_USERNAME=postgres`
- `DB_PASSWORD=postgres`
- `GROQ_AI=`
- `GROQ_AI_MODEL=llama-3.2-90b-vision-preview`
- `GROQ_AI_BASE_URL=https://api.groq.com/openai/v1`
- `GROQ_AI_VERIFY_SSL=true` (или `false` для локальной отладки)

## Тесты

```bash
php artisan test
```
