import json
import os
import re
import time
from typing import Any, Generator

from dotenv import load_dotenv
from flask import Flask, Response, jsonify, request, send_from_directory, stream_with_context
from flask_cors import CORS
from openai import OpenAI

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PUBLIC_DIR = os.path.join(BASE_DIR, "public")
load_dotenv(os.path.join(BASE_DIR, ".env"))

app = Flask(__name__, static_folder=PUBLIC_DIR, static_url_path="")
CORS(app)

LLM_BASE_URL = os.getenv("LLM_BASE_URL", "https://YOUR_LLM_BASE_URL/v1")
LLM_TOKEN = os.getenv("LLM_TOKEN", "YOUR_LLM_TOKEN_HERE")
LLM_MODEL = os.getenv("LLM_MODEL", "YOUR_MODEL_NAME")
MAX_IDEA_LENGTH = int(os.getenv("MAX_IDEA_LENGTH", "6000"))

AGENTS = [
    ("product", "Продакт-менеджер", """Ты опытный Product Manager. Анализируй бизнес-ценность идеи, целевую аудиторию, проблему, JTBD, MVP, метрики и продуктовые гипотезы. Будь конкретным."""),
    ("project", "Проджект-менеджер", """Ты опытный Project Manager. Оцени реализацию, декомпозицию, зависимости, риски, порядок работ, критерии готовности и реалистичный roadmap. Опирайся на предыдущие мнения."""),
    ("backend", "Backend-разработчик", """Ты senior Python backend developer. Предложи практичную архитектуру, API, модель данных, безопасность, интеграции, фоновые задачи, тестирование и стек. Не усложняй MVP без причины."""),
    ("frontend", "Frontend-разработчик и дизайнер", """Ты senior frontend developer и product designer. Продумай UX, пользовательский путь, responsive UI, состояния интерфейса, визуальный язык и frontend-архитектуру. Учитывай предыдущие решения команды."""),
]

ROUND_TOPICS = [
    "Раунд 1: Независимый первичный анализ идеи",
    "Раунд 2: Критика и уточнение предыдущих выводов: обсуждение противоречий и поиск решений.",
    "Раунд 3: Финальная позиция: обсуждение рисков и конкретных рекомендаций",
]

def extract_text_delta(event: Any) -> str:
    """Достаёт текстовый токен из Responses API или Chat Completions stream."""
    if event is None:
        return ""

    if isinstance(event, dict):
        event_type = event.get("type") or ""
        delta = event.get("delta") or ""
        choices = event.get("choices")
    else:
        event_type = getattr(event, "type", None) or ""
        delta = getattr(event, "delta", None) or ""
        choices = getattr(event, "choices", None)

    if event_type == "response.output_text.delta" and isinstance(delta, str):
        return delta

    if choices:
        choice0 = choices[0]
        part = getattr(choice0, "delta", None)
        if part is None and isinstance(choice0, dict):
            part = choice0.get("delta")
        content = getattr(part, "content", None) if part is not None and not isinstance(part, dict) else None
        if content is None and isinstance(part, dict):
            content = part.get("content")
        if isinstance(content, str):
            return content
    return ""


def _env(name: str) -> str:
    return (os.getenv(name) or "").strip()


def _valid_secret(value: str) -> bool:
    return bool(value) and not value.startswith("YOUR_") and "PUT_" not in value


def agent_config(agent: str) -> dict[str, str]:
    key = agent
    if agent == "chair" and not (
        _valid_secret(_env("CHAIR_TOKEN")) and _valid_secret(_env("CHAIR_BASE_URL"))
    ):
        key = "product"
    prefix = key.upper()
    url = _env(f"{prefix}_BASE_URL")
    token = _env(f"{prefix}_TOKEN")
    model = _env(f"{prefix}_MODEL") or _env("LLM_MODEL") or "gpt-4"
    if _valid_secret(url) and _valid_secret(token) and "YOUR_LLM_BASE_URL" not in url:
        return {"base_url": url.rstrip("/"), "api_key": token, "model": model, "key": key}
    return {
        "base_url": LLM_BASE_URL.rstrip("/"),
        "api_key": LLM_TOKEN,
        "model": LLM_MODEL,
        "key": key,
    }


