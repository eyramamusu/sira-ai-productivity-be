<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Reset password · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8efe6; --card: #fffaf5; --text: #2b211b; --muted: #7a6a5f; --accent: #8b5a3c; --error: #b3261e; --border: #e6d6c7; }
        @media (prefers-color-scheme: dark) { :root { --bg: #1d1712; --card: #2b211b; --text: #f8efe6; --muted: #bba99b; --accent: #e7b46d; --error: #f2b8b5; --border: #4a3a2f; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 400px; background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 28px 24px; }
        h1 { margin: 0 0 6px; font-size: 22px; }
        p { margin: 0 0 18px; color: var(--muted); }
        label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
        input { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--border); background: transparent; color: inherit; font: inherit; }
        input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        button { margin-top: 22px; width: 100%; min-height: 48px; border: 0; border-radius: 999px; background: var(--accent); color: var(--card); font: inherit; font-weight: 600; cursor: pointer; }
        .error { color: var(--error); font-size: 14px; margin: 6px 0 0; }
    </style>
</head>
<body>
<main>
    @if ($done)
        <h1>Password updated</h1>
        <p>Your password has been reset and you've been signed out on every device. Open the {{ config('app.name') }} app and sign in with your new password.</p>
    @else
        <h1>Choose a new password</h1>
        <p>For {{ old('email', $email) ?: 'your account' }}</p>

        <form method="post" action="{{ url('/reset-password') }}">
            @csrf
            <input type="hidden" name="token" value="{{ old('token', $token) }}">
            <input type="hidden" name="email" value="{{ old('email', $email) }}">

            @error('email') <p class="error" role="alert">{{ $message }}</p> @enderror
            @error('token') <p class="error" role="alert">This reset link is incomplete. Request a new one from the app.</p> @enderror

            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required autofocus>
            @error('password') <p class="error" role="alert">{{ $message }}</p> @enderror

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>

            <button type="submit">Reset password</button>
        </form>
    @endif
</main>
</body>
</html>
