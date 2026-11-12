@php
    $scopeLabels = (array) config('mcp.scope_labels');
    $maskedEmail = \App\Support\McpConsent::maskEmail($user->email);
    $displayName = trim((string) $user->name);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connect {{ $client->name }} · Creatif Space</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Lora:wght@600&display=swap">
    <style>
        /* v5 tokens. Lora for the H1 only, Inter everywhere else; borders, never shadows. */
        :root {
            --bg: #FAFAF8; --surface: #FFFFFF; --ink: #0F172A; --muted: #5A6478; --line: #ECE9E2;
            --accent: #FF9F43; --on-accent: #0F172A; --subtle: #F4F2EE;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0B1120; --surface: #111827; --ink: #F1F5F9; --muted: #A3ADBF; --line: #263043; --subtle: #172033; }
        }
        * { box-sizing: border-box; }
        html { background: var(--bg); }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink);
               font: 400 15px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 28px; }
        .brand { font-weight: 600; letter-spacing: -0.01em; margin: 0 0 20px; }
        h1 { font-family: Lora, Georgia, "Times New Roman", serif; font-weight: 600; font-size: 24px; line-height: 1.25; letter-spacing: -0.01em; margin: 0 0 20px; }
        p { margin: 0 0 16px; color: var(--muted); }
        .account { border: 1px solid var(--line); border-radius: 12px; background: var(--subtle); padding: 14px 16px; margin: 0 0 20px; }
        .account dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 14px; }
        .account dt { color: var(--muted); }
        .account dd { margin: 0; color: var(--ink); min-width: 0; overflow-wrap: anywhere; }
        .account dd strong { font-weight: 600; }
        .account .sep { color: var(--muted); padding: 0 2px; }
        .switch { margin: 10px 0 0; padding-top: 10px; border-top: 1px solid var(--line); font-size: 14px; color: var(--muted); }
        .switch form { display: inline; margin: 0; }
        .link { padding: 0; border: 0; background: none; font: inherit; font-weight: 600; color: var(--ink); text-decoration: underline; text-underline-offset: 2px; cursor: pointer; }
        .lead { color: var(--ink); font-weight: 500; margin-bottom: 8px; }
        ul { list-style: none; padding: 0; margin: 0 0 16px; border-top: 1px solid var(--line); }
        li { padding: 12px 0; border-bottom: 1px solid var(--line); }
        li strong { display: block; color: var(--ink); font-weight: 600; }
        li span { color: var(--muted); font-size: 14px; }
        .never { font-size: 13px; margin-bottom: 20px; }
        .actions { display: flex; gap: 12px; }
        .actions form { flex: 1; margin: 0; }
        .actions button { width: 100%; padding: 11px 14px; border-radius: 10px; font: inherit; font-weight: 600; cursor: pointer; }
        .approve { background: var(--accent); color: var(--on-accent); border: 1px solid var(--accent); }
        .deny { background: transparent; color: var(--ink); border: 1px solid var(--line); }
        button:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <div class="brand">Creatif Space</div>
    <h1>{{ $client->name }} wants to connect to your account</h1>
    @php($documentHost = \App\OAuth\ClientMetadataDocuments::hostFor($client))
    @if ($documentHost !== null)
        <p class="lead">This app identifies itself from <strong>{{ $documentHost }}</strong>.</p>
    @endif

    <section class="account" aria-label="Account">
        <dl>
            <dt>Signed in as</dt>
            <dd>@if ($displayName !== '')<strong>{{ $displayName }}</strong><span class="sep"> · </span>@endif{{ $maskedEmail }}</dd>
            <dt>Workspace</dt>
            <dd>{{ \App\Support\McpConsent::workspaceName($user) }}</dd>
        </dl>
        <div class="switch">Not you?
            <form method="post" action="{{ route('oauth.switch-account') }}">
                @csrf
                <button type="submit" class="link">Use another account</button>
            </form>
        </div>
    </section>

    <p class="lead">{{ $client->name }} will be able to:</p>
    <ul>
        @forelse ($scopes as $scope)
            <li><strong>{{ $scopeLabels[$scope->id] ?? ucfirst($scope->id) }}</strong><span>{{ $scope->description }}</span></li>
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
</main>
</body>
</html>
