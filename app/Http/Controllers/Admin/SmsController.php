<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsOutbound;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The follow-up messages the engine sent, for answering "پیامک به من
 * نرسید" with the provider's own answer instead of a guess.
 */
class SmsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['queued', 'sent', 'failed'])],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $phone = PhoneNumber::normalize($validated['phone'] ?? null);

        return view('admin.sms', [
            'messages' => SmsOutbound::query()
                ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($phone, fn ($query) => $query->where('phone', $phone))
                ->with('workspace')
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
            'filters' => $validated,
        ]);
    }
}
