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
        self::$dbAdapter = $adapter;
    }

    private static function db(): DbAdapterInterface
    {
        if (self::$dbAdapter instanceof DbAdapterInterface) {
            return self::$dbAdapter;
        }

        self::$dbAdapter = DbAdapterFactory::createFromConfig();
        return self::$dbAdapter;
    }

    private static function signinService(): AuthSigninService
    {
        return new AuthSigninService(self::db());
    }

    private static function signupService(): AuthSignupService
    {
        return new AuthSignupService(self::db());
    }

    private static function passwordResetService(): AuthPasswordResetService
    {
        return new AuthPasswordResetService(self::db());
    }

    private static function passwordChangeService(): AuthPasswordChangeService
    {
        return new AuthPasswordChangeService(self::db());
    }

    public static function signin()
    {
        return self::signinService()->signin();
    }

    public static function signup(): void
    {
        self::signupService()->signup();
    }

    /**
     * @return array{status:string,message:string}
     */
    public static function verifyEmailToken(string $token): array
    {
        return self::signupService()->verifyEmailToken($token);
    }

    public static function signinByUserId(int $userId, bool $emitResponse = false): array
    {
        return self::signinService()->signinByUserId($userId, $emitResponse);
    }

    public static function signout()
    {
        return self::signinService()->signout();
    }

    public static function signinCharactersList(bool $emitResponse = false): array
    {
        return self::signinService()->listSigninCharactersForCurrentUser($emitResponse);
    }

    public static function signinCharacterSelect(int $characterId, bool $emitResponse = false): array
    {
        return self::signinService()->selectSigninCharacter($characterId, $emitResponse);
    }

    public static function resetPassword()
    {
        return self::passwordResetService()->resetPassword();
    }

    public static function resetPasswordConfirm()
    {
        return self::passwordResetService()->resetPasswordConfirm();
    }

    public function changePassword($payload = null): void
    {
        self::passwordChangeService()->changePassword($payload);
    }
}