def timeweb_agents_ready() -> bool:
    return all(
        _valid_secret(_env(f"{key.upper()}_BASE_URL")) and _valid_secret(_env(f"{key.upper()}_TOKEN"))
        for key, _n, _r in AGENTS
    )


def configured() -> bool:
    if timeweb_agents_ready():
        return True
    return (
        _valid_secret(LLM_TOKEN)
        and bool(LLM_BASE_URL)
        and "YOUR_LLM_BASE_URL" not in LLM_BASE_URL
        and _valid_secret(LLM_MODEL)
    )


def _uses_chat(base_url: str) -> bool:
    return "cloud-ai/agents" in base_url or "agent.timeweb.cloud" in base_url


def _llm_client(agent: str = "product") -> tuple[OpenAI, dict[str, str]]:
    cfg = agent_config(agent)
    client = OpenAI(
        api_key=cfg["api_key"],
        base_url=cfg["base_url"],
        timeout=120.0,
        max_retries=2,
    )
    return client, cfg


def stream_llm(system: str, user: str, agent: str = "product") -> Generator[str, None, None]:
    if not configured():
        raise RuntimeError(
            "Агенты не настроены. Заполните PRODUCT/PROJECT/BACKEND/FRONTEND "
            "(BASE_URL и TOKEN) или LLM_BASE_URL/LLM_TOKEN/LLM_MODEL в .env."
        )

    client, cfg = _llm_client(agent)
    if _uses_chat(cfg["base_url"]):
        stream = client.chat.completions.create(
            model=cfg["model"],
            messages=[
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
            temperature=0.7,
            stream=True,
        )
    else:
        stream = client.responses.create(
            model=cfg["model"],
            instructions=system,
            input=user,
            temperature=0.7,
            stream=True,
        )
    for event in stream:
        delta = extract_text_delta(event)
        if delta:
            yield delta


def call_llm(system: str, user: str, agent: str = "chair") -> str:
    if not configured():
        raise RuntimeError(
            "Агенты не настроены. Заполните PRODUCT/PROJECT/BACKEND/FRONTEND "
            "(BASE_URL и TOKEN) или LLM_BASE_URL/LLM_TOKEN/LLM_MODEL в .env."
        )

    client, cfg = _llm_client(agent)
    if _uses_chat(cfg["base_url"]):
        response = client.chat.completions.create(
            model=cfg["model"],
            messages=[
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
            temperature=0.7,
        )
        return (response.choices[0].message.content or "").strip()

    response = client.responses.create(
        model=cfg["model"],
        instructions=system,
        input=user,
        temperature=0.7,
    )
    return response.output_text.strip()

def clean_json(text: str) -> dict[str, Any]:
    text = text.strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
    return json.loads(text)

def build_context(idea: str, rounds: list[dict[str, Any]]) -> str:
    parts = [f"ИДЕЯ ПОЛЬЗОВАТЕЛЯ:\n{idea}"]
    for r in rounds:
        parts.append(f"\nРАУНД {r['round']}:")
        for m in r["messages"]:
            parts.append(f"{m['name']}: {m['text']}")
    return "\n".join(parts)

def sse(event: str, payload: dict[str, Any]) -> str:
    return f"event: {event}\ndata: {json.dumps(payload, ensure_ascii=False)}\n\n"

@app.get("/api/health")
def health():
    return jsonify({
        "status": "ok",
        "llm_configured": configured(),
        "mode": "timeweb-agents" if timeweb_agents_ready() else "gateway",
    })

@app.post("/api/consilium")
def consilium():
    data = request.get_json(silent=True) or {}
    idea = str(data.get("idea", "")).strip()

    if not idea:
        return jsonify({"error": "Поле idea обязательно."}), 400
    if len(idea) > MAX_IDEA_LENGTH:
        return jsonify({"error": f"Идея слишком длинная. Максимум {MAX_IDEA_LENGTH} символов."}), 400

    def generate() -> Generator[str, None, None]:
        rounds: list[dict[str, Any]] = []

        try:
            yield sse("start", {
                "message": "Консилиум запущен",
                "total_rounds": len(ROUND_TOPICS),
                "agents": len(AGENTS),
            })

            for round_no, topic in enumerate(ROUND_TOPICS, start=1):
                yield sse("round_start", {
                    "round": round_no,
                    "total_rounds": len(ROUND_TOPICS),
                    "topic": topic,
                })

                messages = []

                for agent_index, (key, name, role) in enumerate(AGENTS, start=1):
                    yield sse("agent_start", {
                        "round": round_no,
                        "agent": key,
                        "name": name,
                        "agent_index": agent_index,
                        "total_agents": len(AGENTS),
                    })

                    context = build_context(idea, rounds)
                    prompt = f"""Ты участвуешь в AI-консилиуме из четырёх экспертов.
{topic}

{context}

Твоя задача — дать профессиональное мнение именно со своей роли ({name}).
Следующий эксперт увидит твой ответ, поэтому:
- не повторяй очевидное;
- ссылайся на уже найденные решения;
- отмечай несогласие, если оно есть;
- предлагай конкретные решения;
- ответ 120–250 слов, на русском языке.
"""

                    started = time.monotonic()
                    chunks: list[str] = []
                    for delta in stream_llm(role, prompt, key):
                        chunks.append(delta)
                        yield sse("agent_token", {
                            "round": round_no,
                            "agent": key,
                            "name": name,
                            "delta": delta,
                        })
                    text = "".join(chunks).strip()
                    elapsed = round(time.monotonic() - started, 1)

                    message = {"agent": key, "name": name, "text": text}
                    messages.append(message)

                    yield sse("agent_done", {
                        "round": round_no,
                        **message,
                        "elapsed": elapsed,
                    })

                round_data = {"round": round_no, "messages": messages}
                rounds.append(round_data)

                yield sse("round_done", {
                    "round": round_no,
                    "messages": messages,
                })

            yield sse("synthesis_start", {
                "message": "Председатель консилиума готовит итоговое решение…"
            })

            synthesis_prompt = f"""Ты — председатель AI-консилиума.
Нужно синтезировать итоговое решение по идее пользователя на основании трёх раундов и мнений четырёх экспертов.

{build_context(idea, rounds)}

Верни ТОЛЬКО валидный JSON без markdown в формате:
{{
  "title": "короткое название решения",
  "summary": "5-8 предложений с главным решением",
  "mvp": ["5-8 конкретных функций"],
  "stack": "стек и архитектура с объяснением",
  "roadmap": ["5-7 этапов в порядке реализации"],
  "risks": ["4-6 основных рисков"],
  "design": "описание UX/UI и визуального направления",
  "next_steps": ["5 конкретных следующих действий"]
}}
"""

            final = clean_json(call_llm(
                "Ты стратегический руководитель продукта и технический архитектор. "
                "Синтезируй мнения команды в практичное решение.",
                synthesis_prompt,
                "chair",
            ))

            yield sse("synthesis_done", {"final": final})
            yield sse("done", {"message": "Консилиум завершён."})

        except json.JSONDecodeError:
            yield sse("error", {
                "message": "LLM вернула некорректный JSON на этапе финального синтеза."
            })
        except GeneratorExit:
            # Клиент закрыл соединение.
            return
        except Exception as exc:
            yield sse("error", {"message": str(exc)})

    return Response(
        stream_with_context(generate()),
        mimetype="text/event-stream",
        headers={
            "Cache-Control": "no-cache, no-transform",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )

@app.get("/")
def index():
    return send_from_directory(PUBLIC_DIR, "index.html")

@app.get("/<path:path>")
def static_files(path: str):
    return send_from_directory(PUBLIC_DIR, path)

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.getenv("PORT", "8000")), debug=False, threaded=True)
