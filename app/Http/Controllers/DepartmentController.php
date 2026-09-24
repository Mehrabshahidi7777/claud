<?php

namespace App\Http\Controllers;

use App\Enums\DepartmentKind;
use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Task;
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The parts of a company, and who may do what.
 *
 * Two things on one page because they are read together: a manager setting up
 * a workspace is answering "چه بخش‌هایی داریم و هرکس چه دسترسی‌ای دارد"
 * as a single question.
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function index()
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageDepartments), 403);

        $departments = Department::forWorkspace($workspace->id)
            ->with('lead')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $members = $workspace->members()->orderBy('name')->get();

        return view('departments.index', [
            'workspace' => $workspace,
            'departments' => $departments,
            'members' => $members,
            'kinds' => DepartmentKind::cases(),
            'roles' => WorkspaceRole::cases(),
            'permissions' => collect(Permission::cases())->groupBy(fn (Permission $p) => $p->group()),

            // How many people sit in each, and how much open work it carries.
            // A department with nobody in it is usually a setup mistake, and
            // saying so is cheaper than letting tasks route into a void.
            'headcount' => $members
                ->groupBy(fn ($member) => $member->pivot->department_id)
                ->map->count(),
            'openTasks' => Task::forWorkspace($workspace->id)
                ->chaseable()
                ->whereNotNull('department_id')
                ->selectRaw('department_id, COUNT(*) as total')
                ->groupBy('department_id')
                ->pluck('total', 'department_id'),
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageDepartments), 403);

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(DepartmentKind::class)],
            'name' => ['nullable', 'string', 'max:100'],
            'lead_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $kind = DepartmentKind::from($validated['kind']);

        $department = Department::create([
            'workspace_id' => $workspace->id,
            'kind' => $kind,

            // The kind's own name unless the company calls it something else.
            // Most do not, and making them type it is friction for nothing.
            'name' => filled($validated['name'] ?? null) ? $validated['name'] : $kind->label(),
            'lead_id' => $validated['lead_id'] ?? null,
        ]);

        Activity::record($department, 'department.created', $workspace->id, $request->user()->id);

        return back()->with('status', $department->name.' اضافه شد.');
    }

    public function update(Request $request, Department $department)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageDepartments), 403);
        abort_unless($department->workspace_id === $workspace->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'lead_id' => [
                'nullable',
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $department->update([
            'name' => $validated['name'],
            'lead_id' => $validated['lead_id'] ?? null,
        ]);

        return back()->with('status', 'تغییرات ثبت شد.');
    }

    /**
     * Retiring a department rather than deleting it. Its people keep their
     * placement and its finished work keeps its label — a department that
     * disappears takes a year of history's meaning with it.
     */
    public function toggle(Request $request, Department $department)
    {
        $workspace = $this->workspace->get();

        abort_unless($this->workspace->can(Permission::ManageDepartments), 403);
        abort_unless($department->workspace_id === $workspace->id, 404);

        $department->update(['is_active' => ! $department->is_active]);

        return back()->with(
            'status',
            $department->is_active ? 'دوباره فعال شد.' : 'بایگانی شد. سابقه‌اش باقی می‌ماند.',
        );
    }
}
