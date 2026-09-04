<?php

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
$config = consilium_config();
echo json_encode([
    'status' => 'ok',
    'llm_configured' => consilium_agents_ready($config),
    'mode' => 'timeweb-agents',
], JSON_UNESCAPED_UNICODE);
