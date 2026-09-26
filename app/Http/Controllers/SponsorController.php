<?php

namespace App\Http\Controllers;

use App\Models\Sponsor;
use App\Services\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SponsorController extends Controller
{
    /**
     * «اسپانسرهای پیگیر» inside the app: everyone who keeps it free.
     */
    public function index(CurrentWorkspace $workspace): View
    {
        // The workspace goes to the layout, which draws the menu from it.
        return view('sponsors.index', [
            'workspace' => $workspace->get(),
            'sponsors' => Sponsor::showcase(),
        ]);
    }

    /**
     * A logo, served from private storage. The versioned URL changes when the
     * logo does, so the browser may keep it for a month.
     */
    public function logo(Sponsor $sponsor): Response
    {
        abort_unless($sponsor->logo_path && Storage::disk(Sponsor::LOGO_DISK)->exists($sponsor->logo_path), 404);

        return Storage::disk(Sponsor::LOGO_DISK)->response($sponsor->logo_path, headers: [
            'Cache-Control' => 'public, max-age=2592000',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Count the click, then send them on. Only active sponsors with a site
     * redirect, so the route can never be used to bounce people elsewhere.
     *
     * One count per browser session: the number is what a sponsor is shown
     * when renewing, and ten taps by one curious person are not ten visits.
     */
    public function visit(Request $request, Sponsor $sponsor): RedirectResponse
    {
        abort_unless($sponsor->is_active && $sponsor->website_url, 404);

        $key = "sponsor-clicked.{$sponsor->id}";

        if (! $request->session()->has($key)) {
            $sponsor->increment('clicks');
            $request->session()->put($key, true);
        }

        return redirect()->away($sponsor->website_url);
    }
}
