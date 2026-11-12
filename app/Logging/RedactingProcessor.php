<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Monolog processor: every record's message, context and extra through the Redactor. */
final class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: Redactor::string($record->message),
            context: Redactor::value($record->context),
            extra: Redactor::value($record->extra),
        );
    }
}
