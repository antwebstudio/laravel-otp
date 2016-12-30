<?php

namespace Erdemkeren\TemporaryAccess;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Erdemkeren\TemporaryAccess\Contracts\AccessCode as AccessCodeContract;
use Erdemkeren\TemporaryAccess\Contracts\AccessToken as AccessTokenContract;
use Erdemkeren\TemporaryAccess\Contracts\TokenInformation as TokenInformationContract;
use Erdemkeren\TemporaryAccess\Contracts\AccessCodeGenerator as AccessCodeGeneratorContract;
use Erdemkeren\TemporaryAccess\Contracts\AccessTokenRepository as AccessTokenRepositoryContract;

final class TemporaryAccessService
{
    /**
     * The access token repository implementation.
     *
     * @var AccessTokenRepositoryContract
     */
    private $repository;

    /**
     * The access code generator implementation.
     *
     * @var AccessCodeGeneratorContract
     */
    private $codeGenerator;

    /**
     * TemporaryAccessService constructor.
     *
     * @param AccessTokenRepositoryContract $repository
     * @param AccessCodeGeneratorContract   $codeGenerator
     */
    public function __construct(AccessTokenRepositoryContract $repository, AccessCodeGeneratorContract $codeGenerator)
    {
        $this->repository = $repository;
        $this->codeGenerator = $codeGenerator;
    }

    /**
     * Retrieve an access token from the storage by the plain code.
     *
     * @param AuthenticatableContract         $authenticatable The authenticatable who owns the access code.
     * @param string|TokenInformationContract $plainText       The access code of the authenticatable.
     *
     * @return null|AccessTokenContract
     */
    public function retrieveByCode(AuthenticatableContract $authenticatable, $plainText)
    {
        if (! $plainText instanceof TokenInformationContract) {
            $plainText = $this->makeAccessCode($plainText);
        }

        $authenticatableIdentifier = $authenticatable->getAuthIdentifier();

        return $this->retrieveFromRepository($authenticatableIdentifier, $plainText->encrypted());
    }

    /**
     * Retrieve an access token from the storage by the actual token.
     *
     * @param AuthenticatableContract         $authenticatable The authenticatable who owns the access code.
     * @param string|TokenInformationContract $encryptedText   The access code of the authenticatable.
     *
     * @return null|AccessTokenContract
     */
    public function retrieveByToken(AuthenticatableContract $authenticatable, $encryptedText)
    {
        if ($encryptedText instanceof TokenInformationContract) {
            $encryptedText = $encryptedText->encrypted();
        }

        $authenticatableIdentifier = $authenticatable->getAuthIdentifier();

        return $this->retrieveFromRepository($authenticatableIdentifier, $encryptedText);
    }

    /**
     * Determine if an access code exists and is valid.
     *
     * @param  AuthenticatableContract         $authenticatable The authenticatable who owns the access code.
     * @param  string|TokenInformationContract $plainText       The access token of the authenticatable.
     *
     * @return bool
     */
    public function checkCode(AuthenticatableContract $authenticatable, $plainText)
    {
        return ! ! $this->retrieveByCode($authenticatable, $plainText);
    }

    /**
     * Determine if an access token exists and is valid.
     *
     * @param  AuthenticatableContract         $authenticatable The authenticatable who owns the access code.
     * @param  string|TokenInformationContract $encryptedText   The encrypted access token of the authenticatable.
     *
     * @return bool
     */
    public function checkToken(AuthenticatableContract $authenticatable, $encryptedText)
    {
        return ! ! $this->retrieveByToken($authenticatable, $encryptedText);
    }

    /**
     * Determine if an access code record exists and prolong the expire date if so.
     * If no prolong time given, we will reset the original expire time.
     *
     * @param  AuthenticatableContract         $authenticatable The authenticatable who owns the access code.
     * @param  string|TokenInformationContract $plainText       The access code of the authenticatable.
     * @param  int|null                        $prolong         The prolong time in minutes.
     *
     * @return bool|AccessTokenContract
     */
    public function checkCodeAndProlong(AuthenticatableContract $authenticatable, $plainText, $prolong = null)
    {
        if (! $accessToken = $this->retrieveByCode($authenticatable, $plainText)) {
            return false;
        }

        return $this->prolongAndUpdateAccessToken($accessToken, $prolong);
    }

