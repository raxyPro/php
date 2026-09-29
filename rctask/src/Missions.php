<?php
declare(strict_types=1);

/**
 * Missions: short-term goals / small projects ("File ITR", "Buy EV")
 * that group several tasks.
 */
final class Missions
{
    public const STATUSES = ['Active', 'Completed', 'Cancelled'];

    public static function list(int $uid): array
    {
        $st = db()->prepare("SELECT * FROM missions WHERE user_id = ? ORDER BY status = 'Active' DESC, (target_date IS NULL), target_date, sort_order, created_at");
        $st->execute([$uid]);
        return array_map([self::class, 'toApi'], $st->fetchAll());
    }

    private static function toApi(array $r): array
    {
        return [
            'id'        => $r['id'],
            'title'     => $r['title'],
            'goal'      => $r['goal'] ?? '',
            'target'    => $r['target_date'] ?? '',
            'status'    => $r['status'],
            'hue'       => $r['hue'],
            'createdAt' => $r['created_at'],
            'doneAt'    => $r['completed_at'] ? substr($r['completed_at'], 0, 10) : null,
        ];
    }

    public static function save(int $uid, array $m): array
    {
        $title = trim((string)($m['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('Mission name is required.');

        $id = (string)($m['id'] ?? '');
        if (!Tasks::isUuid($id)) $id = Tasks::uuid();
        $status = in_array($m['status'] ?? '', self::STATUSES, true) ? $m['status'] : 'Active';

        $row = [
            'title'       => mb_substr($title, 0, 200),
            'goal'        => mb_substr(trim((string)($m['goal'] ?? '')), 0, 1000) ?: null,
            'target_date' => Tasks::date($m['target'] ?? null),
            'status'      => $status,
            'hue'         => in_array($m['hue'] ?? '', Bandwidths::HUES, true) ? $m['hue'] : 'blue',
        ];

        $pdo = db();
        $ex = $pdo->prepare('SELECT user_id, status, completed_at FROM missions WHERE id = ?');
        $ex->execute([$id]);
        $old = $ex->fetch();
        if ($old && (int)$old['user_id'] !== $uid) throw new InvalidArgumentException('Not found.');
        $row['completed_at'] = $status === 'Completed'
            ? (($old && $old['status'] === 'Completed' && $old['completed_at']) ? $old['completed_at'] : date('Y-m-d H:i:s'))
            : null;

        if ($old) {
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($row)));
            $st = $pdo->prepare("UPDATE missions SET $sets WHERE id = :id AND user_id = :uid");
        } else {
            $cols = array_keys($row);
            $st = $pdo->prepare(sprintf(
                'INSERT INTO missions (id, user_id, %s) VALUES (:id, :uid, %s)',
                implode(', ', $cols),
                implode(', ', array_map(fn($k) => ":$k", $cols))
            ));
        }
        $st->execute($row + ['id' => $id, 'uid' => $uid]);

        $st = $pdo->prepare('SELECT * FROM missions WHERE id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        return self::toApi($st->fetch());
    }

    /** Deletes the mission; its tasks stay (mission_id becomes NULL). */
    public static function delete(int $uid, string $id): void
    {
        $pdo = db();
        $pdo->prepare('UPDATE entries SET mission_id = NULL WHERE mission_id = ? AND user_id = ?')->execute([$id, $uid]);
        $pdo->prepare('DELETE FROM missions WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    }
}
