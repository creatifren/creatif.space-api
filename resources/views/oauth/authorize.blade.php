<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connect {{ $client->name }} · Creatif Space</title>
    <style>
        :root { --bg: #fafaf9; --card: #ffffff; --ink: #161615; --muted: #6b6b66; --line: #e3e3e0; --accent: #161615; --accent-ink: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0e0e0d; --card: #161615; --ink: #ededec; --muted: #a1a09a; --line: #3e3e3a; --accent: #ededec; --accent-ink: #161615; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink);
               font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px; }
        .brand { font-weight: 700; letter-spacing: -0.01em; margin-bottom: 20px; }
        h1 { font-size: 20px; line-height: 1.3; margin: 0 0 8px; }
        p { margin: 0 0 16px; color: var(--muted); }
        ul { list-style: none; padding: 0; margin: 0 0 20px; border-top: 1px solid var(--line); }
        li { padding: 12px 0; border-bottom: 1px solid var(--line); }
        li strong { display: block; color: var(--ink); }
        li span { color: var(--muted); font-size: 14px; }
        .never { font-size: 13px; }
        .actions { display: flex; gap: 12px; }
        form { flex: 1; margin: 0; }
        button { width: 100%; padding: 11px 14px; border-radius: 10px; font: inherit; font-weight: 600; cursor: pointer; }
        .approve { background: var(--accent); color: var(--accent-ink); border: 1px solid var(--accent); }
        .deny { background: transparent; color: var(--ink); border: 1px solid var(--line); }
        button:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
        .who { font-size: 13px; margin-top: 16px; margin-bottom: 0; }
    </style>
</head>
<body>
<main>
    <div class="brand">Creatif Space</div>
    <h1>{{ $client->name }} wants to connect to your account</h1>
    <p>It will be able to:</p>
    <ul>
        @forelse ($scopes as $scope)
            <li><strong>{{ ucfirst($scope->id) }}</strong><span>{{ $scope->description }}</span></li>
        @empty
            <li><strong>Read</strong><span>{{ config('mcp.scopes.read') }}</span></li>
        @endforelse
    </ul>
    <p class="never">{{ \App\Support\McpConsent::never(collect($scopes)->map(fn ($scope) => $scope->id)) }} You can disconnect it any time in Settings → Connected apps.</p>

    <div class="actions">
        <form method="post" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="deny">Cancel</button>
        </form>
        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="approve">Allow</button>
        </form>
    </div>
    <p class="who">Signed in as {{ $user->email }}</p>
</main>
</body>
</html>
