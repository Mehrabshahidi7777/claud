<?php

namespace App\Http\Controllers;

use App\Services\CurrentWorkspace;
use App\Services\TaskParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Free text in, draft tasks out. The manager confirms them on the same screen
 * before anything is created — nothing here writes a task.
 */
class TaskParseController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function __invoke(Request $request, TaskParser $parser)
    {
        $workspace = $this->workspace->get();

        $validated = $request->validate([
            'text' => ['required', 'string', 'min:5', 'max:4000'],
        ]);

        $key = "ai:parse:workspace:{$workspace->id}";
        $limit = (int) config('ai.daily_parse_limit', 50);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'ok' => false,
                'reason' => 'daily_limit',
                'message' => 'سقف استفاده‌ی امروز از دستیار هوشمند پر شده است. فرم دستی در دسترس است.',
            ], 429);
        }

        RateLimiter::hit($key, 86400);

        $result = $parser->parse($validated['text'], $workspace);

        // Unavailable is not an error: the manual form is always there, and
        // saying so plainly beats a spinner that never resolves.
        if (! $result['used_ai']) {
            return response()->json([
                'ok' => false,
                'reason' => 'unavailable',
                'message' => 'دستیار هوشمند در دسترس نیست. تسک را دستی ثبت کنید.',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'tasks' => $result['tasks'],
        ]);
    }
}
