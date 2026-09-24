<?php

namespace App\Services;

use App\Models\Settlement;
use App\Models\SharedExpense;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Who owes whom, and the shortest way to make it stop.
 *
 * The balances are the easy half. The half that makes the feature worth
 * having is the second: six friends after a trip owe each other in a tangle
 * of fifteen little debts, and nobody untangles it — so nobody pays. Turning
 * that into "رضا به حسین ۴۲۰ هزار بدهد، تمام" is the whole product here.
 */
class BalanceSheet
{
    /**
     * Net position per member. Positive means the group owes them.
     *
     * @return Collection<int, array{user: User, net: int}>
     */
    public function balances(Workspace $workspace): Collection
    {
        $members = $workspace->members()->orderBy('name')->get()->keyBy('id');

        $net = $members->mapWithKeys(fn (User $user) => [$user->id => 0])->all();

        SharedExpense::where('workspace_id', $workspace->id)
            ->with('shares')
            ->get()
            ->each(function (SharedExpense $expense) use (&$net) {
                if (array_key_exists($expense->payer_id, $net)) {
                    $net[$expense->payer_id] += $expense->amount;
                }

                foreach ($expense->shares as $share) {
                    if (array_key_exists($share->user_id, $net)) {
                        $net[$share->user_id] -= $share->amount;
                    }
                }
            });

        // Money handed over moves the needle the same way paying a bill does:
        // the giver is owed more (or owes less), the receiver the reverse.
        Settlement::where('workspace_id', $workspace->id)
            ->get()
            ->each(function (Settlement $settlement) use (&$net) {
                if (array_key_exists($settlement->from_user_id, $net)) {
                    $net[$settlement->from_user_id] += $settlement->amount;
                }

                if (array_key_exists($settlement->to_user_id, $net)) {
                    $net[$settlement->to_user_id] -= $settlement->amount;
                }
            });

        return collect($net)
            ->map(fn (int $value, int $userId) => ['user' => $members[$userId], 'net' => $value])
            ->sortByDesc('net')
            ->values();
    }

    /**
     * The fewest transfers that clear everything.
     *
     * Greedy: repeatedly send the largest debtor's money to the largest
     * creditor. It is not provably minimal in every case — that problem is
     * NP-hard — but it never exceeds one transfer fewer than there are
     * people, which is the difference between a list somebody acts on and a
     * tangle they ignore.
     *
     * @return list<array{from: User, to: User, amount: int}>
     */
    public function transfers(Workspace $workspace): array
    {
        $balances = $this->balances($workspace);

        $creditors = $balances->filter(fn (array $row) => $row['net'] > 0)
            ->map(fn (array $row) => ['user' => $row['user'], 'left' => $row['net']])
            ->values()
            ->all();

        $debtors = $balances->filter(fn (array $row) => $row['net'] < 0)
            ->map(fn (array $row) => ['user' => $row['user'], 'left' => -$row['net']])
            ->sortByDesc('left')
            ->values()
            ->all();

        $transfers = [];
        $c = 0;
        $d = 0;

        while ($c < count($creditors) && $d < count($debtors)) {
            $amount = min($creditors[$c]['left'], $debtors[$d]['left']);

            if ($amount > 0) {
                $transfers[] = [
                    'from' => $debtors[$d]['user'],
                    'to' => $creditors[$c]['user'],
                    'amount' => $amount,
                ];
            }

            $creditors[$c]['left'] -= $amount;
            $debtors[$d]['left'] -= $amount;

            if ($creditors[$c]['left'] === 0) {
                $c++;
            }

            if ($debtors[$d]['left'] === 0) {
                $d++;
            }
        }

        return $transfers;
    }

    /**
     * What one person owes another right now, used to stop a settlement
     * recording more than is actually due.
     */
    public function owedBetween(Workspace $workspace, int $fromUserId, int $toUserId): int
    {
        foreach ($this->transfers($workspace) as $transfer) {
            if ($transfer['from']->id === $fromUserId && $transfer['to']->id === $toUserId) {
                return $transfer['amount'];
            }
        }

        return 0;
    }
}
