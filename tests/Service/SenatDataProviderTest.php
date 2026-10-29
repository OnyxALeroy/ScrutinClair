<?php

namespace App\Tests\Service;

use App\Service\ResilientDownloader;
use App\Service\SenatDataProvider;
use App\Service\SenatSqlDumpParser;
use App\Service\SenatUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class SenatDataProviderTest extends TestCase
{
    public function testGroupAtDateIncludesApparentedMembers(): void
    {
        $provider = new SenatDataProvider(
            new ResilientDownloader(new MockHttpClient()),
            new SenatSqlDumpParser(),
            new SenatUrls('senat.fr'),
            sys_get_temp_dir(),
        );

        $donnees = [
            'sen' => [],
            'grppol' => [
                ['grppolcod' => 'UMP', 'grppollibcou' => 'Groupe Les Républicains'],
            ],
            'memgrppol' => [
                [
                    'senmat' => '14069U',
                    'grppolcod' => 'UMP',
                    'typapppolcod' => 'A',
                    'memgrppoldatdeb' => '2014-10-07 00:00:00',
                    'memgrppoldatfin' => '2026-09-30 00:00:00',
                ],
            ],
            'sennom' => [],
        ];

        $propriete = new \ReflectionProperty(SenatDataProvider::class, 'donnees');
        $propriete->setValue($provider, $donnees);

        self::assertSame('UMP', $provider->groupePour('14069U', new \DateTimeImmutable('2024-11-30')));
        self::assertNull($provider->groupePour('14069U', new \DateTimeImmutable('2010-01-01')));
    }
}
