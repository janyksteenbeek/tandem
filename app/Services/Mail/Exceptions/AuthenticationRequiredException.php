<?php

namespace App\Services\Mail\Exceptions;

/**
 * Thrown when a provider rejected our credentials (expired/revoked token, wrong password).
 * The account must be reconnected by its owner; retrying will not help.
 */
class AuthenticationRequiredException extends ProviderException {}
