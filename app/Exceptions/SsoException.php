<?php

namespace App\Exceptions;

use Exception;

/**
 * A sign-in through an identity provider that cannot be completed. The
 * message is safe to show to the person who tried to sign in.
 */
class SsoException extends Exception
{
    public static function emailNotVerified(string $providerName): self
    {
        return new self(__(':provider did not confirm that this email address is verified, so it cannot be linked to an account.', ['provider' => $providerName]));
    }

    public static function emailMissing(string $providerName): self
    {
        return new self(__(':provider did not share an email address for this account.', ['provider' => $providerName]));
    }

    public static function domainNotAllowed(string $providerName): self
    {
        return new self(__('This :provider account belongs to a domain that is not allowed to sign in here.', ['provider' => $providerName]));
    }

    public static function registrationDisabled(): self
    {
        return new self(__('There is no account for this email address and new registrations are closed.'));
    }

    public static function accountExists(string $providerName): self
    {
        return new self(__('An account with this email address already exists. Sign in to it first, then connect :provider from your security settings.', ['provider' => $providerName]));
    }

    public static function linkedToAnotherUser(string $providerName): self
    {
        return new self(__('This :provider account is already connected to a different user.', ['provider' => $providerName]));
    }

    public static function identityMismatch(string $providerName): self
    {
        return new self(__('That :provider account is not connected to the user you are signed in as.', ['provider' => $providerName]));
    }

    public static function providerFailed(string $providerName): self
    {
        return new self(__('Signing in with :provider did not complete. Please try again.', ['provider' => $providerName]));
    }
}
