<?php

namespace App\Service;

/**
 * Fabrique le titre court affiché au grand public pour un texte de loi.
 *
 * Le titre court a la forme « Sujet (article 4, amendement 1158) » :
 * le sujet est rédigé par l'IA, les références sont extraites du titre
 * officiel par règles (déterministe, jamais inventées). Si l'IA ne produit
 * rien d'exploitable, on retombe sur un raccourcissement par règles.
 * En dernier ressort, le titre court reste vide et l'interface affiche
 * le titre officiel complet.
 */
final class TitreCourtGenerator
{
    public const LONGUEUR_MAX = 70;

    private const SYSTEME = <<<'PROMPT'
    Tu rédiges des titres courts et clairs pour des textes de loi français,
    destinés au grand public (journal local, non juriste).
    Tu réponds uniquement par le titre : pas de guillemets, pas de point final,
    pas de commentaire, pas de marqueur de liste.
    PROMPT;

    /** Mots interdits dans le sujet : le code les ajoute lui-même. */
    private const BRUIT = ['n°', 'article', 'amendement', 'sous-amendement', 'première lecture', 'nouvelle lecture', 'seconde lecture', 'examen prioritaire'];

    public function __construct(private readonly OllamaClient $ollama)
    {
    }

    /**
     * @return ?string le titre court complet, null si rien d'exploitable
     */
    public function generer(string $titreOfficiel, ?string $dossier = null): ?string
    {
        $titreOfficiel = trim($titreOfficiel);
        if ($titreOfficiel === '') {
            return null;
        }

        $reference = $this->references($titreOfficiel);
        $budget = $reference === ''
            ? self::LONGUEUR_MAX
            : self::LONGUEUR_MAX - mb_strlen($reference) - 4;

        if ($budget < 16) {
            $reference = '';
            $budget = self::LONGUEUR_MAX;
        }

        $sujet = $this->demanderSujet($titreOfficiel, $dossier, $budget);
        $sujet ??= $this->sujetParRegle($titreOfficiel);

        if ($sujet === null || $sujet === '') {
            return null;
        }

        $sujet = $this->ecreter($sujet, $budget);
        $sujet = $this->capitaliser($sujet);

        if ($sujet === '') {
            return null;
        }

        return $reference === '' ? $sujet : $sujet . ' (' . $reference . ')';
    }

    /**
     * Les références législatives (article, amendement), extraites par
     * expression régulière du titre officiel.
     */
    public function references(string $titre): string
    {
        $parties = [];

        if (preg_match("/(?:^|\\W)(?:l['\x{2019}\x{0060}]?)?articles?\\s+(premier|1er|\\d+(?:\\s+(?:bis|ter|quater|quinquies|sexies|septies|octies|novies|decies))?)/iu", $titre, $m) === 1) {
            $valeur = mb_strtolower($m[1]);
            if (\in_array($valeur, ['premier', '1er'], true)) {
                $valeur = '1';
            }
            $parties[] = 'article ' . $valeur;
        }

        if (preg_match('/n[°o]\s*(\d+)/u', $titre, $m) === 1) {
            $parties[] = 'amendement ' . $m[1];
        }

        return implode(', ', $parties);
    }

    private function demanderSujet(string $titreOfficiel, ?string $dossier, int $budget): ?string
    {
        $consigne = "Voici le titre officiel d'un texte de loi ou d'un amendement de l'Assemblée nationale :\n"
            . '« ' . $titreOfficiel . ' »';
        if ($dossier !== null && trim($dossier) !== '') {
            $consigne .= "\nDossier législatif auquel il appartient : " . trim($dossier);
        }

        $consigne .= "\n\nRédige un titre court qui résume le sujet de ce texte : "
            . 'entre 3 et 8 mots, ' . $budget . ' caractères maximum, sans guillemets ni point final. '
            . "Ne mentionne ni le nom d'un auteur, ni le numéro d'un amendement ou d'un article, "
            . 'ni la phase de lecture : le code ajoute ces références après coup. '
            . "Le sujet doit se lire comme le titre d'un article de presse. "
            . "Réponds uniquement par le titre.";

        for ($essai = 0; $essai < 2; ++$essai) {
            try {
                $reponse = $this->ollama->chat(self::SYSTEME, $consigne, [
                    'temperature' => $essai === 0 ? 0.2 : 0,
                    'num_predict' => 90,
                ]);
            } catch (\Throwable) {
                return null;
            }

            $sujet = $this->nettoyer($reponse, $budget);
            if ($sujet !== null) {
                return $sujet;
            }

            $consigne .= "\nTa réponse précédente était inexploitable : elle devait faire "
                . $budget . ' caractères maximum et ne contenir aucune référence. '
                . "Réponds par le seul sujet, sans rien d'autre.";
        }

        return null;
    }

