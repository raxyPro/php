<?php
declare(strict_types=1);

// Keep a single source of truth for credentials to avoid subtle mismatches.
$appKey = 'y6W095C7=29181$pJ480750n6b1334z';
$secretKey = '51+94N3MY669@)!r)6we89a^K8064=90';
$sessionToken = '54742116'; // Positions API session token
$apiSession = $sessionToken;

$sslVerify = false; // dev only

function output_json_response(mixed $data, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    if (is_string($data)) {
        echo $data;
        return;
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

$action = $_GET['action'] ?? '';

if ($action === 'positions') {
    $url = 'https://api.icicidirect.com/breezeapi/api/v1/portfoliopositions';
    $timeStamp = gmdate("Y-m-d\TH:i:s.000\Z");
    $payload = '{}';
    $checksum = hash('sha256', $timeStamp . $payload . $secretKey);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        "X-Checksum: token {$checksum}",
        "X-Timestamp: {$timeStamp}",
        "X-AppKey: {$appKey}",
        "X-SessionToken: {$sessionToken}",
        'User-Agent: Mozilla/5.0',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if (!$sslVerify) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = ($resp === false) ? curl_error($ch) : null;
    curl_close($ch);

    if ($err) {
        output_json_response(['ok' => false, 'where' => 'curl', 'message' => $err, 'info' => $info], 502);
        exit;
    }

    output_json_response($resp, (int)($info['http_code'] ?? 200));
    exit;
}

if ($action === 'customer_details') {
    $url = 'https://api.icicidirect.com/breezeapi/api/v1/customerdetails';
    $payload = json_encode([
        'SessionToken' => $apiSession,
        'AppKey' => $appKey,
    ], JSON_UNESCAPED_SLASHES);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if (!$sslVerify) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = ($resp === false) ? curl_error($ch) : null;
    curl_close($ch);

    if ($err) {
        output_json_response(['ok' => false, 'where' => 'curl', 'message' => $err, 'info' => $info], 502);
        exit;
    }

    output_json_response($resp, (int)($info['http_code'] ?? 200));
    exit;
}

output_json_response([
    'ok' => false,
    'message' => 'Unknown action',
    'supported' => ['positions', 'customer_details'],
], 400);

