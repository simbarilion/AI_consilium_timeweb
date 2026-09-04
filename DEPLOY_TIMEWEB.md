# AI Consilium — SSE deployment on Timeweb

## 1. Timeweb Cloud App Platform

Recommended command:

```bash
pip install -r backend/requirements.txt
```

Start command:

```bash
gunicorn --bind 0.0.0.0:$PORT --worker-class gevent --workers 1 backend.app:app
```

For SSE, do not use a synchronous single-worker setup. The included gevent worker
allows the server to keep streaming while the worker handles the request.

Set environment variables:

```text
LLM_BASE_URL=https://YOUR_LLM_BASE_URL/v1
LLM_TOKEN=YOUR_LLM_TOKEN_HERE
LLM_MODEL=YOUR_MODEL_NAME
```

## 2. Reverse proxy / Nginx

The backend sends:

```text
Content-Type: text/event-stream
Cache-Control: no-cache, no-transform
X-Accel-Buffering: no
```

`X-Accel-Buffering: no` tells Nginx not to buffer the SSE response. If you have
your own Nginx config, also disable proxy buffering for `/api/consilium`.

Example:

```nginx
location /api/consilium {
    proxy_pass http://127.0.0.1:8000;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 600s;
}
```

## 3. Frontend protocol

The browser uses:

```text
POST /api/consilium
Accept: text/event-stream
Content-Type: application/json
```

The frontend reads `response.body` incrementally and parses SSE blocks.

Native `EventSource` is intentionally not used for the POST request: EventSource
is designed around opening an event stream URL, while the idea must be sent to the
server in the request body.

## 4. Event sequence

```text
start
  ↓
round_start
  ↓
agent_start
  ↓
agent_token   (repeats while the model generates text)
  ↓
agent_done
  ↓
agent_start
  ↓
agent_token
  ↓
agent_done
  ↓
... × 4 agents
  ↓
round_done
  ↓
... × 5 rounds
  ↓
synthesis_start
  ↓
synthesis_done
  ↓
done
```

The UI shows each expert's text as tokens arrive. `agent_done` then
finalizes that card with the full assembled answer.


## 5. Local run

```bash
python -m venv .venv
# Windows:
.venv\Scripts\activate
# Linux/macOS:
source .venv/bin/activate

pip install -r backend/requirements.txt
copy .env.example .env   # Windows
# cp .env.example .env   # Linux/macOS

python -m backend.app
```

Open `http://localhost:8000`.

For a production-like local run:

```bash
gunicorn --bind 127.0.0.1:8000 --worker-class gevent --workers 1 backend.app:app
```

## 6. Important

Token streaming depends on the LLM provider supporting OpenAI Responses API
with `stream=True`. The backend emits `agent_token` for each text delta and
`agent_done` with the assembled answer.

Nginx must not buffer `/api/consilium`, otherwise tokens arrive in one burst
instead of appearing live.
