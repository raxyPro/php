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

    /**
     * @return array{missions: array, tasks: array, ideas: array} raw objects from Claude
     */
    public static function parse(string $text, array $bandwidths, array $missions): array
    {
        global $CONFIG;
        $prompt = self::prompt(mb_substr($text, 0, 4000), $bandwidths, $missions);

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
                'max_tokens' => 3000,
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

        $empty = ['missions' => [], 'tasks' => [], 'ideas' => []];
        $start = strpos($out, '{');
        $end = strrpos($out, '}');
        if ($start === false || $end === false || $end <= $start) return $empty;
        $obj = json_decode(substr($out, $start, $end - $start + 1), true);
        if (!is_array($obj)) return $empty;
        return [
            'missions' => is_array($obj['missions'] ?? null) ? $obj['missions'] : [],
            'tasks'    => is_array($obj['tasks'] ?? null) ? $obj['tasks'] : [],
            'ideas'    => is_array($obj['ideas'] ?? null) ? $obj['ideas'] : [],
        ];
    }

    private static function prompt(string $text, array $bandwidths, array $missions): string
    {
        $lines = array_map(
            fn($b) => sprintf('- %s: "%s" — %s (%s h/week)', $b['id'], $b['name'], $b['when'], $b['hoursPerWeek']),
            $bandwidths
        );
        $list = implode("\n", $lines);
        $active = array_filter($missions, fn($m) => $m['status'] === 'Active');
        $mlist = $active
            ? implode("\n", array_map(fn($m) => sprintf('- %s: "%s"', $m['id'], $m['title']), $active))
            : '(none yet)';
        $today = date('Y-m-d');
        $weekday = date('l');

        return <<<PROMPT
You turn a person's free-text note into missions, tasks and ideas for their personal execution app.
Today is {$weekday}, {$today} (India). Dates like 10-Sep-26 or 10/09/2026 are day-month-year.

DEFINITIONS
- mission: a short-term goal or small project with several steps, e.g. "File ITR", "Buy EV". Only create one when the note clearly names a goal that has (or will need) several tasks, e.g. "File ITR: collect Form 16, call CA" or "mission Buy EV".
- task: one concrete action.
- idea: a thought, quote, insight or thing to remember that is not an action, e.g. "difference between gold and silver - no one remembers who came second". Lines starting with "idea:" are always ideas.

EXISTING ACTIVE MISSIONS (link tasks to these by id when they clearly belong):
{$mlist}

BANDWIDTHS (the slot of the week when a task can realistically be done):
{$list}

RULES FOR TASKS
- One task per distinct action. Split lists joined by commas, "and", or new lines.
- title: short, starts with a verb, sentence case, no date/time words.
- mission: an existing mission id, OR the exact title of a new mission you return in "missions", OR null.
- bandwidth: the id whose slot fits WHERE and WHEN the task can be done. If the note names a slot ("tonight", "on Saturday", "at office"), follow it.
- due: "YYYY-MM-DD" if a deadline or day is stated or clearly implied ("by Tuesday", "tomorrow", "this Saturday"), else null.
- time: "HH:MM" (24h) if a time is stated ("at 5pm", "10:30"), else null.
- priority: "High" if due within 3 days or urgent/important/asap; "Low" if someday/optional; else "Medium".
- effort_min: realistic minutes, 5–480.
- category: one of Work, Home, Family, Health, Finance, Vehicle, Shopping, Personal, Admin, Learning.
- person: a named person the task involves, else null.
- why: at most 12 words on why this bandwidth fits.

Reply with only one JSON object:
{"missions":[{"title":"File ITR","goal":"File FY25-26 return before 31-Jul","target":"2026-07-31"}],
 "tasks":[{"title":"Collect Form 16 from HR","mission":"File ITR","bandwidth":"work","due":null,"time":null,"priority":"Medium","effort_min":15,"category":"Finance","person":null,"why":"HR is reachable during office hours"}],
 "ideas":[{"title":"Gold vs silver: no one remembers who came second","note":"Being first matters more than being close."}]}
Use empty arrays when there is nothing of a type.

Note:
"""
{$text}
"""
PROMPT;
    }
}
