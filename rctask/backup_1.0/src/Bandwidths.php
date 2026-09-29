<?php
declare(strict_types=1);

final class Bandwidths
{
    public const HUES = ['blue', 'amber', 'violet', 'teal', 'rose', 'olive', 'slate', 'orange'];

    public static function defaults(): array
    {
        return [
            ['id' => 'work', 'name' => 'Workday', 'when' => 'Mon–Fri, 9 am–6 pm at the office. Job and client work, meetings, documents.', 'hoursPerWeek' => 10, 'hue' => 'blue', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '18:00'],
            ['id' => 'morning', 'name' => 'Early morning', 'when' => 'Mon–Fri, 6–9 am before work. Exercise, reading, personal routines.', 'hoursPerWeek' => 3, 'hue' => 'amber', 'days' => [1, 2, 3, 4, 5], 'start' => '06:00', 'end' => '09:00'],
            ['id' => 'evening', 'name' => 'Weekday evening', 'when' => 'Mon–Fri, 6–10:30 pm at home. Short home chores, family, planning, online admin.', 'hoursPerWeek' => 7.5, 'hue' => 'violet', 'days' => [1, 2, 3, 4, 5], 'start' => '18:00', 'end' => '22:30'],
            ['id' => 'weekend', 'name' => 'Weekend', 'when' => 'Sat–Sun daytime. Longer jobs, going out, shops, service centres, visits.', 'hoursPerWeek' => 8, 'hue' => 'teal', 'days' => [0, 6], 'start' => '07:00', 'end' => '22:00'],
            ['id' => 'errand', 'name' => 'On the go', 'when' => 'Any spare 10 minutes with just a phone. Calls, payments, bookings, quick replies.', 'hoursPerWeek' => 2, 'hue' => 'rose', 'days' => [], 'start' => '', 'end' => ''],
        ];
    }

    public static function list(int $uid): array
    {
        $st = db()->prepare('SELECT * FROM bandwidths WHERE user_id = ? ORDER BY sort_order, name');
        $st->execute([$uid]);
        $rows = $st->fetchAll();
        if (!$rows) {
            self::save($uid, self::defaults());
            return self::defaults();
        }
        return array_map(fn(array $r) => [
            'id'           => $r['id'],
            'name'         => $r['name'],
            'when'         => $r['description'],
            'hoursPerWeek' => (float)$r['hours_per_week'],
            'hue'          => $r['hue'],
            'days'         => $r['days'] === '' ? [] : array_map('intval', explode(',', $r['days'])),
            'start'        => $r['start_time'] ? substr($r['start_time'], 0, 5) : '',
            'end'          => $r['end_time'] ? substr($r['end_time'], 0, 5) : '',
        ], $rows);
    }

    /** Replaces the user's whole bandwidth list. */
    public static function save(int $uid, array $list): array
    {
        $clean = [];
        foreach (array_values($list) as $i => $b) {
            if (!is_array($b)) continue;
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['id'] ?? ''));
            $name = trim((string)($b['name'] ?? ''));
            if ($id === '' || $name === '') continue;
            $days = array_values(array_unique(array_filter(array_map('intval', (array)($b['days'] ?? [])), fn($d) => $d >= 0 && $d <= 6)));
            sort($days);
            $time = fn($v) => preg_match('/^\d{2}:\d{2}$/', (string)$v) ? $v . ':00' : null;
            $clean[] = [
                'id' => substr($id, 0, 40),
                'name' => mb_substr($name, 0, 80),
                'description' => mb_substr(trim((string)($b['when'] ?? '')), 0, 400),
                'hours' => max(0, min(168, (float)($b['hoursPerWeek'] ?? 0))),
                'hue' => in_array($b['hue'] ?? '', self::HUES, true) ? $b['hue'] : 'slate',
                'days' => implode(',', $days),
                'start' => $time($b['start'] ?? ''),
                'end' => $time($b['end'] ?? ''),
                'sort' => $i,
            ];
        }
        if (!$clean) throw new InvalidArgumentException('Keep at least one bandwidth.');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ids = array_column($clean, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM bandwidths WHERE user_id = ? AND id NOT IN ($in)")->execute([$uid, ...$ids]);
            $st = $pdo->prepare(
                'INSERT INTO bandwidths (user_id, id, name, description, hours_per_week, hue, days, start_time, end_time, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), hours_per_week=VALUES(hours_per_week),
                   hue=VALUES(hue), days=VALUES(days), start_time=VALUES(start_time), end_time=VALUES(end_time), sort_order=VALUES(sort_order)'
            );
            foreach ($clean as $b) {
                $st->execute([$uid, $b['id'], $b['name'], $b['description'], $b['hours'], $b['hue'], $b['days'], $b['start'], $b['end'], $b['sort']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::list($uid);
    }
}
