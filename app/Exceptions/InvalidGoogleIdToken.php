<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An ID token we will not accept. The message says why, for the log only:
 * the caller answers every one of these with the same generic 422.
 */
class InvalidGoogleIdToken extends RuntimeException {}
