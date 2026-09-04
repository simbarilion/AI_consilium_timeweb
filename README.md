# AI Consilium

Четыре IT-эксперта разбирают идею в 3 раундах и собирают план. Интерфейс тот же, бэкенд для бесплатного хостинга Timeweb — **PHP**.

Flask остаётся только для локальной разработки. На **timeweb.com → Хостинг для сайта** Python/Flask не ставится.

## Бесплатная публикация (PHP)

1. В [Timeweb Cloud](https://timeweb.cloud) создайте **четыре агента** (продакт, проджект, backend, дизайн).
2. У каждого агента скопируйте:
   - **Интеграции → Скопировать OpenAI URL**
   - **Управление → Доступ по API** (токен агента, не «API и Terraform»)
3. Скопируйте `config/config.example.php` в `config/config.php` и вставьте `base_url` + `token` четырёх агентов. Председатель можно не заполнять — синтез пойдёт через продакт-агента.
4. Залейте в `public_html` файлы:
   - `index.php`, `.htaccess`
   - папки `api/`, `config/`, `public/`
5. Откройте сайт и проверьте `https://ваш-домен/api/health` — должно быть `"llm_configured": true`.

Токены только в `config/config.php` на сервере. Этот файл в git не попадает.

## Локально (Flask)

```bash
copy .env.example .env
pip install -r backend/requirements.txt
python -m backend.app
```

## Протокол

`POST /api/consilium` — SSE. События: `start` → `round_start` → `agent_start` → `agent_token`* → `agent_done` → `round_done` → `synthesis_start` → `synthesis_done` → `done`.

12 вызовов экспертов (4×3) + 1 синтез.
