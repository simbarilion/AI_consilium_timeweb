<?php
header('Content-Type: text/html; charset=utf-8');

$htmlPath = __DIR__ . '/public/index.html';
if (!is_file($htmlPath)) {
    http_response_code(500);
    echo 'Не найден public/index.html';
    exit;
}

$html = file_get_contents($htmlPath);

$script = str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php');
$dir = str_replace('\\', '/', dirname($script));
if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
    $base = './';
} else {
    $base = rtrim($dir, '/') . '/';
}

$baseTag = '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">';
$html = preg_replace('/<head>/i', "<head>\n  " . $baseTag, $html, 1);

echo $html;
