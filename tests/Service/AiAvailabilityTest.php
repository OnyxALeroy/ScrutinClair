<?php

namespace App\Tests\Service;

use App\Service\AiAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiAvailabilityTest extends TestCase
{
    #[DataProvider('valeursDesactivees')]
    public function testEstDesactiveePourUneValeurFausse(string $valeur): void
    {
        self::assertFalse((new AiAvailability($valeur))->isEnabled());
    }

    #[DataProvider('valeursActivees')]
    public function testEstActiveePourUneValeurVraie(string $valeur): void
    {
        self::assertTrue((new AiAvailability($valeur))->isEnabled());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valeursDesactivees(): iterable
    {
        yield '0' => ['0'];
        yield 'false' => ['false'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
        yield 'chaîne vide' => [''];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valeursActivees(): iterable
    {
        yield '1' => ['1'];
        yield 'true' => ['true'];
        yield 'on' => ['on'];
        yield 'yes' => ['yes'];
    }
}
