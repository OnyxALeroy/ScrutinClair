<?php

namespace App\Tests\Service;

use App\Service\AssembleeUrls;
use PHPUnit\Framework\TestCase;

final class AssembleeUrlsTest extends TestCase
{
    public function testPhotoUrlUsesLegislatureAndNumericUid(): void
    {
        $urls = new AssembleeUrls('assemblee-nationale.fr', 17);

        self::assertSame(
            'https://www.assemblee-nationale.fr/dyn/static/tribun/17/photos/carre/795422.jpg',
            $urls->photoUrl('PA795422'),
        );
    }
}
