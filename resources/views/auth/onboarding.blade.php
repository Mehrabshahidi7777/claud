@extends('layouts.app')

@section('title', 'شروع')

@section('content')
<div class="mx-auto max-w-sm pt-10">
    <h1 class="text-xl font-bold">آخرین قدم</h1>
    <p class="mt-2 text-sm text-slate-600">
        نام خودتان و نام شرکت. همین.
    </p>

    <form method="POST" action="{{ route('onboarding.store') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">نام و نام خانوادگی</label>
            <input id="name" name="name" value="{{ old('name') }}" autofocus required
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:border-slate-900 focus:outline-none">
            @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="workspace" class="block text-sm font-medium">نام شرکت</label>
            <input id="workspace" name="workspace" value="{{ old('workspace') }}" required
                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 focus:border-slate-900 focus:outline-none">
            @error('workspace')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-slate-900 px-4 py-2.5 font-medium text-white hover:bg-slate-800">
            بساز و شروع کن
        </button>
    </form>
</div>
@endsection
