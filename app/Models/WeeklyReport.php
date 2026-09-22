<?php

namespace App\Models;

use Database\Factories\WeeklyReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WeeklyReport extends Model
{
    /** @use HasFactory<WeeklyReportFactory> */
    use HasFactory;

    /**
     * Written only by the generator, never from request input.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'metrics' => 'array',
            'narrative_from_ai' => 'boolean',
            'emailed_at' => 'datetime',
            'sms_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report) {
            $report->share_token ??= Str::random(48);
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Read a figure out of the frozen snapshot. Nothing is recomputed on
     * display: a number that moves after the manager first read it is a number
     * they stop believing.
     */
    public function metric(string $key, mixed $default = null): mixed
    {
        return data_get($this->metrics, $key, $default);
    }

    public function url(): string
    {
        return route('reports.weekly.show', $this->share_token);
    }
}
