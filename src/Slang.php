<?php
namespace Funseco\Freezone;

/** Whole-word slang matcher and the per-login hit records behind the hourly report. Silent: nobody is told or punished. */
class Slang
{
    /** @var string[] */
    private array $words = [];
    private ?string $pattern = null;
    /** @var array<string, array{language: string, first: int, last: int, words: array<string,int>, total: int}> */
    private array $records = [];
    /** @var array<string, true> */
    private array $watched = [];

    /** @param array<int,mixed> $words */
    public function setWords(array $words): void
    {
        $this->words = array_values(array_unique(array_filter(
            array_map(fn ($w) => mb_strtolower(trim((string) $w)), $words),
            fn ($w) => $w !== ''
        )));
        // a word at the start/end of the line or between whitespace
        $this->pattern = $this->words
            ? '/(?<!\S)('.implode('|', array_map(fn ($w) => preg_quote($w, '/'), $this->words)).')(?!\S)/iu'
            : null;
    }

    public function wordCount(): int
    {
        return count($this->words);
    }

    public function check(string $login, string $language, string $text, int $now): void
    {
        if ($this->pattern === null || !preg_match_all($this->pattern, $text, $m)) {
            return;
        }
        $threshold = (int) config('freezone.slang_threshold');
        $window = (int) config('freezone.slang_window');

        foreach ($m[1] as $hit) {
            $word = mb_strtolower($hit);
            if (!isset($this->records[$login])) {
                // the first hit only opens the record (no threshold check yet)
                $this->records[$login] = ['language' => $language, 'first' => $now, 'last' => $now, 'words' => [$word => 1], 'total' => 1];
                continue;
            }
            $r = &$this->records[$login];
            $r['words'][$word] = ($r['words'][$word] ?? 0) + 1;
            $r['total']++;
            $r['last'] = $now;
            if ($r['total'] >= $threshold && $r['last'] - $r['first'] <= $window) {
                $this->watched[$login] = true;
            }
            unset($r);
        }
    }

    /** @return array<int, array{language: string, matches: array<int, array{0:string,1:int}>}> */
    public function report(): array
    {
        $out = [];
        foreach (array_keys($this->watched) as $login) {
            $r = $this->records[$login] ?? null;
            if ($r) {
                $out[] = ['language' => $r['language'], 'matches' => array_map(fn ($w, $c) => [$w, $c], array_keys($r['words']), $r['words'])];
            }
        }

        return $out;
    }

    public function clearWatched(): void
    {
        $this->watched = [];
    }

    /** Drop records whose first hit is older than the window. */
    public function prune(int $now): void
    {
        $window = (int) config('freezone.slang_window');
        foreach ($this->records as $login => $r) {
            if ($now - $r['first'] > $window) {
                unset($this->records[$login], $this->watched[$login]);
            }
        }
    }
}
