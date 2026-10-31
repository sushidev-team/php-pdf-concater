<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Tests;

/** Fault injection for OS failures; successful calls still use the real PHP runtime. */
final class Runtime
{
    public static ?string $failure = null;

    public static int $temporaryCalls = 0;

    /** @var list<string> */
    public static array $unavailable = [];

    public static function reset(): void
    {
        self::$failure = null;
        self::$temporaryCalls = 0;
        self::$unavailable = [];
    }
}
