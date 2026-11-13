{{-- Shown by EnsureAdminMfa when two-factor sign-in cannot be checked (fail closed). Standalone on
     purpose: no layout and no database reads, so it renders even when the database is the problem. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Two-factor sign-in cannot be checked') }} | {{ config('app.name') }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f5f6f8; color: #1f2937; }
        main { max-width: 34rem; margin: 12vh auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px; }
        h1 { font-size: 1.25rem; margin: 0 0 12px; }
        p { line-height: 1.5; margin: 0 0 20px; }
        button { background: #1f3a5f; color: #fff; border: 0; border-radius: 6px; padding: 10px 18px; font-size: 1rem; cursor: pointer; }
        .code { color: #6b7280; font-size: .875rem; margin-bottom: 8px; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <div class="code">503</div>
        <h1>{{ __('Two-factor sign-in cannot be checked') }}</h1>
        <p>{{ __('Admin pages are closed because two-factor sign-in cannot be checked right now. Nothing has been changed. Please try again later.') }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">{{ __('Log out') }}</button>
        </form>
    </div>
</main>
</body>
</html>
