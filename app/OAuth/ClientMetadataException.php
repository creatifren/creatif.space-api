<?php

namespace App\OAuth;

use RuntimeException;

/** A client_id metadata document that could not be fetched or is not acceptable. The message is safe to show the client. */
final class ClientMetadataException extends RuntimeException {}
