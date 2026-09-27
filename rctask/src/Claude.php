<?php
declare(strict_types=1);

/**
 * Turns free text into structured tasks with the Claude API.
 * The API key stays in config.php on the server.
 */
final class Claude
{
    public static function configured(): bool
    {
        global $CONFIG;
        return trim((string)($CONFIG['anthropic_api_key'] ?? '')) !== '';
    }

    /** @return array<int, array<string, mixed>> raw task objects from Claude */
    public static function parseTasks(string $text, array $bandwidths): array
    {
        global $CONFIG;
        $prompt = self::prompt(mb_substr($text, 0, 4000), $bandwidths);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $CONFIG['anthropic_api_key'],
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => $CONFIG['anthropic_model'] ?: 'claude-haiku-4-5',
                'max_tokens' => 2000,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);

        if ($body === false) throw new RuntimeException('Could not reach Claude: ' . $err);
        if ($status !== 200) {
            error_log("Claude API error $status: $body");
            throw new RuntimeException("Claude API returned $status");
        }
        $data = json_decode($body, true);
        $out = '';
        foreach ($data['content'] ?? [] as $c) $out .= $c['text'] ?? '';

        $start = strpos($out, '[');
        $end = strrpos($out, ']');
        if ($start === false || $end === false || $end <= $start) return [];
        $arr = json_decode(substr($out, $start, $end - $start + 1), true);
        return is_array($arr) ? $arr : [];
    }

    private static function prompt(string $text, array $bandwidths): string
    {
        $lines = array_map(
            fn($b) => sprintf('- %s: "%s" — %s (%s h/week)', $b['id'], $b['name'], $b['when'], $b['hoursPerWeek']),
            $bandwidths
        );
        $list = implode("\n", $lines);
        $today = date('Y-m-d');
        $weekday = date('l');

        return <<<PROMPT
You turn a person's free-text note into tasks for their personal task manager.
Today is {$weekday}, {$today} (India). Dates written like 10-Sep-26 or 10/09/2026 are day-month-year.

The person plans by "bandwidth": the slot of their week when a task can realistically be done. Their bandwidths:
{$list}

Rules:
- One task per distinct action. Split lists joined by commas, "and", or new lines.
- title: short, starts with a verb, sentence case, no date words.
- bandwidth: the id whose slot fits WHERE and WHEN the task can be done. If the note names a slot ("tonight", "on Saturday", "at office"), follow it. Job work goes to the office slot; quick phone-only actions to the on-the-go slot if one exists; long jobs or anything needing shops, travel or daylight to the weekend slot.
- due: "YYYY-MM-DD" if a deadline or day is stated or clearly implied ("by Tuesday", "before 10-Oct-26", "tomorrow", "this Saturday"), else null.
- priority: "High" if due within 3 days or marked urgent/important/asap; "Low" if "sometime", "someday" or optional; else "Medium".
- effort_min: realistic minutes, 5–480.
- category: one of Work, Home, Family, Health, Finance, Vehicle, Shopping, Personal, Admin.
- person: a named person the task involves, else null.
- why: at most 12 words on why this bandwidth fits.

Reply with only a JSON array like:
[{"title":"Get bike pollution check done","bandwidth":"weekend","due":"2026-10-10","priority":"Medium","effort_min":45,"category":"Vehicle","person":null,"why":"Needs a trip to the PUC centre"}]

Note:
"""
{$text}
"""
PROMPT;
    }
}
