<?php
/**
 * api_health_proxy.php
 * Прокси для JS health-check: браузер не может напрямую обратиться к 127.0.0.1:5000,
 * поэтому JS вызывает этот файл, а он уже спрашивает API.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$ch = curl_init('http://127.0.0.1:5000/health');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 2,
    CURLOPT_CONNECTTIMEOUT => 1,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode(['ok' => ($code === 200)]);
