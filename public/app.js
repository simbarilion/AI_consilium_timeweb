const $ = s => document.querySelector(s);

const agents = [
  {key:"product", name:"Продакт-менеджер", short:"P", color:"product"},
  {key:"project", name:"Проджект-менеджер", short:"J", color:"project"},
  {key:"backend", name:"Backend-разработчик", short:"B", color:"backend"},
  {key:"frontend", name:"Frontend / дизайнер", short:"F", color:"frontend"},
];

let idea = "";
let busy = false;
let finalData = null;
const liveText = new Map();

function agentByKey(key) {
  return agents.find(a => a.key === key) || agents[0];
}

function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, m => ({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
  }[m]));
}

function liveId(data) {
  return `live-${data.round}-${data.agent}`;
}

function appendMessage(data) {
  const agent = agentByKey(data.agent);
  const el = document.createElement("article");
  el.className = "message";
  el.dataset.agent = data.agent;
  el.innerHTML = `<div class="avatar ${agent.color}">${agent.short}</div>
    <div><h3>${agent.name}</h3>
    <small>Раунд ${data.round} · живой ответ LLM</small>
    <p>${escapeHtml(data.text).replace(/\n/g,"<br>")}</p></div>`;
  $("#conversation").appendChild(el);
  el.scrollIntoView({behavior:"smooth", block:"nearest"});
}

function startLiveMessage(data) {
  const id = liveId(data);
  if (document.getElementById(id)) return;
  const agent = agentByKey(data.agent);
  const el = document.createElement("article");
  el.className = "message streaming";
  el.id = id;
  el.dataset.agent = data.agent;
  el.innerHTML = `<div class="avatar ${agent.color}">${agent.short}</div>
    <div><h3>${agent.name}</h3>
    <small>Раунд ${data.round} · печатает…</small>
    <p data-live-text></p></div>`;
  $("#conversation").appendChild(el);
  liveText.set(id, "");
  el.scrollIntoView({behavior:"smooth", block:"nearest"});
}

function appendLiveToken(data) {
  startLiveMessage(data);
  const id = liveId(data);
  const next = (liveText.get(id) || "") + (data.delta || "");
  liveText.set(id, next);
  const p = document.querySelector(`#${CSS.escape(id)} [data-live-text]`);
  if (p) p.innerHTML = escapeHtml(next).replace(/\n/g, "<br>");
}

function finishLiveMessage(data) {
  const id = liveId(data);
  const el = document.getElementById(id);
  const text = data.text || liveText.get(id) || "";
  liveText.delete(id);
  if (!el) {
    appendMessage(data);
    return;
  }
  el.classList.remove("streaming");
  const small = el.querySelector("small");
  if (small) {
    const elapsed = data.elapsed != null ? ` · ${data.elapsed} с` : "";
    small.textContent = `Раунд ${data.round} · живой ответ LLM${elapsed}`;
  }
  const p = el.querySelector("[data-live-text]");
  if (p) p.innerHTML = escapeHtml(text).replace(/\n/g, "<br>");
}

function removeLoading() {
  $("#thinking")?.remove();
}

function setRound(round, total=3) {
  $("#roundNo").textContent = round;
  $("#progress").style.width = `${Math.round(round / total * 100)}%`;
}

function card(title, value) {
  return `<article class="decision-card"><h3>${title}</h3>${
    Array.isArray(value) ? listHtml(value) : `<p>${escapeHtml(value || "—")}</p>`
  }</article>`;
}

function listHtml(value) {
  if (!Array.isArray(value)) return `<p>${escapeHtml(value || "—")}</p>`;
  return `<ul>${value.map(x => `<li>${escapeHtml(String(x))}</li>`).join("")}</ul>`;
}

function showFinal(d) {
  finalData = d || {};
  $("#progress").style.width = "100%";
  $("#roundNo").textContent = "3";

  $("#decision").innerHTML = `
    <article class="decision-card wide"><span class="tag">РЕШЕНИЕ</span>
      <h3>${escapeHtml(finalData.title || "Итоговое решение консилиума")}</h3>
      <p>${escapeHtml(finalData.summary || "Консилиум завершил обсуждение.")}</p>
    </article>
    ${card("🎯 MVP", finalData.mvp)}
    ${card("⚙ Технологии и архитектура", finalData.stack)}
    ${card("🚀 Этапы реализации", finalData.roadmap)}
    ${card("⚠ Риски", finalData.risks)}
    ${card("🎨 UX / UI", finalData.design)}
    <article class="decision-card wide"><h3>Следующие шаги</h3>${listHtml(finalData.next_steps)}</article>`;

  $("#final").classList.remove("hidden");
  $("#final").scrollIntoView({behavior:"smooth", block:"start"});
}

