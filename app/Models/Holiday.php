<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = ['date', 'title', 'jalali_year'];

    protected function casts(): array
    {
        return ['date' => 'date'];
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
