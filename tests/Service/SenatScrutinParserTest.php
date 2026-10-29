<?php

namespace App\Tests\Service;

use App\Service\SenatScrutinParser;
use PHPUnit\Framework\TestCase;

final class SenatScrutinParserTest extends TestCase
{
    public function testParseHtmlExtractsScrutinMetadataAndCounts(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html lang="fr"><body>
            <h1 class="page-title">Scrutin n°120 - séance du 1 décembre 2024</h1>
            <p class="page-lead">sur l'amendement n° A-22 à l'article 14 bis du projet de loi de finances pour 2025</p>
            <span class="badge">Adopté</span>
            <a href="https://www.senat.fr/dossier-legislatif/pjlf2025.html">Le dossier législatif</a>
            <div class="card">
                <h2>Résultat du scrutin</h2>
                <p><strong>242</strong> votants · <strong>242</strong> suffrages exprimés</p>
                <p><strong>210</strong> pour · <strong>32</strong> contre</p>
                <p>Abstention : <span>0</span></p>
                <p>N'ont pas pris part au vote : <span>105</span></p>
            </div>
            </body></html>
            HTML;

        $scrutin = (new SenatScrutinParser())->parseHtml(
            $html,
            'https://www.senat.fr/scrutin-public/2024/scr2024-120.html',
        );

        self::assertSame('scr2024-120', $scrutin->uid);
        self::assertSame('Scrutin n°120 - séance du 1 décembre 2024', $scrutin->titre);
        self::assertStringContainsString('amendement n° A-22', $scrutin->objet);
        self::assertSame('2024-12-01', $scrutin->dateScrutin?->format('Y-m-d'));
        self::assertSame('Adopté', $scrutin->resultat);
        self::assertSame('pjlf2025', $scrutin->dossierRef);
        self::assertSame(242, $scrutin->votants);
        self::assertSame(242, $scrutin->suffragesExprimes);
        self::assertSame(210, $scrutin->pour);
        self::assertSame(32, $scrutin->contre);
        self::assertSame(0, $scrutin->abstentions);
        self::assertSame(105, $scrutin->nonVotants);
    }

    public function testParseVotesJsonKeepsOfficialCodes(): void
    {
        $json = json_encode([
            'votes' => [
                ['matricule' => '04033B', 'vote' => 'p', 'siege' => 58],
                ['matricule' => '01043Q', 'vote' => 'c', 'siege' => 12],
                ['matricule' => '21090J', 'vote' => 'a', 'siege' => 3],
                ['matricule' => '14089Y', 'vote' => 'n', 'siege' => 44],
            ],
        ], JSON_THROW_ON_ERROR);

        $parser = new SenatScrutinParser();
        $votes = $parser->parseVotesJson($json);

        self::assertCount(4, $votes);
        self::assertSame(['04033B', 'p', 58], [$votes[0]['matricule'], $votes[0]['vote'], $votes[0]['siege']]);
        self::assertSame('Pour', $parser->statut('p'));
        self::assertSame('Contre', $parser->statut('c'));
        self::assertSame('Abstention', $parser->statut('a'));
        self::assertNull($parser->statut('n'));
    }
}
