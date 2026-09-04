<?php
/**
 * Общая логика консилиума для Timeweb (OpenAI-совместимый chat/completions).
 */

function consilium_config()
{
    $path = dirname(__DIR__) . '/config/config.php';
    if (!is_file($path)) {
        return null;
    }
    $config = require $path;
    return is_array($config) ? $config : null;
}

function consilium_secret_ok($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }
    return strpos($value, 'PUT_') === false && strpos($value, 'YOUR_') !== 0;
}

function consilium_agent_ready(array $agent)
{
    return consilium_secret_ok(isset($agent['base_url']) ? $agent['base_url'] : '')
        && consilium_secret_ok(isset($agent['token']) ? $agent['token'] : '');
}

function consilium_agents_ready($config)
{
    if (!$config || empty($config['agents']) || !is_array($config['agents'])) {
        return false;
    }
    foreach (array('product', 'project', 'backend', 'frontend') as $key) {
        if (empty($config['agents'][$key]) || !consilium_agent_ready($config['agents'][$key])) {
            return false;
        }
    }
    return true;
}

function consilium_configured()
{
    return consilium_agents_ready(consilium_config());
}

function consilium_chair(array $config)
{
    if (!empty($config['chair']) && consilium_agent_ready($config['chair'])) {
        return $config['chair'];
    }
    return $config['agents']['product'];
}

function consilium_chair_role(array $config)
{
    if (!empty($config['chair']['role'])) {
        return $config['chair']['role'];
    }
    return 'Ты стратегический руководитель продукта и технический архитектор. Синтезируй мнения команды в практичное решение.';
}

function consilium_topics()
{
    return array(
        'Раунд 1: Независимый первичный анализ идеи',
        'Раунд 2: Критика и уточнение предыдущих выводов: обсуждение противоречий и поиск решений.',
        'Раунд 3: Финальная позиция: обсуждение рисков и конкретных рекомендаций',
    );
}

function consilium_sse($event, $payload)
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function consilium_build_context($idea, $rounds)
{
    $parts = array("ИДЕЯ ПОЛЬЗОВАТЕЛЯ:\n" . $idea);
    foreach ($rounds as $round) {
        $parts[] = "\nРАУНД " . $round['round'] . ':';
        foreach ($round['messages'] as $message) {
            $parts[] = $message['name'] . ': ' . $message['text'];
        }
    }
    return implode("\n", $parts);
}

function consilium_clean_json($text)
{
    $text = trim($text);
    if (strpos($text, '```') === 0) {
        $text = preg_replace('/^```(?:json)?\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
    }
    $data = json_decode($text, true);
    if (!is_array($data)) {
        throw new RuntimeException('LLM вернула некорректный JSON на этапе финального синтеза.');
    }
    return $data;
}

function consilium_chat(array $agent, $system, $user, $stream, $onDelta = null)
{
    $url = rtrim($agent['base_url'], '/') . '/chat/completions';
    $payload = array(
        'model' => isset($agent['model']) && $agent['model'] !== '' ? $agent['model'] : 'gpt-4',
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.7,
        'stream' => (bool) $stream,
    );

    $buffer = '';
    $full = '';
    $rawBody = '';
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL недоступен на хостинге.');
    }

    $headers = array(
        'Authorization: Bearer ' . $agent['token'],
        'Content-Type: application/json',
        'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
    );

    $opts = array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_RETURNTRANSFER => !$stream,
    );

    if ($stream) {
        $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $data) use (&$buffer, &$full, &$rawBody, $onDelta) {
            $rawBody .= $data;
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || strpos($line, 'data:') !== 0) {
                    continue;
                }
                $json = trim(substr($line, 5));
                if ($json === '[DONE]') {
                    continue;
                }
                $chunk = json_decode($json, true);
                if (!is_array($chunk)) {
                    continue;
                }
                $delta = '';
                if (!empty($chunk['choices'][0]['delta']['content'])) {
                    $delta = $chunk['choices'][0]['delta']['content'];
                }
                if ($delta !== '') {
                    $full .= $delta;
                    if (is_callable($onDelta)) {
                        $onDelta($delta);
                    }
                }
            }
            return strlen($data);
        };
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('Ошибка запроса к агенту Timeweb: ' . $error);
    }
    if ($status >= 400) {
        $hint = is_string($raw) ? $raw : $rawBody;
        throw new RuntimeException('Агент Timeweb ответил HTTP ' . $status . ($hint ? ': ' . $hint : ''));
    }

    if ($stream) {
        $full = trim($full);
        if ($full === '') {
            throw new RuntimeException('Пустой ответ агента Timeweb.');
        }
        return $full;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['choices'][0]['message']['content'])) {
        throw new RuntimeException('Пустой ответ агента Timeweb.');
    }
    return trim($decoded['choices'][0]['message']['content']);
}