function handleSseEvent(eventName, payload) {
  if (eventName === "start") {
    liveText.clear();
    $("#conversation").innerHTML = "";
  }
  if (eventName === "round_start") {
    setRound(payload.round, payload.total_rounds);
    const heading = document.createElement("div");
    heading.className = "round-heading";
    heading.innerHTML = `<span>РАУНД ${payload.round}</span><b>${escapeHtml(payload.topic.replace(/^Раунд \d+: /,""))}</b>`;
    $("#conversation").appendChild(heading);
  }
  if (eventName === "agent_start") {
    removeLoading();
    startLiveMessage(payload);
  }
  if (eventName === "agent_token") {
    appendLiveToken(payload);
  }
  if (eventName === "agent_done") {
    removeLoading();
    finishLiveMessage(payload);
  }
  if (eventName === "synthesis_start") {
    removeLoading();
    const el = document.createElement("article");
    el.className = "message thinking synthesis-thinking";
    el.dataset.agent = "product";
    el.innerHTML = `<div class="avatar product">C</div><div><h3>Consilium</h3><small>Собираем план</small><p><span class="typing"><i></i><i></i><i></i></span> Сводим двенадцать выступлений в одно решение…</p></div>`;
    $("#conversation").appendChild(el);
  }
  if (eventName === "synthesis_done") {
    $(".synthesis-thinking")?.remove();
    showFinal(payload.final);
  }
  if (eventName === "error") {
    removeLoading();
    throw new Error(payload.message || "Ошибка консилиума");
  }
}

function parseSseBlock(block) {
  let event = "message";
  const dataLines = [];
  for (const line of block.split("\n")) {
    if (line.startsWith("event:")) event = line.slice(6).trim();
    if (line.startsWith("data:")) dataLines.push(line.slice(5).trimStart());
  }
  if (!dataLines.length) return;
  handleSseEvent(event, JSON.parse(dataLines.join("\n")));
}

async function consumeSse(response) {
  if (!response.body) throw new Error("Браузер не поддерживает streaming response.");

  const reader = response.body.getReader();
  const decoder = new TextDecoder("utf-8");
  let buffer = "";

  while (true) {
    const {value, done} = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, {stream:true});
    const blocks = buffer.split("\n\n");
    buffer = blocks.pop() || "";
    for (const block of blocks) {
      if (block.trim()) parseSseBlock(block);
    }
  }
  buffer += decoder.decode();
  if (buffer.trim()) parseSseBlock(buffer);
}

async function start() {
  idea = $("#idea").value.trim();
  if (!idea || busy) {
    $("#idea").focus();
    return;
  }

  busy = true;
  finalData = null;
  $("#startBtn").disabled = true;
  $("#startBtn span").textContent = "Идёт обсуждение…";
  $("#consultation").classList.remove("hidden");
  $("#final").classList.add("hidden");
  liveText.clear();
  $("#conversation").innerHTML = `<article class="message" data-agent="product"><div class="avatar product">C</div><div><h3>Consilium</h3><small>Обсуждение началось</small><p>Текст каждого эксперта появится здесь по мере генерации — токен за токеном.</p></div></article>`;
  $("#consultation").scrollIntoView({behavior:"smooth", block:"start"});

  try {
    const response = await fetch("/api/consilium", {
      method: "POST",
      headers: {"Content-Type":"application/json", "Accept":"text/event-stream"},
      body: JSON.stringify({idea})
    });

    if (!response.ok) {
      let msg = `Ошибка API: HTTP ${response.status}`;
      try { msg = (await response.json()).error || msg; } catch {}
      throw new Error(msg);
    }

    await consumeSse(response);
  } catch (error) {
    removeLoading();
    const box = $("#conversation");
    box.innerHTML += `<article class="message" data-agent="backend"><div class="avatar backend">!</div><div><h3>Обсуждение прервано</h3><p>${escapeHtml(error.message)}</p><small>Проверьте config/config.php и токены агентов Timeweb.</small></div></article>`;
  } finally {
    busy = false;
    $("#startBtn").disabled = false;
    $("#startBtn span").textContent = "Начать обсуждение";
  }
}

$("#startBtn").addEventListener("click", start);
$("#pdfBtn").addEventListener("click", () => window.print());
$("#idea").addEventListener("keydown", e => {
  if ((e.ctrlKey || e.metaKey) && e.key === "Enter") start();
});
