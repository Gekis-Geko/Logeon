<?php

declare(strict_types=1);

namespace Core;

use Core\Adapters\ArrayConfigRepository;
use Core\Adapters\DefaultDbConnectionProvider;
use Core\Adapters\PhpSessionStoreAdapter;
use Core\Adapters\SessionAuthContext;
use Core\Adapters\SystemClock;
use Core\Adapters\TwigTemplateRendererAdapter;
use Core\Contracts\AuthContextInterface;
use Core\Contracts\ClockInterface;
use Core\Contracts\ConfigRepositoryInterface;
use Core\Contracts\DbConnectionProviderInterface;
use Core\Contracts\SessionInterface;
use Core\Contracts\TemplateRendererInterface;
use Core\Logging\LegacyLoggerAdapter;
use Core\Logging\LoggerInterface;

class AppContext
{
    private static ?SessionInterface $session = null;
    private static ?TemplateRendererInterface $templateRenderer = null;
    private static ?ConfigRepositoryInterface $config = null;
    private static ?ClockInterface $clock = null;
    private static ?AuthContextInterface $authContext = null;
    private static ?DbConnectionProviderInterface $dbProvider = null;
    private static ?LoggerInterface $logger = null;

    public static function resetRuntimeState(): void
    {
        self::$session = null;
        self::$templateRenderer = null;
        self::$config = null;
        self::$clock = null;
        self::$authContext = null;
        self::$dbProvider = null;
        self::$logger = null;
    }

    public static function session(): SessionInterface
    {
        if (!(self::$session instanceof SessionInterface)) {
            self::$session = new PhpSessionStoreAdapter();
        }
        return self::$session;
    }

    public static function setSession(?SessionInterface $session): void
    {
        self::$session = $session;
    }

    public static function templateRenderer(): TemplateRendererInterface
    {
        if (!(self::$templateRenderer instanceof TemplateRendererInterface)) {
            self::$templateRenderer = new TwigTemplateRendererAdapter();
        }
        return self::$templateRenderer;
    }

    public static function setTemplateRenderer(?TemplateRendererInterface $renderer): void
    {
        self::$templateRenderer = $renderer;
    }

    public static function config(): ConfigRepositoryInterface
    {
        if (!(self::$config instanceof ConfigRepositoryInterface)) {
            self::$config = new ArrayConfigRepository();
        }
        return self::$config;
    }

    public static function setConfig(?ConfigRepositoryInterface $config): void
    {
        self::$config = $config;
    }

    public static function clock(): ClockInterface
    {
        if (!(self::$clock instanceof ClockInterface)) {
            self::$clock = new SystemClock();
        }
        return self::$clock;
    }

    public static function setClock(?ClockInterface $clock): void
    {
        self::$clock = $clock;
    }

    public static function authContext(): AuthContextInterface
    {
        if (!(self::$authContext instanceof AuthContextInterface)) {
            self::$authContext = new SessionAuthContext();
        }
        return self::$authContext;
    }

    public static function setAuthContext(?AuthContextInterface $context): void
    {
        self::$authContext = $context;
    }

    public static function dbProvider(): DbConnectionProviderInterface
    {
        if (!(self::$dbProvider instanceof DbConnectionProviderInterface)) {
            self::$dbProvider = new DefaultDbConnectionProvider();
        }
        return self::$dbProvider;
    }

    public static function setDbProvider(?DbConnectionProviderInterface $provider): void
    {
        self::$dbProvider = $provider;
    }

    public static function logger(): LoggerInterface
    {
        if (!(self::$logger instanceof LoggerInterface)) {
            self::$logger = new LegacyLoggerAdapter();
        }
        return self::$logger;
    }

    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }
}


