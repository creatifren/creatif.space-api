#!/usr/bin/env bash
#
# This repository is public: no real person's email address belongs in it.
# Fails on any address in a tracked file whose domain is not on the list
# below (RFC 2606 example domains and reserved test TLDs, creatif.space,
# and the few service/bot addresses the code really uses). A plain grep,
# well under 10 s. Run locally: scripts/check-public-emails.sh
#
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

address='[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}'

# Matched against the address's domain, case-insensitively, to the end.
allowed='@(([a-z0-9-]+\.)*(example\.(com|org|net)|creatif\.space)'\
'|([a-z0-9-]+\.)+(test|example|invalid|localhost)'\
'|notcreatif\.space'\
'|system\.gserviceaccount\.com'\
'|example-project\.iam\.gserviceaccount\.com'\
'|anthropic\.com'\
'|users\.noreply\.github\.com'\
'|mailinator\.com|sub\.yopmail\.com)$'
# notcreatif.space: the test that a look-alike is not counted as ours.
# system.gserviceaccount.com: Google Play's notification account (README).
# mailinator / yopmail: public disposable inboxes the waitlist refuses.

# Third-party code we do not edit: Filament's published assets carry their
# authors' credits; lock files list package metadata.
found=$(git grep -I -n -o -E "$address" -- . \
    ':(exclude)public/js/filament/**' \
    ':(exclude)public/css/filament/**' \
    ':(exclude)*.lock' \
    ':(exclude)**/package-lock.json' \
    | grep -v -i -E "$allowed" || true)

if [ -n "$found" ]; then
    echo "Real-looking email addresses in tracked files (this repository is public):" >&2
    echo "$found" >&2
    echo >&2
    echo "Use an example.com / .test address instead, or add a real service address to scripts/check-public-emails.sh." >&2
    exit 1
fi
echo "No real-looking email addresses."
