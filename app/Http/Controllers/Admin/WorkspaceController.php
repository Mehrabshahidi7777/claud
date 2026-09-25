<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceType;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\SmsOutbound;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\BillingService;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Every customer, and what support can do for one of them.
 */
class WorkspaceController extends Controller
{
    private const GRANTING = [
        SubscriptionStatus::Trialing->value,
        SubscriptionStatus::Active->value,
        SubscriptionStatus::Grace->value,
    ];

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(WorkspaceType::class)],
            'status' => ['nullable', Rule::in(['trialing', 'active', 'grace', 'lapsed'])],
        ]);

        $search = trim($validated['q'] ?? '');
        $phone = PhoneNumber::normalize($search);

        $workspaces = Workspace::query()
            // A phone number finds the company that person is in, which is
            // how a support call starts: "I'm 0912…, my SMS stopped."
            ->when($search !== '', fn ($query) => $phone !== null
                ? $query->whereHas('members', fn ($members) => $members->where('phone', $phone))
                : $query->where('name', 'like', "%{$search}%"))
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['status'] ?? null, fn ($query, $status) => $status === 'lapsed'
                ? $query->whereDoesntHave('subscriptions', fn ($s) => $s->whereIn('status', self::GRANTING))
                : $query->whereHas('subscriptions', fn ($s) => $s->where('status', $status)))
            ->withCount('members')
            ->with([
                'owners',
                'subscriptions' => fn ($query) => $query->whereIn('status', self::GRANTING)->latest('ends_at'),
            ])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.workspaces.index', [
            'workspaces' => $workspaces,
            'filters' => $validated,
        ]);
    }

    public function show(Workspace $workspace, BillingService $billing): View
    {
        $workspace->load([
            'members' => fn ($query) => $query->orderBy('name'),
            'subscriptions' => fn ($query) => $query->latest('ends_at'),
            'invoices' => fn ($query) => $query->latest()->limit(20),
            'payments' => fn ($query) => $query->latest()->limit(20),
        ]);

        $open = Task::forWorkspace($workspace->id)->chaseable();

        return view('admin.workspaces.show', [
            'workspace' => $workspace,
            'current' => $billing->currentSubscription($workspace),
            'tasks' => [
                'open' => (clone $open)->count(),
                'overdue' => (clone $open)->where('due_at', '<', now())->count(),
                'done30' => Task::forWorkspace($workspace->id)
                    ->where('status', TaskStatus::Done->value)
                    ->where('completed_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'sms' => [
                'sent30' => (int) SmsOutbound::where('workspace_id', $workspace->id)
                    ->where('status', 'sent')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->sum('segments'),
                'failed30' => SmsOutbound::where('workspace_id', $workspace->id)
                    ->where('status', 'failed')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'activities' => Activity::where('workspace_id', $workspace->id)
                ->with('user')
                ->latest('id')
                ->limit(15)
                ->get(),
        ]);
    }

    public function extend(Request $request, Workspace $workspace, BillingService $billing): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $subscription = $billing->grantDays($workspace, (int) $validated['days']);

        Activity::record($subscription, 'subscription.granted_by_platform', $workspace->id, $request->user()->id, [
            'days' => (int) $validated['days'],
        ]);

        return back()->with('status', "{$validated['days']} روز به اشتراک «{$workspace->name}» اضافه شد.");
    }

    public function updateSms(Request $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validate([
            'sms_quota' => ['required', 'integer', 'min:0', 'max:1000000'],
            'sms_enabled' => ['nullable', 'boolean'],
        ]);

        $workspace->update([
            'sms_quota' => (int) $validated['sms_quota'],
            'sms_enabled' => (bool) ($validated['sms_enabled'] ?? false),
        ]);

        Activity::record($workspace, 'workspace.sms_updated_by_platform', $workspace->id, $request->user()->id, [
            'sms_quota' => (int) $validated['sms_quota'],
            'sms_enabled' => (bool) ($validated['sms_enabled'] ?? false),
        ]);

        return back()->with('status', 'تنظیمات پیامک ذخیره شد.');
    }
}
