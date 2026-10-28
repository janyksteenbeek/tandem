<?php

namespace App\Services\Mail\Exceptions;

/**
 * Provider was temporarily unavailable; the job should be retried.
 */
class TransientProviderException extends ProviderException {}
