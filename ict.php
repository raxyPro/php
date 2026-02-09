<?php
declare(strict_types=1);

$appKey = 'y6W095C7=29181$pJ480750n6b1334z'; 
$secretKey = '820E21)x636e422~9261L30e0~0s1kMB'; 
$sessionToken = '54658959';

$sslVerify = false; // dev only

if (($_GET['action'] ?? '') === 'positions') {
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
        CURLOPT_POSTFIELDS => $payload, // docs show GET with body
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

    header('Content-Type: application/json; charset=utf-8');
    http_response_code((int)($info['http_code'] ?? 200));

    if ($err) {
        echo json_encode(['ok'=>false,'where'=>'curl','message'=>$err,'info'=>$info], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo $resp;
    exit;
}
?>
