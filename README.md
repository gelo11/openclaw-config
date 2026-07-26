# OpenClaw Config Editor

Веб-интерфейс для управления конфигурацией [OpenClaw](https://openclaw.ai) gateway. Single-page приложение на ванильном JS + PHP backend.

## Возможности

- **Провайдеры** — управление AI-провайдерами (OpenAI, DeepSeek, OpenRouter, Together, NVIDIA и др.)
- **Модели** — добавление/редактирование моделей: ID, название, context window, max tokens, reasoning, thinking format, supported efforts
- **📦 Модели** — сводная вкладка всех моделей; подсветка непривязанных к агентам, быстрая привязка кнопкой ➕
- **🔗 Привязка** — настройка primary/fallback моделей для каждого агента и defaults
- **⚙️ Общие** — глобальные параметры: thinking default, reasoning default, pruning, subagents
- **Автосохранение** — все изменения пишутся в `openclaw.json`, с бэкапами перед каждой записью

## Структура

```
/var/www/html/openclaw-config/
├── index.html        # Весь UI (~1500 строк)
├── api.php           # PHP API: загрузка/сохранение конфига
├── openclaw.json     # Конфигурация OpenClaw gateway
├── .env              # Ключи API (в .gitignore)
├── .gitignore
└── backups/          # Авто-бэкапы перед каждым сохранением
```

## Установка

```bash
# Клонировать в web-директорию
git clone https://github.com/gelo11/openclaw-config.git /var/www/html/openclaw-config
chown -R www-data:www-data /var/www/html/openclaw-config
chmod 664 /var/www/html/openclaw-config/openclaw.json
```

Требуется Apache/Nginx с PHP, serving `/var/www/html/`.

## API (api.php)

| Метод | Эндпоинт | Описание |
|--------|----------|----------|
| `GET` | `api.php?action=load` | Загрузить конфигурацию из `openclaw.json` |
| `POST` | `api.php?action=save` | Сохранить конфигурацию (тело — JSON) |

При `save` — автоматически создаётся бэкап в `backups/openclaw-YYYYMMDD-HHMMSS.json`, затем конфиг записывается с блокировкой файла (`flock`).

## Синхронизация с живым конфигом (sync-openclaw-config.sh)

Редактор работает с копией конфига в `/var/www/html/openclaw-config/openclaw.json`. Для синхронизации с реальным конфигом OpenClaw (`/root/.openclaw/openclaw.json`) используется скрипт-посредник.

### Скрипт

`/root/.openclaw/sync-openclaw-config.sh`:

```bash
#!/bin/bash
P=/var/www/html/openclaw-config

# 1. Load: live → project (по флагу .request_load)
if [ -f "$P/.request_load" ]; then
    cp /root/.openclaw/openclaw.json "$P/openclaw.json"
    chown www-data:www-data "$P/openclaw.json"
    chmod 664 "$P/openclaw.json"
    rm "$P/.request_load"
    echo "$(date -Iseconds) load: live → project"
fi

# 2. Save: project → live (по флагу .request_save)
if [ -f "$P/.request_save" ]; then
    cp /root/.openclaw/openclaw.json "$P/backups/openclaw-$(date +%Y%m%d-%H%M%S).json"
    cp "$P/openclaw.json" /root/.openclaw/openclaw.json
    rm "$P/.request_save"
    echo "$(date -Iseconds) save: project → live"
fi
```

**Механика:**
- Редактор никогда не трогает живой конфиг напрямую
- При сохранении в UI → создаётся файл-флаг `.request_save`
- Скрипт по крону раз в 30 секунд проверяет флаг → если есть, копирует конфиг в `/root/.openclaw/` с предварительным бэкапом
- Аналогично `.request_load` — для загрузки свежего конфига из live в редактор

### Cron

```cron
* * * * * /root/.openclaw/sync-openclaw-config.sh >> /var/log/openclaw-sync.log 2>&1
* * * * * sleep 30; /root/.openclaw/sync-openclaw-config.sh >> /var/log/openclaw-sync.log 2>&1
```

Два задания с интервалом 30 секунд (на минуте и на 30-й секунде). Логи пишутся в `/var/log/openclaw-sync.log`.

## Безопасность

- `.env` и `backups/` в `.gitignore` — не коммитятся
- API ключи хранятся в `.env`, подгружаются PHP-бэкендом
- `flock` при чтении/записи — защита от гонки

## Лицензия

MIT
