<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = ['date', 'title', 'jalali_year'];

    /**
     * Stored as a bare date and read back as one.
     *
     * A plain `date` cast writes `2026-03-21 00:00:00`, which a later lookup
     * for `2026-03-21` then fails to match — so `holidays:seed` run twice
     * would insert duplicates and hit the unique index instead of being the
     * no-op it is meant to be.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => CarbonImmutable::parse($value)->startOfDay(),
            set: fn ($value) => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    /**
     * The working-hours calculation asks this on every scheduled message, so
     * the answer is cached for the day rather than fetched each time.
     *
     * @return array<string, true>
     */
    public static function lookup(): array
    {
        return Cache::remember('holidays.lookup', now()->addDay(), function () {
            return self::query()
                ->pluck('date')
                ->mapWithKeys(fn ($date) => [$date->toDateString() => true])
                ->all();
        });
    }

    public static function forgetLookup(): void
    {
        Cache::forget('holidays.lookup');
    }
}
