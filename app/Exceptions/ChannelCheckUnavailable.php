<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The relay could not answer a channel check (down, slow, rate-limited).
 * Says nothing about the channel: its health is left as it was.
 */
class ChannelCheckUnavailable extends RuntimeException {}
