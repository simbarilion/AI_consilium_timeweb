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

function consilium_part_text($part)
{
    if (is_string($part)) {
        return $part;
    }
    if (!is_array($part)) {
        return '';
    }
    if (isset($part['text']) && is_string($part['text'])) {
        return $part['text'];
    }
    if (isset($part['content'])) {
        return consilium_part_text($part['content']);
    }
    $out = '';
    foreach ($part as $item) {
        if (is_string($item) || is_array($item)) {
            $out .= consilium_part_text($item);
        }
    }
    return $out;
}

function consilium_first_text($source, $keys)
{
    if (!is_array($source)) {
        return '';
    }
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source) || $source[$key] === null) {
            continue;
        }
        $text = consilium_part_text($source[$key]);
        if (trim($text) !== '') {
            return $text;
        }
    }
    return '';
}

function consilium_message_text($message)
{
    return consilium_first_text($message, array('content', 'reasoning_content', 'reasoning', 'text'));
}

function consilium_chunk_text(array $chunk)
{
    if (!isset($chunk['choices'][0]) || !is_array($chunk['choices'][0])) {
        return '';
    }
    $choice = $chunk['choices'][0];
    if (isset($choice['delta']) && is_array($choice['delta'])) {
        $text = consilium_first_text($choice['delta'], array('content', 'reasoning_content', 'reasoning', 'text'));
        if ($text !== '') {
            return $text;
        }
    }
    if (isset($choice['message']) && is_array($choice['message'])) {
        $text = consilium_message_text($choice['message']);
        if ($text !== '') {
            return $text;
        }
    }
    return consilium_first_text($choice, array('text', 'content'));
}

function consilium_apply_sse_line($line, &$full, &$reasoning, $onDelta)
{
    $line = trim($line);
    if ($line === '' || strpos($line, 'data:') !== 0) {
        return;
    }
    $json = trim(substr($line, 5));
    if ($json === '' || $json === '[DONE]') {
        return;
    }
    $chunk = json_decode($json, true);
    if (!is_array($chunk)) {
        return;
    }

    $choice = (isset($chunk['choices'][0]) && is_array($chunk['choices'][0])) ? $chunk['choices'][0] : array();
    $delta = (isset($choice['delta']) && is_array($choice['delta'])) ? $choice['delta'] : array();
    $content = consilium_first_text($delta, array('content', 'text'));
    $think = consilium_first_text($delta, array('reasoning_content', 'reasoning'));
    if ($content === '' && $think === '') {
        $content = consilium_chunk_text($chunk);
    }

    if ($think !== '') {
        $reasoning .= $think;
    }
    if ($content !== '') {
        $full .= $content;
        if (is_callable($onDelta)) {
            $onDelta($content);
        }
    }
}

function consilium_text_from_raw($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return consilium_chunk_text($decoded);
    }
    $full = '';
    $reasoning = '';
    foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
        consilium_apply_sse_line($line, $full, $reasoning, null);
    }
    $full = trim($full);
    return $full !== '' ? $full : trim($reasoning);
}

function consilium_request(array $agent, $system, $user, $stream, $onDelta = null)
{
    $url = rtrim($agent['base_url'], '/') . '/chat/completions';
    $payload = array(
        'model' => isset($agent['model']) && $agent['model'] !== '' ? $agent['model'] : 'gpt-4',
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.7,
        'max_tokens' => 4096,
        'stream' => (bool) $stream,
    );

    $buffer = '';
    $full = '';
    $reasoning = '';
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
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_ENCODING => '',
        CURLOPT_RETURNTRANSFER => !$stream,
    );

    if ($stream) {
        $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $data) use (&$buffer, &$full, &$reasoning, &$rawBody, $onDelta) {
            $rawBody .= $data;
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                consilium_apply_sse_line($line, $full, $reasoning, $onDelta);
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
    if ($status >= 400 && $stream) {
        return consilium_request($agent, $system, $user, false, $onDelta);
    }
    if ($status >= 400) {
        $hint = is_string($raw) ? $raw : $rawBody;
        $hint = trim(preg_replace('/\s+/', ' ', (string) $hint));
        if (strlen($hint) > 300) {
            $hint = substr($hint, 0, 300) . '…';
        }
        throw new RuntimeException('Агент Timeweb ответил HTTP ' . $status . ($hint ? ': ' . $hint : ''));
    }

    if ($stream) {
        if ($buffer !== '') {
            consilium_apply_sse_line($buffer, $full, $reasoning, $onDelta);
        }
        $full = trim($full);
        if ($full === '') {
            $full = trim($reasoning);
            if ($full !== '' && is_callable($onDelta)) {
                $onDelta($full);
            }
        }
        if ($full === '') {
            $full = consilium_text_from_raw($rawBody);
        }
        return $full;
    }

    $text = consilium_text_from_raw(is_string($raw) ? $raw : '');
    if ($text !== '' && is_callable($onDelta)) {
        $onDelta($text);
    }
    return $text;
}

function consilium_chat(array $agent, $system, $user, $stream, $onDelta = null)
{
    $text = '';
    if ($stream) {
        $text = consilium_request($agent, $system, $user, true, $onDelta);
    }
    if (trim($text) === '') {
        $text = consilium_request($agent, $system, $user, false, null);
        $text = trim($text);
        if ($text !== '' && $stream && is_callable($onDelta)) {
            $onDelta($text);
        }
    }
    if (trim($text) === '') {
        throw new RuntimeException('Пустой ответ агента Timeweb.');
    }
    return trim($text);
}
