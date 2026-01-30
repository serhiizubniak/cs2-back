# Railway Deployment Guide

## Налаштування проекту на Railway

### 1. Підключення GitHub репозиторію

1. Зайдіть на https://railway.app/project/2f6b7ca9-3a97-45f1-ae02-116420970011
2. Натисніть "New" → "GitHub Repo"
3. Виберіть репозиторій: `serhiizubniak/cs2-back`
4. Railway автоматично виявить `railway.json` і налаштує проект

### 2. Налаштування змінних середовища

Railway автоматично налаштує:
- `PORT` - порт для запуску сервера (автоматично)

### 3. Перевірка конфігурації

Файл `railway.json` вже налаштований:
```json
{
  "build": {
    "builder": "NIXPACKS"
  },
  "deploy": {
    "startCommand": "php -S 0.0.0.0:$PORT router.php",
    "restartPolicyType": "ON_FAILURE",
    "restartPolicyMaxRetries": 10
  }
}
```

### 4. Деплой

1. Railway автоматично задеплоїть проект після підключення репозиторію
2. Після деплою ви отримаєте URL типу: `https://your-project.railway.app`
3. API буде доступне за адресою: `https://your-project.railway.app/api/`

### 5. Налаштування для фронтенду

Після отримання URL бекенду:

1. Зайдіть в налаштування вашого фронтенд проекту на Vercel
2. Додайте Environment Variable:
   - `NEXT_PUBLIC_API_URL` = `https://your-project.railway.app`
3. Передеплойте фронтенд

### 6. Перевірка роботи

Перевірте, що API працює:
```bash
curl https://your-project.railway.app/api/?action=get-statistics
```

### 7. Логи та моніторинг

- Логи доступні в Railway Dashboard
- Моніторинг автоматично включений
- Автоматичний рестарт при помилках (до 10 спроб)

### 8. Створення папки data

Railway автоматично створить папку `data/` при першому запуску. 
Файли `matches.json`, `matches_data.json`, `teams.json` будуть створені автоматично.

### Troubleshooting

**Проблема: 500 Internal Server Error**
- Перевірте логи в Railway Dashboard
- Переконайтеся, що PHP 8.2+ встановлено (NIXPACKS автоматично)

**Проблема: CORS помилки**
- CORS вже налаштовано в `api/index.php`
- Перевірте, що фронтенд використовує правильний `NEXT_PUBLIC_API_URL`

**Проблема: Файли не зберігаються**
- Переконайтеся, що папка `data/` має права на запис
- Railway автоматично налаштує права при деплої
