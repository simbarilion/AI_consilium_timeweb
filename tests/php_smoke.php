<?php
/**
 * Smoke-check PHP helpers without network calls.
 */
require_once dirname(__DIR__) . '/api/lib.php';

$failed = 0;
function check($ok, $label)
{
    global $failed;
    if ($ok) {
        echo "ok  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
}

check(consilium_secret_ok('abc') === true, 'secret ok accepts real token');
check(consilium_secret_ok('PUT_PRODUCT_TOKEN') === false, 'secret ok rejects PUT_ placeholder');
check(consilium_secret_ok('YOUR_TOKEN') === false, 'secret ok rejects YOUR_ prefix');
check(consilium_secret_ok('') === false, 'secret ok rejects empty');

$ready = array(
    'agents' => array(
        'product' => array('base_url' => 'https://agent.timeweb.cloud/x/v1', 'token' => 'tok-a'),
        'project' => array('base_url' => 'https://agent.timeweb.cloud/y/v1', 'token' => 'tok-b'),
        'backend' => array('base_url' => 'https://agent.timeweb.cloud/z/v1', 'token' => 'tok-c'),
        'frontend' => array('base_url' => 'https://agent.timeweb.cloud/w/v1', 'token' => 'tok-d'),
    ),
);
check(consilium_agents_ready($ready) === true, 'agents ready with four secrets');
$ready['agents']['frontend']['token'] = 'PUT_X';
check(consilium_agents_ready($ready) === false, 'agents not ready with placeholder');

$json = consilium_clean_json("```json\n{\"title\":\"ok\"}\n```");
check(isset($json['title']) && $json['title'] === 'ok', 'clean json strips fences');

$topics = consilium_topics();
check(count($topics) === 3, 'three round topics');

$chair = consilium_chair($ready);
check($chair['token'] === 'tok-a', 'chair falls back to product');
$ready['chair'] = array(
    'base_url' => 'https://agent.timeweb.cloud/chair/v1',
    'token' => 'tok-chair',
    'role' => 'chair role',
);
check(consilium_chair($ready)['token'] === 'tok-chair', 'chair uses dedicated agent when ready');
check(consilium_chair_role($ready) === 'chair role', 'chair role from config');

$ctx = consilium_build_context('идея', array(
    array(
        'round' => 1,
        'messages' => array(array('name' => 'P', 'text' => 'hello')),
    ),
));
check(strpos($ctx, 'ИДЕЯ ПОЛЬЗОВАТЕЛЯ') !== false && strpos($ctx, 'hello') !== false, 'build context');

$reasonChunk = array(
    'choices' => array(
        array(
            'delta' => array(
                'reasoning_content' => 'думаю ',
                'content' => null,
            ),
        ),
    ),
);
check(consilium_chunk_text($reasonChunk) === 'думаю ', 'chunk text reads reasoning_content');

$sseFull = '';
$sseReason = '';
consilium_apply_sse_line(
    'data: {"choices":[{"delta":{"reasoning_content":"план"}}]}',
    $sseFull,
    $sseReason,
    null
);
check($sseFull === '' && $sseReason === 'план', 'sse keeps reasoning aside from content');

$rawJson = '{"choices":[{"message":{"role":"assistant","content":null,"reasoning_content":"итог"}}]}';
check(consilium_text_from_raw($rawJson) === 'итог', 'raw json falls back to reasoning_content');

if ($failed > 0) {
    echo "\n$failed failed\n";
    exit(1);
}
echo "\nall passed\n";
