<?php

namespace App\Service;

use App\Dto\SenatScrutin;

final class SenatScrutinParser
{
    private const MOIS = [
        'janvier' => 1,
        'fevrier' => 2,
        'février' => 2,
        'mars' => 3,
        'avril' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7,
        'aout' => 8,
        'août' => 8,
        'septembre' => 9,
        'octobre' => 10,
        'novembre' => 11,
        'decembre' => 12,
        'décembre' => 12,
    ];

    public function parseHtml(string $html, string $url): SenatScrutin
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);

        $titre = $this->texte($xpath, '//h1[contains(concat(" ", normalize-space(@class), " "), " page-title ")]');
        if ($titre === '') {
            $titre = 'Scrutin';
        }

        $objet = $this->texte($xpath, '//p[contains(concat(" ", normalize-space(@class), " "), " page-lead ")]');
        if ($objet === '') {
            $objet = $titre;
        }

        $resultat = $this->texte($xpath, '//span[contains(concat(" ", normalize-space(@class), " "), " badge ")]');
        $resultat = $resultat !== '' ? $resultat : null;

        $dossierRef = null;
        $lienDossier = $xpath->query('//a[contains(@href, "/dossier-legislatif/")]')->item(0);
        if ($lienDossier instanceof \DOMElement) {
            $chemin = (string) parse_url($lienDossier->getAttribute('href'), PHP_URL_PATH);
            $dossierRef = basename($chemin, '.html');
            if ($dossierRef === '' || $dossierRef === '.') {
                $dossierRef = null;
            }
        }

        $resultatTexte = $this->texte($xpath, '//div[contains(@class, "card")][.//h2[contains(., "sultat du scrutin")]]');
        $votants = $this->nombre($resultatTexte, '/\b(\d+)\s+votants\b/u');
        $suffragesExprimes = $this->nombre($resultatTexte, '/\b(\d+)\s+suffrages exprimés\b/u');
        $pour = $this->nombre($resultatTexte, '/\b(\d+)\s+pour\b/u');
        $contre = $this->nombre($resultatTexte, '/\b(\d+)\s+contre\b/u');
        $abstentions = $this->nombre($resultatTexte, '/Abstention\s*:\s*(\d+)/u');
        $nonVotants = $this->nombre($resultatTexte, "/N'ont pas pris part au vote\\s*:\\s*(\\d+)/u");

        $uid = basename((string) parse_url($url, PHP_URL_PATH), '.html');

        return new SenatScrutin(
            uid: $uid,
            titre: $titre,
            objet: $objet,
            dateScrutin: $this->dateDepuisTitre($titre),
            resultat: $resultat,
            dossierRef: $dossierRef,
            lien: $url,
            votants: $votants,
            suffragesExprimes: $suffragesExprimes,
            pour: $pour,
            contre: $contre,
            abstentions: $abstentions,
            nonVotants: $nonVotants,
        );
    }

    /**
     * @return list<array{matricule: string, vote: string, siege: ?int}>
     */
    public function parseVotesJson(string $json): array
    {
        $donnees = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $votes = $donnees['votes'] ?? null;
        if (!\is_array($votes)) {
            return [];
        }

        $resultat = [];
        foreach ($votes as $vote) {
            if (!\is_array($vote)) {
                continue;
            }

            $matricule = $vote['matricule'] ?? null;
            $position = $vote['vote'] ?? null;
            if (!\is_string($matricule) || $matricule === '' || !\is_string($position)) {
                continue;
            }

            $resultat[] = [
                'matricule' => $matricule,
                'vote' => $position,
                'siege' => isset($vote['siege']) && is_numeric($vote['siege']) ? (int) $vote['siege'] : null,
            ];
        }

        return $resultat;
    }

    public function statut(string $code): ?string
    {
        return match ($code) {
            'p' => 'Pour',
            'c' => 'Contre',
            'a' => 'Abstention',
            'n' => null,
            default => null,
        };
    }

    private function texte(\DOMXPath $xpath, string $expression): string
    {
        $noeud = $xpath->query($expression)->item(0);
        if (!$noeud instanceof \DOMNode) {
            return '';
        }

        return $this->normaliser($noeud->textContent);
    }

    private function normaliser(string $texte): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }

    private function nombre(string $texte, string $expression): ?int
    {
        if (preg_match($expression, $texte, $correspondances) !== 1) {
            return null;
        }

        return (int) $correspondances[1];
    }

    private function dateDepuisTitre(string $titre): ?\DateTimeImmutable
    {
        if (preg_match('/séance du (.+)$/u', $titre, $correspondances) !== 1) {
            return null;
        }

        $date = $this->normaliser($correspondances[1]);
        $date = (string) preg_replace('/^1er\b/u', '1', $date);

        if (preg_match('/^(\d{1,2})\s+([a-zéûô]+)\s+(\d{4})$/u', mb_strtolower($date, 'UTF-8'), $parts) !== 1) {
            return null;
        }

        $mois = self::MOIS[$parts[2]] ?? null;
        if ($mois === null) {
            return null;
        }

        return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $parts[3], $mois, (int) $parts[1])))->setTime(0, 0);
    }
}
