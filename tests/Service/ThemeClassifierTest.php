<?php

namespace App\Tests\Service;

use App\Enum\Theme;
use App\Service\OllamaClient;
use App\Service\ThemeClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ThemeClassifierTest extends TestCase
{
    public function testClasseUneReponseJsonValide(): void
    {
        $classifieur = $this->classifieur(json_encode(['themes' => ['Finances publiques', 'Fiscalité']]));

        $themes = $classifieur->classer("Un texte sur le budget de l'État.");

        self::assertSame([Theme::FinancesPubliques, Theme::Fiscalite], $themes);
    }

    public function testAccepteUneListeDeLibelles(): void
    {
        $classifieur = $this->classifieur(json_encode(['Écologie', 'Énergie']));

        self::assertSame([Theme::Ecologie, Theme::Energie], $classifieur->classer('Un texte sur l\'environnement.'));
    }

    public function testAccepteDesObjetsTheme(): void
    {
        $classifieur = $this->classifieur(json_encode([
            'themes' => [['theme' => 'Santé'], ['theme' => 'Recherche & innovation']],
        ]));

        self::assertSame([Theme::Sante, Theme::Recherche], $classifieur->classer('Un texte.'));
    }

    public function testIgnoreLesThemesInconnusEtDeduplique(): void
    {
        $classifieur = $this->classifieur(json_encode([
            'themes' => ['Finances publiques', 'Sujet inconnu', 'finances publiques'],
        ]));

        self::assertSame([Theme::FinancesPubliques], $classifieur->classer('Un texte.'));
    }

    public function testNeGardeQueTroisThemes(): void
    {
        $classifieur = $this->classifieur(json_encode([
            'themes' => [
                'Finances publiques', 'Fiscalité', 'Économie & entreprises',
                'Travail & emploi', 'Santé', 'Éducation',
            ],
        ]));

        self::assertCount(3, $classifieur->classer('Un texte.'));
    }

    public function testUneReponseIllisibleNeLevePasErreur(): void
    {
        $classifieur = $this->classifieur('pas du json du tout');

        self::assertSame([], $classifieur->classer('Un texte.'));
    }

    public function testUneErreurDuModeleEstAbsorbee(): void
    {
        $client = new MockHttpClient(new MockResponse('erreur', ['http_code' => 500]));
        $classifieur = new ThemeClassifier(new OllamaClient($client, 'modele', 8192));

        self::assertSame([], $classifieur->classer('Un texte.'));
    }

    public function testUnTexteVideNestPasClasse(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse(json_encode(['message' => ['content' => '{}']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
        $classifieur = new ThemeClassifier(new OllamaClient($client, 'modele', 8192));

        self::assertSame([], $classifieur->classer('   '));
        self::assertSame(0, $appels);
    }

    public function testLeModeleRecoitLeCatalogueEtLeFormatJson(): void
    {
        $corps = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$corps): MockResponse {
            $corps = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse(json_encode(['message' => ['content' => '{"themes":["Santé"]}']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
        $classifieur = new ThemeClassifier(new OllamaClient($client, 'modele', 8192));

        self::assertSame([Theme::Sante], $classifieur->classer("Un texte sur l'assurance maladie."));
        self::assertIsArray($corps);
        self::assertSame('json', $corps['format']);
        self::assertStringContainsString('Finances publiques', $corps['messages'][1]['content']);
    }

    private function classifieur(string $reponse): ThemeClassifier
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['message' => ['content' => $reponse]]), [
            'response_headers' => ['Content-Type' => 'application/json'],
        ]));

        return new ThemeClassifier(new OllamaClient($client, 'modele', 8192));
    }
}