    private function nettoyer(string $reponse, int $budget): ?string
    {
        $sujet = trim($reponse);

        // Encadrement résiduel (« Titre : … », guillemets, listes Markdown).
        $sujet = (string) preg_replace('/^(?:titre|titre court|titre court proposé)\s*[:\-–]\s*/iu', '', $sujet);
        $sujet = (string) preg_replace('/^(?:#+\s*|\*\*|\*|\d+\.\s*|-\s*)/u', '', $sujet);
        $sujet = trim($sujet, " \t\n\r\0\x0B\"“”«»'’`*_");
        $sujet = rtrim($sujet, '.');
        $sujet = trim($sujet);

        if (mb_strlen($sujet) < 4 || mb_strlen($sujet) > $budget + 15) {
            return null;
        }

        $minuscules = mb_strtolower($sujet);
        foreach (self::BRUIT as $mot) {
            if (preg_match('/\\b' . preg_quote($mot, '/') . '/iu', $minuscules) === 1) {
                return null;
            }
        }

        return $sujet;
    }

    /**
     * Repli déterministe : on coupe le titre officiel au dernier marqueur
     * introduisant le sujet, puis on nettoie les mentions de lecture.
     */
    private function sujetParRegle(string $titre): ?string
    {
        $reste = (string) preg_replace(
            '/\s*\((?:première|nouvelle|seconde|deuxième)\s+lecture\)\.?|\s*\(examen prioritaire\)\.?|\s*\(rect(?:\.|ificatif)[^)]*\)\.?|\s*\(supprimé\)\.?/iu',
            '',
            $titre,
        );
        $reste = rtrim(trim($reste), '.;, ');

        $marqueurs = [
            'visant à ', 'visant la ', 'visant le ', 'visant les ', 'visant ',
            'relative à ', 'relatif à ', 'relative au ', 'relatif au ',
            'relative aux ', 'relatif aux ',
            'portant sur ', 'portant ', 'concernant ',
            'du projet de loi de ', 'de la proposition de loi de ',
            'du projet de loi portant ', 'de la proposition de loi visant ',
            'du projet de loi ', 'de la proposition de loi ',
        ];

        $position = -1;
        $longueur = 0;
        foreach ($marqueurs as $marqueur) {
            $trouve = mb_strripos($reste, $marqueur);
            if ($trouve !== false && $trouve > $position) {
                $position = $trouve;
                $longueur = mb_strlen($marqueur);
            }
        }

        if ($position < 0) {
            return null;
        }

        $sujet = trim(mb_substr($reste, $position + $longueur));
        $sujet = (string) preg_replace('/^(?:la |le |l\x27|les |un |une |des |du |de |d\x27)/iu', '', $sujet);

        return trim($sujet) === '' ? null : $sujet;
    }

    /**
     * Met une majuscule initiale : le modèle prolonge parfois la phrase du
     * titre officiel (« … visant à simplifier » → « simplifier … »).
     */
    private function capitaliser(string $sujet): string
    {
        $initiale = mb_substr($sujet, 0, 1);

        return mb_strtoupper($initiale) . mb_substr($sujet, 1);
    }

    /**
     * Coupe au niveau d'un mot entier pour respecter la longueur maximale.
     */
    private function ecreter(string $sujet, int $budget): string
    {
        if (mb_strlen($sujet) <= $budget) {
            return $sujet;
        }

        $tranche = mb_substr($sujet, 0, $budget);
        $espace = mb_strrpos($tranche, ' ');
        if ($espace !== false && $espace >= (int) ($budget * 0.5)) {
            $tranche = mb_substr($tranche, 0, $espace);
        }

        return rtrim($tranche, " \t,;:….-");
    }
}
