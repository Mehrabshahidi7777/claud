<?php

namespace App\Http\Controllers;

use App\Models\Sponsor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SponsorController extends Controller
{
    /**
     * «اسپانسرهای پیگیر» inside the app: everyone who keeps it free.
     */
    public function index(): View
    {
        return view('sponsors.index', ['sponsors' => Sponsor::showcase()]);
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
     */
    public function visit(Sponsor $sponsor): RedirectResponse
    {
        abort_unless($sponsor->is_active && $sponsor->website_url, 404);

        $sponsor->increment('clicks');

        return redirect()->away($sponsor->website_url);
    }
}
