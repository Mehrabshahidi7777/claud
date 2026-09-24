<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ExpenseCategory;
use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The monthly money picture, built from the two things this system knows
 * that an accounting package does not: who approved a spend, and who is
 * supposed to be collecting a debt.
 */
class FinanceReport
{
    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, spent: int, by_category: Collection<int, array<string, mixed>>, previous_spent: int}
     */
    public function spending(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Expense::forWorkspace($workspace->id)
            ->between($from->toDateString(), $to->toDateString())
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as entries')
            ->groupBy('category')
            ->get();

        $spent = (int) $rows->sum('total');

        // The previous window of the same length, so a number on screen can
        // say whether it is going up. A figure with nothing to compare it to
        // tells a manager nothing they can act on.
        $length = max(1, (int) $from->diffInDays($to));

        $previousSpent = (int) Expense::forWorkspace($workspace->id)
            ->between(
                $from->subDays($length + 1)->toDateString(),
                $from->subDay()->toDateString(),
            )
            ->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'spent' => $spent,
            'previous_spent' => $previousSpent,
            'by_category' => $rows
                ->map(fn ($row) => [
                    // Already an enum: the model's cast applies to the grouped
                    // column too, so re-hydrating it would fail on the way back.
                    'category' => $row->category instanceof ExpenseCategory
                        ? $row->category
                        : ExpenseCategory::from($row->category),
                    'total' => (int) $row->total,
                    'entries' => (int) $row->entries,
                    'share' => $spent > 0 ? round($row->total / $spent * 100) : 0,
                ])
                ->sortByDesc('total')
                ->values(),
        ];
    }

    /**
     * Outstanding money, bucketed by how late it is.
     *
     * The buckets are the ones a collections conversation actually uses: not
     * yet due, a month late, two months late, and the bucket that has stopped
     * being a payment delay and started being a bad debt.
     *
     * @return array{total: int, overdue: int, buckets: array<int, array<string, mixed>>, worst: Collection<int, Receivable>}
     */
    public function receivables(Workspace $workspace): array
    {
        $open = Receivable::forWorkspace($workspace->id)
            ->outstanding()
            ->with(['owner', 'task'])
            ->get();

        $buckets = [
            ['label' => 'هنوز سررسید نشده', 'min' => null, 'max' => 0, 'total' => 0, 'count' => 0, 'tone' => 'slate'],
            ['label' => 'تا ۳۰ روز تأخیر', 'min' => 1, 'max' => 30, 'total' => 0, 'count' => 0, 'tone' => 'amber'],
            ['label' => '۳۱ تا ۶۰ روز', 'min' => 31, 'max' => 60, 'total' => 0, 'count' => 0, 'tone' => 'orange'],
            ['label' => 'بیش از ۶۰ روز', 'min' => 61, 'max' => null, 'total' => 0, 'count' => 0, 'tone' => 'red'],
        ];

        foreach ($open as $receivable) {
            $days = $receivable->daysOverdue();

            foreach ($buckets as $index => $bucket) {
                $aboveFloor = $bucket['min'] === null || $days >= $bucket['min'];
                $belowCeiling = $bucket['max'] === null || $days <= $bucket['max'];

                if ($aboveFloor && $belowCeiling) {
                    $buckets[$index]['total'] += $receivable->outstanding();
                    $buckets[$index]['count']++;
                    break;
                }
            }
        }

        return [
            'total' => $open->sum(fn (Receivable $r) => $r->outstanding()),
            'overdue' => $open->filter->isOverdue()->sum(fn (Receivable $r) => $r->outstanding()),
            'buckets' => $buckets,
            'worst' => $open->filter->isOverdue()
                ->sortByDesc(fn (Receivable $r) => $r->daysOverdue())
                ->take(8)
                ->values(),
        ];
    }

    /**
     * Purchases and expenses that were approved and never recorded as spent.
     *
     * This view exists only because approvals and expenses live in the same
     * system, and it is the one number in here a manager cannot get anywhere
     * else: money the company said yes to, with no trace of where it went.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function approvedButUnrecorded(Workspace $workspace): Collection
    {
        return ApprovalRequest::forWorkspace($workspace->id)
            ->where('status', ApprovalStatus::Approved->value)
            ->whereIn('type', [ApprovalType::Purchase->value, ApprovalType::Expense->value])
            ->whereNotNull('amount')

            // Give the company a fortnight to file the receipt before the
            // request is held up as unaccounted for.
            ->where('decided_at', '<', now()->subDays(14))
            ->whereDoesntHave('expense')
            ->with('requester')
            ->orderBy('decided_at')
            ->get();
    }
}
