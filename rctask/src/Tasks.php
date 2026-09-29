<?php
declare(strict_types=1);

/**
 * Tasks and ideas (both live in the `entries` table).
 *   task: title, date, time, status, progress, remark, mission, bandwidth...
 *   idea: title + note (notes_html); no date or status workflow.
 */
final class Tasks
{
    public const STATUSES = ['New', 'Progress', 'Completed', 'Cancelled'];
    private const PRIORITIES = ['High', 'Medium', 'Low'];
    private const KINDS = ['task', 'idea'];

    /** All tasks and ideas of the user. */
    public static function list(int $uid): array
    {
        $st = db()->prepare("SELECT * FROM entries WHERE user_id = ? AND kind IN ('task','idea') ORDER BY created_at DESC");
        $st->execute([$uid]);
        return array_map([self::class, 'toApi'], $st->fetchAll());
    }

    private static function toApi(array $r): array
    {
        return [
            'id'        => $r['id'],
            'kind'      => $r['kind'],
            'title'     => $r['title'],
            'mission'   => $r['mission_id'] ?? '',
            'bandwidth' => $r['bandwidth_id'] ?? '',
            'due'       => $r['due_date'] ?? '',
            'time'      => $r['due_time'] ? substr($r['due_time'], 0, 5) : '',
            'priority'  => $r['priority'],
            'effort'    => (int)($r['effort_min'] ?? 30),
            'category'  => $r['category'] ?? '',
            'person'    => $r['person'] ?? '',
            'status'    => $r['status'],
            'progress'  => $r['progress_html'] ?? '',
            'remark'    => $r['remark_html'] ?? '',
            'notes'     => $r['notes_html'] ?? '',
            'why'       => $r['why'] ?? '',
            'source'    => $r['source_text'] ?? '',
            'createdAt' => $r['created_at'],
            'updatedAt' => $r['updated_at'],
            'doneAt'    => $r['completed_at'] ? substr($r['completed_at'], 0, 10) : null,
        ];
    }

    public static function date(?string $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
    }

    private static function time(?string $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{2}):(\d{2})$/', $v, $m)) return null;
        return ((int)$m[1] < 24 && (int)$m[2] < 60) ? "$v:00" : null;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function isUuid(string $id): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /** Insert or update one task/idea owned by $uid. Returns the saved row. */
    public static function save(int $uid, array $t): array
    {
        $title = trim((string)($t['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('Title is required.');

        $id = (string)($t['id'] ?? '');
        if (!self::isUuid($id)) $id = self::uuid();

        $kind = in_array($t['kind'] ?? 'task', self::KINDS, true) ? $t['kind'] : 'task';
        $status = in_array($t['status'] ?? '', self::STATUSES, true) ? $t['status'] : 'New';

        // mission must belong to this user
        $mission = (string)($t['mission'] ?? '');
        if ($mission !== '') {
            $chk = db()->prepare('SELECT 1 FROM missions WHERE id = ? AND user_id = ?');
            $chk->execute([$mission, $uid]);
            if (!$chk->fetchColumn()) $mission = '';
        }

        $row = [
            'kind'          => $kind,
            'title'         => mb_substr($title, 0, 300),
            'mission_id'    => $mission ?: null,
            'bandwidth_id'  => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($t['bandwidth'] ?? '')), 0, 40) ?: null,
            'status'        => $status,
            'priority'      => in_array($t['priority'] ?? '', self::PRIORITIES, true) ? $t['priority'] : 'Medium',
            'due_date'      => self::date($t['due'] ?? null),
            'due_time'      => self::time($t['time'] ?? null),
            'effort_min'    => max(5, min(10000, (int)($t['effort'] ?? 30))),
            'category'      => mb_substr(trim((string)($t['category'] ?? '')), 0, 40) ?: null,
            'person'        => mb_substr(trim((string)($t['person'] ?? '')), 0, 80) ?: null,
            'progress_html' => Sanitizer::html((string)($t['progress'] ?? '')) ?: null,
            'remark_html'   => Sanitizer::html((string)($t['remark'] ?? '')) ?: null,
            'notes_html'    => Sanitizer::html((string)($t['notes'] ?? '')) ?: null,
            'why'           => mb_substr(trim((string)($t['why'] ?? '')), 0, 200) ?: null,
            'source_text'   => mb_substr((string)($t['source'] ?? ''), 0, 4000) ?: null,
        ];
        if ($kind === 'idea') {
            $row['due_date'] = $row['due_time'] = $row['bandwidth_id'] = null;
        }

        $pdo = db();
        $existing = $pdo->prepare('SELECT status, completed_at, user_id FROM entries WHERE id = ?');
        $existing->execute([$id]);
        $old = $existing->fetch();
        if ($old && (int)$old['user_id'] !== $uid) throw new InvalidArgumentException('Not found.');

        // completed_at follows the status
        if ($status === 'Completed') {
            $row['completed_at'] = ($old && $old['status'] === 'Completed' && $old['completed_at']) ? $old['completed_at'] : date('Y-m-d H:i:s');
        } else {
            $row['completed_at'] = null;
        }

        if ($old) {
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($row)));
            $st = $pdo->prepare("UPDATE entries SET $sets WHERE id = :id AND user_id = :uid");
        } else {
            $cols = array_keys($row);
            $st = $pdo->prepare(sprintf(
                'INSERT INTO entries (id, user_id, %s) VALUES (:id, :uid, %s)',
                implode(', ', $cols),
                implode(', ', array_map(fn($k) => ":$k", $cols))
            ));
        }
        $st->execute($row + ['id' => $id, 'uid' => $uid]);

        $st = $pdo->prepare('SELECT * FROM entries WHERE id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        return self::toApi($st->fetch());
    }

    public static function delete(int $uid, string $id): void
    {
        db()->prepare('DELETE FROM entries WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    }
}