    /**
     * Determine if an access token record exists and prolong the expire date if so.
     * If no prolong time given, we will reset the original expire time.
     *
     * @param  AuthenticatableContract         $authenticatable The authenticatable who owns the access code.
     * @param  string|TokenInformationContract $encryptedText   The access code of the authenticatable.
     * @param  int|null                        $prolong         The prolong time in minutes.
     *
     * @return bool|AccessTokenContract
     */
    public function checkTokenAndProlong(AuthenticatableContract $authenticatable, $encryptedText, $prolong = null)
    {
        if (! $accessToken = $this->retrieveByToken($authenticatable, $encryptedText)) {
            return false;
        }

        return $this->prolongAndUpdateAccessToken($accessToken, $prolong);
    }

    /**
     * Generate a new access token in the storage and get the access code.
     *
     * @param  AuthenticatableContract $authenticatable The authenticatable who owns the access code.
     * @param  Carbon|null             $expiresAt       The optional expire date of the access token.
     *
     * @return AccessTokenContract
     */
    public function generate(AuthenticatableContract $authenticatable, Carbon $expiresAt = null)
    {
        $accessCode = $this->codeGenerator->generate();
        $authenticatableId = $authenticatable->getAuthIdentifier();
        $expiresAt = $expiresAt ? (string) $expiresAt : null;

        $payload = $this->repository->store($authenticatableId, (string) $accessCode, $expiresAt);
        $payload['plain'] = $accessCode->plain();

        return $this->makeAccessToken($payload);
    }

    /**
     * Update an access token in the storage.
     *
     * @param  AccessTokenContract $accessToken The access token to be updated.
     *
     * @return bool
     */
    public function update(AccessTokenContract $accessToken)
    {
        $token = $accessToken->token();
        $expiresAt = $accessToken->expiresAt();
        $authenticatableId = $accessToken->authenticatableId();

        return $this->repository->update($authenticatableId, $token, (string) $expiresAt);
    }

    /**
     * Revive an access code from the given plain text.
     *
     * @param  string $plainText The plain text code to be converted back to access code instance.
     *
     * @return AccessCodeContract
     */
    public function makeAccessCode($plainText)
    {
        return $this->codeGenerator->fromPlain($plainText);
    }

    /**
     * Retrieve the first resource by the given attributes.
     *
     * @param  array $queryParams The key - value pairs to match.
     * @param  array $attributes  The attributes to be returned from the storage.
     *
     * @return AccessTokenContract|null
     */
    public function retrieveByAttributes(array $queryParams, array $attributes = ['*'])
    {
        $attributes = $this->repository->retrieveByAttributes($queryParams, $attributes);

        return $attributes ? $this->makeAccessToken((array) $attributes) : null;
    }

    /**
     * Delete the given access token from the storage.
     *
     * @param  AccessTokenContract $accessToken The access token to be deleted.
     *
     * @return bool
     */
    public function delete(AccessTokenContract $accessToken)
    {
        return ! ! $this->repository->delete($accessToken->authenticatableId(), $accessToken->token());
    }

    /**
     * Delete the expired access tokens from the storage.
     *
     * @return void
     */
    public function deleteExpired()
    {
        $this->repository->deleteExpired();
    }

    /**
     * Retrieve an access token from the storage.
     *
     * @param  int    $authenticatableId
     * @param  string $encryptedText
     *
     * @return GenericAccessToken|null
     */
    private function retrieveFromRepository($authenticatableId, $encryptedText)
    {
        if (! $attributes = $this->repository->retrieve($authenticatableId, $encryptedText)) {
            return null;
        }

        return $this->makeAccessToken((array) $attributes);
    }

    /**
     * Prolong the access token then update it in the storage.
     *
     * @param  AccessTokenContract $accessToken
     * @param  int|null            $prolong
     *
     * @return bool|AccessTokenContract
     */
    private function prolongAndUpdateAccessToken(AccessTokenContract $accessToken, $prolong = null)
    {
        $accessToken = $this->prolongAccessToken($accessToken, $prolong);

        if ($this->update($accessToken)) {
            return $accessToken;
        }

        return false;
    }

    /**
     * Prolong an access token.
     *
     * @param AccessTokenContract $accessToken
     * @param int|null            $prolong
     *
     * @return AccessTokenContract
     */
    private function prolongAccessToken(AccessTokenContract $accessToken, $prolong = null)
    {
        $prolong = $prolong ? $prolong * 60 : $this->getNow()->diffInSeconds($accessToken->createdAt());

        return $accessToken->prolong($prolong);
    }

    /**
     * Get a new access token instance with the given attributes.
     *
     * @param array $attributes
     *
     * @return GenericAccessToken
     */
    private function makeAccessToken(array $attributes)
    {
        return new GenericAccessToken($attributes);
    }

    /**
     * Get the current UNIX timestamp.
     *
     * @return Carbon
     */
    private function getNow()
    {
        return Carbon::now();
    }
}