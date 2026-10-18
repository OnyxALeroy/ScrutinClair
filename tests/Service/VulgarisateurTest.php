<?php

namespace App\Tests\Service;

use App\Service\OllamaClient;
use App\Service\Vulgarisateur;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VulgarisateurTest extends TestCase
{
    public function testTexteCourtUnSeulAppel(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse(json_encode(['message' => ['content' => 'Résumé']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });

        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));
        $resultat = $vulgarisateur->vulgariserTexteLoi('Un texte court.');

        self::assertSame('Résumé', $resultat);
        self::assertSame(1, $appels);
    }

    public function testTexteLongDeclencheUnMapReduce(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse(json_encode(['message' => ['content' => 'Bloc']]), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });

        $paragraphe = str_repeat('La décision est prise après examen. ', 300); // ~10 500 caractères
        $texte = implode("\n\n", array_fill(0, 5, $paragraphe)); // ~52 500 caractères

        $etapes = [];
        $vulgarisateur = new Vulgarisateur(new OllamaClient($client, 'modele', 8192));
        $vulgarisateur->vulgariserTexteLoi($texte, function (int $courant, int $total) use (&$etapes): void {
            $etapes[] = [$courant, $total];
        });

        // 5 morceaux + 1 synthèse finale = 6 appels.
        self::assertSame(6, $appels);
        self::assertNotEmpty($etapes);
    }
}
