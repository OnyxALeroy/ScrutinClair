<?php

namespace App\Service;

final class SenatSqlDumpParser
{
    private const TABLES = ['sen', 'grppol', 'memgrppol', 'sennom'];

    /**
     * @return array<string, list<array<string, ?string>>>
     */
    public function parse(string $sql): array
    {
        $flux = fopen('php://temp', 'r+');
        if ($flux === false) {
            throw new \RuntimeException('Impossible de créer le flux temporaire.');
        }
        fwrite($flux, $sql);
        rewind($flux);
        $resultat = $this->parseStream($flux);
        fclose($flux);

        return $resultat;
    }

    /**
     * @param resource $flux
     *
     * @return array<string, list<array<string, ?string>>>
     */
    public function parseStream($flux): array
    {
        $resultat = array_fill_keys(self::TABLES, []);
        $table = null;
        $colonnes = [];

        while (($ligne = fgets($flux)) !== false) {
            $ligne = rtrim($ligne, "\r\n");
            if ($table === null) {
                if (preg_match('/^COPY (\w+) \((.+)\) FROM stdin;$/', $ligne, $correspondances) === 1) {
                    $nomTable = $correspondances[1];
                    if (\in_array($nomTable, self::TABLES, true)) {
                        $table = $nomTable;
                        $colonnes = array_map('trim', explode(',', $correspondances[2]));
                    }
                }

                continue;
            }

            if ($ligne === '\.') {
                $table = null;
                $colonnes = [];

                continue;
            }

            $valeurs = explode("\t", $ligne);
            $ligneAssociee = [];
            foreach ($colonnes as $index => $colonne) {
                $valeur = $valeurs[$index] ?? null;
                $ligneAssociee[$colonne] = $valeur === '\N' ? null : $valeur;
            }

            $resultat[$table][] = $ligneAssociee;
        }

        return $resultat;
    }
}
