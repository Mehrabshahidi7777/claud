<?php

namespace App\Services;

/**
 * Divides an amount between people without losing a rial.
 *
 * The whole module rests on this. Rial has no subunit, so splitting 10,000
 * three ways gives 3,333 each and one rial with nowhere to go. Dropping it
 * means the shares no longer sum to what was paid, every expense leaks a
 * little, and the group's balances never reach zero — a group that can never
 * finish settling up stops believing the numbers, which is the end of the
 * feature.
 *
 * So the remainder is handed out, one rial at a time, in a fixed order. The
 * order matters only in that it must be stable: recomputing the same split
 * twice has to give the same answer, or an edit shifts a rial between two
 * friends for no reason either of them can see.
 */
class SplitCalculator
{
    /**
     * An equal split, with the remainder distributed deterministically.
     *
     * @param  list<int>  $userIds
     * @return array<int, int> user id => share
     */
    public function equally(int $amount, array $userIds): array
    {
        $ids = array_values(array_unique($userIds));

        if ($ids === [] || $amount <= 0) {
            return [];
        }

        sort($ids);

        $count = count($ids);
        $base = intdiv($amount, $count);
        $remainder = $amount % $count;

        $shares = [];

        foreach ($ids as $index => $id) {
            // The first few pay one rial more. Over many expenses this
            // averages out, and within one expense nobody can tell.
            $shares[$id] = $base + ($index < $remainder ? 1 : 0);
        }

        return $shares;
    }

    /**
     * A split the user typed themselves, checked rather than trusted.
     *
     * @param  array<int, int>  $amounts  user id => share
     * @return array{ok: bool, shares: array<int, int>, difference: int}
     */
    public function custom(int $amount, array $amounts): array
    {
        $shares = array_filter(
            array_map('intval', $amounts),
            fn (int $share) => $share > 0,
        );

        $total = array_sum($shares);

        return [
            'ok' => $shares !== [] && $total === $amount,
            'shares' => $shares,
            'difference' => $amount - $total,
        ];
    }
}
