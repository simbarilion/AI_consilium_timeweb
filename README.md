# AI Consilium — Full Stack + SSE

Готовый Timeweb-oriented сервис: Flask backend + HTML/CSS/JS frontend.

## SSE

`POST /api/consilium` возвращает `text/event-stream`.

Frontend читает HTTP response stream и отображает:
- запуск консилиума;
- начало каждого раунда;
- карточку текущего агента;
- токены ответа по мере генерации;
- завершение ответа агента;
- завершение раунда;
- финальный synthesis;
- завершение консилиума.

В консилиуме 20 экспертных LLM-вызовов (4 агента × 5 раундов) + 1 synthesis-вызов.

## Почему не EventSource

Native `EventSource` хорошо подходит для SSE-подписки, но здесь пользователю
нужно отправить `idea` в POST body. Поэтому используется `fetch()` + чтение
`ReadableStream` и тот же стандартный формат SSE.

## Настройка

```bash
copy .env.example .env
```

Заполнить:

```text
LLM_BASE_URL=https://YOUR_LLM_BASE_URL/v1
LLM_TOKEN=YOUR_LLM_TOKEN_HERE
LLM_MODEL=YOUR_MODEL_NAME
```

Токен хранится только на backend.

## Запуск

```bash
pip install -r backend/requirements.txt
python -m backend.app
```

Production:

```bash
gunicorn --bind 0.0.0.0:$PORT --worker-class gevent --workers 1 backend.app:app
```

Подробный Timeweb deployment и настройки Nginx для SSE находятся в
`DEPLOY_TIMEWEB.md`.

## Streaming

События консилиума приходят по SSE. Текст каждого специалиста стримится
токенами: backend читает OpenAI Responses API с `stream=True` и шлёт
`agent_token` до тех пор, пока модель генерирует ответ. После этого
приходит `agent_done` с полным текстом.

Финальный синтез по-прежнему приходит целиком (`synthesis_done`), потому что
это структурированный JSON для карточек решения.
