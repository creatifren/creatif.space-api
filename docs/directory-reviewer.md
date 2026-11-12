# Directory reviewer account

For the Claude Connectors Directory and ChatGPT Apps reviews: one account
that signs in with a password, with no email verification step, no magic
link and no MFA. Its password does not expire. Every other account signs in
exactly as before (Google, or email + password with a verified address).

1. Staff create the account the usual way (Rizal).
2. Flag it and set its password on the server:

   ```bash
   php artisan reviewer:grant reviewer@example.com --password
   ```

   (Without `--password` a 24-character password is generated and printed
   once.) Granting also marks the address verified.
3. The reviewer signs in with `POST /auth/reviewer/login` `{email, password}`
   (session cookie, like `/auth/login`), then connects the MCP server; the
   consent screen and OAuth flow are the normal ones.
4. When the review is done: `php artisan reviewer:grant reviewer@example.com --revoke`
   (clears the flag and the password).

`/auth/reviewer/login` refuses every account without the flag, whatever its
password, with the same answer as a wrong password. It is throttled per IP
(10/min) and per address (5 per 15 min), and every attempt (success,
rejected, suspended, throttled, granted, revoked) is a row in
`directory_reviewer_logins` with the address as a SHA-256 hash, never in
clear.
