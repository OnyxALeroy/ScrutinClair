<?php

namespace App\Tests\Enum;

use App\Enum\Chambre;
use PHPUnit\Framework\TestCase;

final class ChambreTest extends TestCase
{
    public function testResolvesFilters(): void
    {
        self::assertSame(Chambre::AssembleeNationale, Chambre::depuisFiltre('an'));
        self::assertSame(Chambre::Senat, Chambre::depuisFiltre('SENAT'));
        self::assertNull(Chambre::depuisFiltre('all'));
    }

    public function testRejectsUnknownFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Chambre::depuisFiltre('europe');
    }
}
