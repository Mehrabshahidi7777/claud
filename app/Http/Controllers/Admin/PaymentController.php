<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Every payment attempt across all customers. The one to watch is "paid but
 * not verified": the bank has the money and the customer may not have their
 * subscription.
 */
class PaymentController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
        ]);

        return view('admin.payments', [
            'payments' => Payment::query()
                ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->with(['workspace', 'invoice'])
                ->latest()
                ->paginate(30)
                ->withQueryString(),
            'status' => $validated['status'] ?? null,
        ]);
    }
}
