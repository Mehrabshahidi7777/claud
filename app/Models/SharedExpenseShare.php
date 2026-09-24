<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's part of one shared expense. The shares of an expense always
 * sum to its amount exactly — see SplitCalculator for why that is not a
 * detail.
 */
class SharedExpenseShare extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(SharedExpense::class, 'shared_expense_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
