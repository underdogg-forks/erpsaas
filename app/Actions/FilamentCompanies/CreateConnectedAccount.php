<?php

namespace App\Actions\FilamentCompanies;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Wallo\FilamentCompanies\ConnectedAccount;
use Wallo\FilamentCompanies\Contracts\CreatesConnectedAccounts;
use Wallo\FilamentCompanies\FilamentCompanies;

class CreateConnectedAccount implements CreatesConnectedAccounts
{
    /**
     * Create a connected account for a given user.
     */
    public function create(Authenticatable $user, string $provider, ProviderUser $providerUser): ConnectedAccount
    {
        return FilamentCompanies::connectedAccountModel()::forceCreate([
            'user_id'       => $user->getAuthIdentifier(),
            'provider'      => mb_strtolower($provider),
            'provider_id'   => $providerUser->getId(),
            'name'          => $providerUser->getName(),
            'nickname'      => $providerUser->getNickname(),
            'email'         => $providerUser->getEmail(),
            'avatar_path'   => $providerUser->getAvatar(),
            'token'         => $providerUser->token,
            'secret'        => $providerUser->tokenSecret ?? null,
            'refresh_token' => $providerUser->refreshToken ?? null,
            'expires_at'    => property_exists($providerUser, 'expiresIn') ? now()->addSeconds($providerUser->expiresIn) : null,
        ]);
    }
}
