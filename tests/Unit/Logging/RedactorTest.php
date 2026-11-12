<?php

use App\Logging\RedactingProcessor;
use App\Logging\Redactor;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * G5 log hygiene: emails, names, tokens and what people wrote never reach
 * a log line; ids, codes and statuses do.
 */

it('masks email addresses anywhere in a string', function (string $in, string $out) {
    expect(Redactor::string($in))->toBe($out);
})->with([
    ['login failed for rani@client.test', 'login failed for [email]'],
    ['to: First.Last+tag@sub.example.co.id, cc a@b.io', 'to: [email], cc [email]'],
    ['no address here', 'no address here'],
]);

it('masks tokens and keys', function (string $in, string $out) {
    expect(Redactor::string($in))->toBe($out);
})->with([
    'bearer' => ['Authorization: Bearer abc.DEF-123_xyz', 'Authorization: Bearer [token]'],
    'jwt' => ['token eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl here', 'token [token] here'],
    'api key' => ['key cs_live_AbC123xyz used', 'key [token] used'],
    'refresh token hex' => ['def50200'.str_repeat('a1', 40), '[token]'],
    'query' => ['GET /cb?code=SplxlOBeZQ&state=xyz&access_token=abc', 'GET /cb?code=[redacted]&state=xyz&access_token=[redacted]'],
]);

it('keeps ids, codes and statuses', function () {
    $line = 'post 01JD2ZK4M0Q5X8Y9R7T6V5W4X3 failed: invalid_grant (400), user 42';
    expect(Redactor::string($line))->toBe($line);
});

it('replaces sensitive keys whole, at any depth, and scans the rest', function () {
    $out = Redactor::value([
        'user' => 17,
        'email' => 'rani@client.test',
        'name' => 'Rani Putri',
        'approver_email' => 'boss@client.test',
        'caption' => 'Our new menu drops Friday! Call 0812…',
        'payload' => ['text' => 'hey can you DM me', 'from' => ['username' => '@studio', 'display_name' => 'Studio K']],
        'headers' => ['Authorization' => 'Bearer xyz', 'X-MCP-Secret' => 'shh'],
        'refresh_token' => 'def50200abc',
        'client_secret' => 'sekret',
        'error' => 'SQLSTATE[23000]: Duplicate entry rani@client.test for key users_email_unique',
        'status' => 'failed',
        'empty_name' => null,
    ]);

    expect($out)->toBe([
        'user' => 17,
        'email' => '[redacted]',
        'name' => '[redacted]',
        'approver_email' => '[redacted]',
        'caption' => '[redacted]',
        'payload' => ['text' => '[redacted]', 'from' => ['username' => '@studio', 'display_name' => '[redacted]']],
        'headers' => ['Authorization' => '[redacted]', 'X-MCP-Secret' => '[redacted]'],
        'refresh_token' => '[redacted]',
        'client_secret' => '[redacted]',
        'error' => 'SQLSTATE[23000]: Duplicate entry [email] for key users_email_unique',
        'status' => 'failed',
        'empty_name' => null,
    ]);
});

it('turns an exception into a redacted class, message, place and trace', function () {
    $out = Redactor::value(['exception' => new RuntimeException('no user rani@client.test (token eyJa.eyJb.c)')]);

    expect($out['exception']['class'])->toBe(RuntimeException::class)
        ->and($out['exception']['message'])->toBe('no user [email] (token [token])')
        ->and($out['exception']['file'])->toContain('RedactorTest.php:')
        ->and(json_encode($out))->not->toContain('rani@');
});

it('redacts a whole Monolog record: message, context and extra', function () {
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'oauth.client_registered for owner@studio.test',
        context: ['name' => 'Claude', 'client_id' => '9d1e', 'ip' => '203.0.113.9'],
        extra: ['email' => 'x@y.zz'],
    );

    $out = (new RedactingProcessor)($record);

    expect($out->message)->toBe('oauth.client_registered for [email]')
        ->and($out->context)->toBe(['name' => '[redacted]', 'client_id' => '9d1e', 'ip' => '203.0.113.9'])
        ->and($out->extra)->toBe(['email' => '[redacted]']);
});
