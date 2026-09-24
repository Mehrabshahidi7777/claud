<?php

namespace Database\Factories;

use App\Enums\DepartmentKind;
use App\Models\Department;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'kind' => DepartmentKind::Operations,
            'name' => DepartmentKind::Operations->label(),
            'is_active' => true,
        ];
    }

    public function kind(DepartmentKind $kind): static
    {
        return $this->state(['kind' => $kind, 'name' => $kind->label()]);
    }
}
