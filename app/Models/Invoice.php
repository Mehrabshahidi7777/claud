<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Rial is stored; Toman is read. Dividing at the edge keeps a factor of
     * ten out of the arithmetic.
     */
    public function totalInToman(): int
    {
        return intdiv($this->total, 10);
    }

    public function subtotalInToman(): int
    {
        return intdiv($this->subtotal, 10);
    }

    public function vatInToman(): int
    {
        return intdiv($this->vat, 10);
    }

    public function planName(): string
    {
        return config("payment.plans.$this->plan_key.name", $this->plan_key);
    }
}
