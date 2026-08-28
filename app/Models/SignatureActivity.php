<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignatureActivityAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single historical record of a character creating, updating or deleting a signature,
 * used to compute the maintainer leaderboard. Written via insertOrIgnore, never create().
 *
 * @property int $id
 * @property int $map_id
 * @property int $character_id
 * @property int $signature_id
 * @property int $solarsystem_id
 * @property string $dedupe_key
 * @property SignatureActivityAction $action
 * @property CarbonImmutable $activity_date
 * @property CarbonImmutable $created_at
 * @property-read Map $map
 * @property-read Character $character
 */
final class SignatureActivity extends Model
{
    public $timestamps = false;

    protected $casts = [
        'action' => SignatureActivityAction::class,
        'activity_date' => 'immutable_date',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Map,$this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * @return BelongsTo<Character,$this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
