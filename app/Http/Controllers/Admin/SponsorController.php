<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SponsorRequest;
use App\Models\Sponsor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The platform owner enters each sponsor by hand after agreeing terms with
 * them: name, logo, a line about what they do, and where a click should go.
 */
class SponsorController extends Controller
{
    public function index(): View
    {
        return view('admin.sponsors.index', [
            'sponsors' => Sponsor::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.sponsors.form', ['sponsor' => new Sponsor(['is_active' => true])]);
    }

    public function store(SponsorRequest $request): RedirectResponse
    {
        $sponsor = new Sponsor($this->attributes($request));
        $sponsor->logo_path = $request->file('logo')->store('sponsors', Sponsor::LOGO_DISK);
        $sponsor->save();

        return redirect()->route('admin.sponsors.index')->with('status', "«{$sponsor->name}» اضافه شد.");
    }

    public function edit(Sponsor $sponsor): View
    {
        return view('admin.sponsors.form', ['sponsor' => $sponsor]);
    }

    public function update(SponsorRequest $request, Sponsor $sponsor): RedirectResponse
    {
        $sponsor->fill($this->attributes($request));

        if ($request->hasFile('logo')) {
            $previous = $sponsor->logo_path;
            $sponsor->logo_path = $request->file('logo')->store('sponsors', Sponsor::LOGO_DISK);

            if ($previous) {
                Storage::disk(Sponsor::LOGO_DISK)->delete($previous);
            }
        }

        $sponsor->save();

        return redirect()->route('admin.sponsors.index')->with('status', 'تغییرات ذخیره شد.');
    }

    public function destroy(Sponsor $sponsor): RedirectResponse
    {
        if ($sponsor->logo_path) {
            Storage::disk(Sponsor::LOGO_DISK)->delete($sponsor->logo_path);
        }

        $sponsor->delete();

        return redirect()->route('admin.sponsors.index')->with('status', "«{$sponsor->name}» حذف شد.");
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SponsorRequest $request): array
    {
        return [
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'website_url' => $request->validated('website_url'),
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) $request->validated('sort_order', 0),
        ];
    }
}
