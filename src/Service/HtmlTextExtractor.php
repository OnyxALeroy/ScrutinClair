<?php

namespace App\Service;

/**
 * Convertit un document HTML (notamment les textes de loi publiés
 * sur /dyn/opendata/) en texte brut exploitable par le modèle.
 */
final class HtmlTextExtractor
{
    public function extract(string $html): string
    {
        // 1. On retire les blocs non textuels (CSS, JS, commentaires, en-tête).
        $html = preg_replace('#<head\b[^>]*>.*?</head>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;

        // 2. Les balises de bloc deviennent des sauts de ligne pour préserver la structure.
        $html = preg_replace(
            '#<(?:br|/p|/div|/li|/tr|/h[1-6]|/section|/article)\b[^>]*>#i',
            "\n",
            $html
        ) ?? $html;

        // 3. Suppression des balises restantes puis décodage des entités.
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 4. Normalisation des espaces : insécables, tabulations, lignes vides multiples.
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\t"], ' ', $text);
        $text = preg_replace('/[ ]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
