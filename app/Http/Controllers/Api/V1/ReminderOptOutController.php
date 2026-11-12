<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialApprovalLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The approver's one-click "turn reminders off for this plan", from the
 * Content Plan reminder email. The signed URL is the credential (route
 * middleware `signed`): it names one link and expires.
 *
 * GET only shows a button; POST turns reminders off. Mail scanners open
 * links with GET, and must not opt the approver out.
 */
class ReminderOptOutController extends Controller
{
    public function show(Request $request, string $link): Response
    {
        $row = $this->find($link);
        if ($row === null) {
            return $this->page('This plan is no longer available.', 404);
        }
        if (! $row->remindersOn()) {
            return $this->page('Reminders are already off for this plan.');
        }

        $action = e($request->fullUrl());
        $title = e($row->title ?: 'this Content Plan');

        return $this->page(
            "Stop reminder emails for {$title}?",
            200,
            "<form method=\"post\" action=\"{$action}\"><button type=\"submit\">Turn reminders off</button></form>",
        );
    }

    public function store(Request $request, string $link): Response
    {
        $row = $this->find($link);
        if ($row === null) {
            return $this->page('This plan is no longer available.', 404);
        }
        $row->update(['reminders_enabled' => false]);

        return $this->page('Reminders are off for this plan. You can still open it from the email you were sent.');
    }

    private function find(string $ulid): ?SocialApprovalLink
    {
        return SocialApprovalLink::query()->where('ulid', $ulid)->whereNull('revoked_at')->first();
    }

    private function page(string $message, int $status = 200, string $extra = ''): Response
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>creatif.space</title>'
            .'<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;color:#1a1a1a}button{font:inherit;padding:.6rem 1.2rem;border-radius:.5rem;border:0;background:#1a1a1a;color:#fff;cursor:pointer}</style>'
            .'</head><body><p>'.$message.'</p>'.$extra.'</body></html>';

        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
