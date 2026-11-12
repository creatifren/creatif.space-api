<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * Channel `tap` (config/logging.php): puts the RedactingProcessor on every
 * handler of the channel. On the handler, not the logger, so it survives a
 * `stack` channel that collects other channels' handlers.
 */
final class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new RedactingProcessor);
            }
        }
    }
}
