<?php
declare(strict_types=1);

/**
 * JSON API used by assets/app.js.
 *   GET  api.php?a=bootstrap         -> {tasks, bandwidths, ai}
 *   GET  api.php?a=tasks             -> {tasks}
 *   POST api.php?a=task_save         body: task        -> {task}
 *   POST api.php?a=task_delete       body: {id}        -> {ok}
 *   POST api.php?a=bandwidths_save   body: {list}      -> {bandwidths}
 *   POST api.php?a=parse             body: {text, bandwidths} -> {tasks} | {ok:false, reason}
 * POST calls need the X-CSRF-Token header.
 */

require __DIR__ . '/../src/bootstrap.php';

$uid = current_user_id();
if ($uid === null) json_out(['error' => 'Please sign in again.'], 401);

$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($method === 'POST') {
    if (!check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) json_out(['error' => 'Session expired. Reload the page.'], 419);
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) json_out(['error' => 'Invalid JSON body.'], 400);
}

try {
    switch ("$method $action") {
        case 'GET bootstrap':
            json_out(['tasks' => Tasks::list($uid), 'bandwidths' => Bandwidths::list($uid), 'ai' => Claude::configured()]);

        case 'GET tasks':
            json_out(['tasks' => Tasks::list($uid)]);

        case 'POST task_save':
            json_out(['task' => Tasks::save($uid, $body)]);

        case 'POST task_delete':
            Tasks::delete($uid, (string)($body['id'] ?? ''));
            json_out(['ok' => true]);

        case 'POST bandwidths_save':
            json_out(['bandwidths' => Bandwidths::save($uid, (array)($body['list'] ?? []))]);

        case 'POST parse':
            if (!Claude::configured()) json_out(['ok' => false, 'reason' => 'not_configured']);
            $text = trim((string)($body['text'] ?? ''));
            if ($text === '') json_out(['error' => 'Text is required.'], 400);
            try {
                json_out(['ok' => true, 'tasks' => Claude::parseTasks($text, Bandwidths::list($uid))]);
            } catch (RuntimeException $e) {
                error_log('parse failed: ' . $e->getMessage());
                json_out(['ok' => false, 'reason' => 'claude_error']);
            }

        default:
            json_out(['error' => 'Unknown action.'], 404);
    }
} catch (InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('api error: ' . $e);
    json_out(['error' => 'Server error. Check the PHP error log.'], 500);
}
