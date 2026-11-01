<?php

namespace App\Actions\Sso;

use App\Actions\Organizations\CreateOrganization;
use App\Exceptions\SsoException;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Services\Sso\Sso;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Turns the account an identity provider returned into a local user: the
 * already linked one, an existing user with the same verified email, a fresh
 * registration, or (while signed in) the current user with the identity
 * attached.
 */
class ResolveSsoUser
{
    public const INTENT_LOGIN = 'login';

    public const INTENT_LINK = 'link';

    public const INTENT_CONFIRM = 'confirm';

    public function __construct(
        private Sso $sso,
        private CreateOrganization $createOrganization,
    ) {}

    /**
     * @throws SsoException
     */
    public function handle(string $provider, SocialiteUser $socialUser, ?User $currentUser, string $intent = self::INTENT_LOGIN): User
    {
        $providerName = $this->sso->name($provider);

        if (! $this->sso->domainAllowed($provider, $socialUser)) {
            throw SsoException::domainNotAllowed($providerName);
        }

        $identity = SsoIdentity::query()
            ->where('provider', $provider)
            ->where('provider_id', (string) $socialUser->getId())
            ->first();

        if ($currentUser) {
            return $this->resolveForCurrentUser($provider, $socialUser, $currentUser, $identity, $intent);
        }

        if ($identity) {
            $this->refreshIdentity($identity, $socialUser);

            return $identity->user;
        }

        $email = $socialUser->getEmail();

        if (! is_string($email) || $email === '') {
            throw SsoException::emailMissing($providerName);
        }

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser) {
            if (! $this->sso->linksExistingAccounts()) {
                throw SsoException::accountExists($providerName);
            }

            if (! $this->sso->emailVerified($socialUser)) {
                throw SsoException::emailNotVerified($providerName);
            }

            return DB::transaction(function () use ($existingUser, $provider, $socialUser) {
                if ($existingUser->email_verified_at === null) {
                    $existingUser->forceFill(['email_verified_at' => now()])->save();
                }

                $this->attachIdentity($existingUser, $provider, $socialUser);

                return $existingUser;
            });
        }

        if (! Features::enabled(Features::registration())) {
            throw SsoException::registrationDisabled();
        }

        return $this->register($provider, $socialUser, $email);
    }

    /**
     * @throws SsoException
     */
    private function resolveForCurrentUser(string $provider, SocialiteUser $socialUser, User $currentUser, ?SsoIdentity $identity, string $intent): User
    {
        $providerName = $this->sso->name($provider);

        if ($identity && $identity->user_id !== $currentUser->id) {
            throw $intent === self::INTENT_CONFIRM
                ? SsoException::identityMismatch($providerName)
                : SsoException::linkedToAnotherUser($providerName);
        }

        if ($intent === self::INTENT_CONFIRM) {
            if (! $identity) {
                throw SsoException::identityMismatch($providerName);
            }

            $this->refreshIdentity($identity, $socialUser);

            return $currentUser;
        }

        if ($identity) {
            $this->refreshIdentity($identity, $socialUser);
        } else {
            $this->attachIdentity($currentUser, $provider, $socialUser);
        }

        return $currentUser;
    }

    private function register(string $provider, SocialiteUser $socialUser, string $email): User
    {
        $user = DB::transaction(function () use ($provider, $socialUser, $email) {
            $user = User::create([
                'name' => $this->displayName($socialUser, $email),
                'email' => $email,
                'password' => Str::password(48),
            ]);

            if ($this->sso->emailVerified($socialUser)) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->createOrganization->handle($user, $user->name."'s Organization", isPersonal: true);
            $this->attachIdentity($user, $provider, $socialUser);

            return $user;
        });

        event(new Registered($user));

        return $user;
    }

    private function attachIdentity(User $user, string $provider, SocialiteUser $socialUser): SsoIdentity
    {
        return $user->ssoIdentities()->updateOrCreate(
            ['provider' => $provider],
            [...$this->identityAttributes($socialUser), 'provider_id' => (string) $socialUser->getId()],
        );
    }

    private function refreshIdentity(SsoIdentity $identity, SocialiteUser $socialUser): void
    {
        $identity->fill($this->identityAttributes($socialUser))->save();
    }

    /**
     * @return array{email: string|null, name: string|null, avatar: string|null, last_used_at: Carbon}
     */
    private function identityAttributes(SocialiteUser $socialUser): array
    {
        return [
            'email' => $socialUser->getEmail(),
            'name' => $socialUser->getName(),
            'avatar' => $socialUser->getAvatar(),
            'last_used_at' => now(),
        ];
    }

    private function displayName(SocialiteUser $socialUser, string $email): string
    {
        foreach ([$socialUser->getName(), $socialUser->getNickname()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return Str::limit(trim($candidate), 255, '');
            }
        }

        return Str::before($email, '@');
    }
}
