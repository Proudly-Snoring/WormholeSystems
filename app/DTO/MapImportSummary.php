<?php

declare(strict_types=1);

namespace App\DTO;

final class MapImportSummary
{
    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $sections = [];

    public function created(string $section, int $count = 1): void
    {
        $this->increment($section, 'created', $count);
    }

    public function updated(string $section, int $count = 1): void
    {
        $this->increment($section, 'updated', $count);
    }

    public function skipped(string $section, int $count = 1): void
    {
        $this->increment($section, 'skipped', $count);
    }

    public function describe(): string
    {
        if ($this->sections === []) {
            return 'Nothing to import.';
        }

        $parts = [];

        foreach ($this->sections as $section => $counts) {
            $detail = collect($counts)
                ->filter(fn (int $count): bool => $count > 0)
                ->map(fn (int $count, string $kind): string => sprintf('%d %s', $count, $kind))
                ->implode(', ');

            if ($detail !== '') {
                $parts[] = sprintf('%s: %s', str_replace('_', ' ', $section), $detail);
            }
        }

        return $parts === [] ? 'Nothing to import.' : implode('. ', $parts).'.';
    }

    private function increment(string $section, string $kind, int $count): void
    {
        $this->sections[$section] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->sections[$section][$kind] += $count;
    }
}
