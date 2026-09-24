<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A guard against the one bug that has now bitten this project five times.
 *
 * Every time a feature adds a column the engine writes — meeting_id,
 * recurring_task_id, contract_id — it is easy to add it to the migration and
 * forget $fillable. Eloquent then drops the value in complete silence: the
 * insert succeeds, the row is wrong, and it surfaces hours later as "the
 * link doesn't work" with nothing in any log.
 *
 * This fails the moment a column is added to `tasks` without a decision being
 * made about it, which is cheaper than finding it by hand a sixth time.
 */
class MassAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columns a caller must never be able to set through mass assignment, or
     * that are managed by the framework.
     *
     * @var list<string>
     */
    private const NOT_ASSIGNABLE = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function test_every_task_column_is_either_fillable_or_deliberately_not(): void
    {
        $columns = Schema::getColumnListing('tasks');

        $fillable = (new Task)->getFillable();

        $unaccounted = array_diff($columns, $fillable, self::NOT_ASSIGNABLE);

        $this->assertSame(
            [],
            array_values($unaccounted),
            'These `tasks` columns are in neither $fillable nor the deliberate exclusion list: '
            .implode(', ', $unaccounted)
            .'. A column the engine writes but $fillable omits is dropped silently on create().',
        );
    }
}
