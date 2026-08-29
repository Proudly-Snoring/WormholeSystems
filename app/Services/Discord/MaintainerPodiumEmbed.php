<?php

declare(strict_types=1);

namespace App\Services\Discord;

use App\DTO\MaintainerCharacterStat;
use App\DTO\MaintainerEntry;
use App\Models\MapAlert;
use App\Services\Statistics\MaintainerPeriod;
use Illuminate\Support\Collection;

/**
 * Builds the Discord embed for the monthly maintainer podium: the top scanners/maintainers
 * on a map, ranked by the map's configured point weights. Entries are already grouped,
 * threshold-filtered and positioned by MaintainerLeaderboard::aggregate() -- this class
 * only formats them.
 */
final readonly class MaintainerPodiumEmbed
{
    private const int MAX_ENTRIES = 10;

    private const array MEDALS = [1 => '🥇', 2 => '🥈', 3 => '🥉'];

    /**
     * @param  Collection<int, MaintainerEntry>  $entries
     * @return array<string, mixed>
     */
    public function build(MapAlert $alert, MaintainerPeriod $period, Collection $entries): array
    {
        $shown = $entries->take(self::MAX_ENTRIES);

        return [
            'title' => sprintf('Maintainer Podium — %s', $period->label()),
            'url' => route('maps.settings.statistics.show', $alert->map),
            'description' => $shown->isEmpty()
                ? 'Nobody qualified for the leaderboard this month.'
                : $shown->map(fn (MaintainerEntry $entry): string => $this->line($entry))->implode("\n"),
            'fields' => $entries->count() > self::MAX_ENTRIES ? [
                [
                    'name' => 'Full list',
                    'value' => sprintf('[See the full leaderboard](%s)', route('maps.settings.statistics.show', $alert->map)),
                ],
            ] : [],
        ];
    }

    private function line(MaintainerEntry $entry): string
    {
        $rank = self::MEDALS[$entry->position] ?? sprintf('#%d', $entry->position);

        return sprintf('%s **%s** — %d pts (%s)', $rank, $entry->display_name, $entry->points, $this->breakdown($entry));
    }

    private function breakdown(MaintainerEntry $entry): string
    {
        $added = array_sum(array_map(fn (MaintainerCharacterStat $stat): int => $stat->nb_added, $entry->characters));
        $edited = array_sum(array_map(fn (MaintainerCharacterStat $stat): int => $stat->nb_edited, $entry->characters));
        $deleted = array_sum(array_map(fn (MaintainerCharacterStat $stat): int => $stat->nb_deleted, $entry->characters));

        return sprintf('+%d / ~%d / -%d', $added, $edited, $deleted);
    }
}
