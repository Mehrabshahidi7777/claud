<?php

namespace App\Models;

use Database\Factories\SponsorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Sponsor extends Model
{
    /** @use HasFactory<SponsorFactory> */
    use HasFactory;

    /**
     * The disk logos live on. Private storage served through a route, so the
     * server needs no storage:link and nothing uploaded is ever executed.
     */
    public const LOGO_DISK = 'local';

    private const CACHE_KEY = 'sponsors:active';

    protected $fillable = ['name', 'description', 'website_url', 'logo_path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'clicks' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Every page with a sponsor strip reads the cached list; any change
        // in the admin panel has to show up on the next page load.
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * @param  Builder<Sponsor>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The sponsors to show, cached for ten minutes: the list is on the login
     * page and the dashboard, and changes perhaps once a month.
     *
     * @return Collection<int, Sponsor>
     */
    public static function showcase(): Collection
    {
        // Plain rows go into the cache, not models: the cache refuses to
        // unserialize objects, and would hand back broken ones.
        $rows = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            fn () => self::active()->get()->map->getAttributes()->all(),
        );

        return self::hydrate($rows);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? route('sponsors.logo', ['sponsor' => $this, 'v' => $this->updated_at?->timestamp])
            : null;
    }

    /**
     * Where a click goes: through our counter, then to their site.
     */
    public function visitUrl(): ?string
    {
        return $this->website_url ? route('sponsors.visit', $this) : null;
    }
}
