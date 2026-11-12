<?php

use App\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\Log;

/* The redaction is wired into every real log channel, and a line written through one comes out clean. */

it('taps every writing channel with the redactor', function (string $channel) {
    expect(config("logging.channels.{$channel}.tap"))->toContain(RedactSensitiveData::class);
})->with(['single', 'daily', 'monthly', 'webhooks', 'stderr', 'syslog', 'errorlog', 'papertrail', 'slack']);

it('writes a redacted line through a channel, also inside a stack', function (string $driver) {
    $path = storage_path('logs/redaction-test-'.uniqid().'.log');
    config([
        'logging.channels.redaction_file' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'tap' => [RedactSensitiveData::class]],
        'logging.channels.redaction_stack' => ['driver' => 'stack', 'channels' => ['redaction_file']],
    ]);

    Log::channel($driver === 'stack' ? 'redaction_stack' : 'redaction_file')->warning('reply failed for rani@client.test', [
        'user' => 17,
        'text' => 'please DM me your address',
        'token' => 'eyJa.eyJb.c',
        'error' => new RuntimeException('Bearer abc.def rejected'),
    ]);

    $written = file_get_contents($path);
    @unlink($path);

    expect($written)->toContain('reply failed for [email]')
        ->toContain('"user":17')
        ->not->toContain('rani@')
        ->not->toContain('please DM me')
        ->not->toContain('eyJa.eyJb.c')
        ->not->toContain('Bearer abc.def');
})->with(['single', 'stack']);
