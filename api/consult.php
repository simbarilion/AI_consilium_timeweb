<?php

require_once __DIR__ . '/lib.php';

set_time_limit(600);
ignore_user_abort(true);

function consilium_json_error($code, $message)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    consilium_json_error(405, 'Нужен метод POST.');
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$idea = isset($body['idea']) ? trim((string) $body['idea']) : '';
$config = consilium_config();
$maxLen = ($config && isset($config['max_idea_length'])) ? (int) $config['max_idea_length'] : 6000;
$ideaLen = function_exists('mb_strlen') ? mb_strlen($idea, 'UTF-8') : strlen($idea);

if ($idea === '') {
    consilium_json_error(400, 'Поле idea обязательно.');
}
if ($ideaLen > $maxLen) {
    consilium_json_error(400, 'Идея слишком длинная. Максимум ' . $maxLen . ' символов.');
}
if (!consilium_agents_ready($config)) {
    consilium_json_error(500, 'Агенты не настроены. Заполните config/config.php на сервере.');
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$agents = $config['agents'];
$topics = (!empty($config['rounds']) && is_array($config['rounds'])) ? $config['rounds'] : consilium_topics();
$order = array('product', 'project', 'backend', 'frontend');

try {
    consilium_sse('start', [
        'message' => 'Консилиум запущен',
        'total_rounds' => count($topics),
        'agents' => count($order),
    ]);

    $rounds = [];
    foreach ($topics as $index => $topic) {
        $roundNo = $index + 1;
        consilium_sse('round_start', [
            'round' => $roundNo,
            'total_rounds' => count($topics),
            'topic' => $topic,
        ]);

        $messages = [];
        $agentIndex = 0;
        foreach ($order as $key) {
            $agentIndex++;
            $agent = $agents[$key];
            consilium_sse('agent_start', [
                'round' => $roundNo,
                'agent' => $key,
                'name' => $agent['name'],
                'agent_index' => $agentIndex,
                'total_agents' => count($order),
            ]);

            $context = consilium_build_context($idea, $rounds);
            $prompt = "Ты участвуешь в AI-консилиуме из четырёх экспертов.\n"
                . $topic . "\n\n"
                . $context . "\n\n"
                . "Твоя задача — дать профессиональное мнение именно со своей роли ({$agent['name']}).\n"
                . "Следующий эксперт увидит твой ответ, поэтому:\n"
                . "- не повторяй очевидное;\n"
                . "- ссылайся на уже найденные решения;\n"
                . "- отмечай несогласие, если оно есть;\n"
                . "- предлагай конкретные решения;\n"
                . "- ответ 120–250 слов, на русском языке.\n";

            $started = microtime(true);
            $text = consilium_chat($agent, $agent['role'], $prompt, true, function ($delta) use ($roundNo, $key, $agent) {
                consilium_sse('agent_token', [
                    'round' => $roundNo,
                    'agent' => $key,
                    'name' => $agent['name'],
                    'delta' => $delta,
                ]);
            });
            $elapsed = round(microtime(true) - $started, 1);
            $message = ['agent' => $key, 'name' => $agent['name'], 'text' => $text];
            $messages[] = $message;
            consilium_sse('agent_done', array_merge($message, [
                'round' => $roundNo,
                'elapsed' => $elapsed,
            ]));
        }

        $roundData = ['round' => $roundNo, 'messages' => $messages];
        $rounds[] = $roundData;
        consilium_sse('round_done', $roundData);
    }

    consilium_sse('synthesis_start', [
        'message' => 'Председатель консилиума готовит итоговое решение…',
    ]);

    $synthesisPrompt = "Ты — председатель AI-консилиума.\n"
        . "Нужно синтезировать итоговое решение по идее пользователя на основании трёх раундов и мнений четырёх экспертов.\n\n"
        . consilium_build_context($idea, $rounds) . "\n\n"
        . "Верни ТОЛЬКО валидный JSON без markdown в формате:\n"
        . "{\n"
        . "  \"title\": \"короткое название решения\",\n"
        . "  \"summary\": \"5-8 предложений с главным решением\",\n"
        . "  \"mvp\": [\"5-8 конкретных функций\"],\n"
        . "  \"stack\": \"стек и архитектура с объяснением\",\n"
        . "  \"roadmap\": [\"5-7 этапов в порядке реализации\"],\n"
        . "  \"risks\": [\"4-6 основных рисков\"],\n"
        . "  \"design\": \"описание UX/UI и визуального направления\",\n"
        . "  \"next_steps\": [\"5 конкретных следующих действий\"]\n"
        . "}\n";

    $chair = consilium_chair($config);
    $finalRaw = consilium_chat($chair, consilium_chair_role($config), $synthesisPrompt, false);
    $final = consilium_clean_json($finalRaw);
    consilium_sse('synthesis_done', ['final' => $final]);
    consilium_sse('done', ['message' => 'Консилиум завершён.']);
} catch (Exception $e) {
    consilium_sse('error', ['message' => $e->getMessage()]);
}
