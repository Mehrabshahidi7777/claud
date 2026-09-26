@extends('layouts.app')

@section('title', 'اسپانسرهای پیگیر')

@section('content')

<div class="mx-auto max-w-4xl">
    <h1 class="text-lg font-bold">اسپانسرهای پیگیر</h1>
    <p class="mt-1 text-sm text-slate-600">
        {{ config('brand.name') }} برای همه رایگان است، چون این مجموعه‌ها هزینه‌اش را می‌دهند.
    </p>

    @if ($sponsors->isEmpty())
        <div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">
            به‌زودی.
        </div>
    @else
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach ($sponsors as $sponsor)
                @include('sponsors._card')
            @endforeach
        </div>
    @endif
</div>

@endsection
