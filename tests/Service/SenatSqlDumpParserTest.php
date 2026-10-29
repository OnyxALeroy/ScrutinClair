<?php

namespace App\Tests\Service;

use App\Service\SenatSqlDumpParser;
use PHPUnit\Framework\TestCase;

final class SenatSqlDumpParserTest extends TestCase
{
    public function testParseExtractsOnlySupportedCopyBlocksAndNulls(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE sen (senmat text);
            COPY sen (senmat, sennomuse, sendatnai) FROM stdin;
            04033B	Retailleau	1960-11-20 00:00:00
            01043Q	Létard	\N
            \.
            COPY grppol (grppolcod, grppollibcou) FROM stdin;
            UMP	Groupe Les Républicains
            \.
            COPY memgrppol (memgrppolid, grppolcod, typapppolcod, memgrppoldatdeb, memgrppoldatfin, senmat) FROM stdin;
            2386	UMP	N	2012-11-07 00:00:00	2024-10-21 00:00:00	04033B
            \.
            COPY autre (id) FROM stdin;
            999
            \.
            SQL;

        $donnees = (new SenatSqlDumpParser())->parse($sql);

        self::assertArrayNotHasKey('autre', $donnees);
        self::assertCount(2, $donnees['sen']);
        self::assertSame('Retailleau', $donnees['sen'][0]['sennomuse']);
        self::assertNull($donnees['sen'][1]['sendatnai']);
        self::assertCount(1, $donnees['grppol']);
        self::assertSame('UMP', $donnees['memgrppol'][0]['grppolcod']);
        self::assertSame('04033B', $donnees['memgrppol'][0]['senmat']);
    }
}
