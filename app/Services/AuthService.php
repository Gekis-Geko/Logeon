<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class AuthService
{
    /** @var DbAdapterInterface|null */
    private static $dbAdapter = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        static::$dbAdapter = $adapter;
    }

    private static function db(): DbAdapterInterface
    {
        if (static::$dbAdapter instanceof DbAdapterInterface) {
            return static::$dbAdapter;
        }

        static::$dbAdapter = DbAdapterFactory::createFromConfig();
        return static::$dbAdapter;
    }

    private static function signinService(): AuthSigninService
    {
        return new AuthSigninService(static::db());
    }

    private static function signupService(): AuthSignupService
    {
        return new AuthSignupService(static::db());
    }

    private static function passwordResetService(): AuthPasswordResetService
    {
        return new AuthPasswordResetService(static::db());
    }

    private static function passwordChangeService(): AuthPasswordChangeService
    {
        return new AuthPasswordChangeService(static::db());
    }

    public static function signin()
    {
        return static::signinService()->signin();
    }

    public static function signup(): void
    {
        static::signupService()->signup();
    }

    /**
     * @return array{status:string,message:string}
     */
    public static function verifyEmailToken(string $token): array
    {
        return static::signupService()->verifyEmailToken($token);
    }

    public static function signinByUserId(int $userId, bool $emitResponse = false): array
    {
        return static::signinService()->signinByUserId($userId, $emitResponse);
    }

    public static function signout()
    {
        return static::signinService()->signout();
    }

    public static function signinCharactersList(bool $emitResponse = false): array
    {
        return static::signinService()->listSigninCharactersForCurrentUser($emitResponse);
    }

    public static function signinCharacterSelect(int $characterId, bool $emitResponse = false): array
    {
        return static::signinService()->selectSigninCharacter($characterId, $emitResponse);
    }

    public static function resetPassword()
    {
        return static::passwordResetService()->resetPassword();
    }

    public static function resetPasswordConfirm()
    {
        return static::passwordResetService()->resetPasswordConfirm();
    }

    public function changePassword($payload = null): void
    {
        static::passwordChangeService()->changePassword($payload);
    }
}
