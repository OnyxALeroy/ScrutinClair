<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SenatDataProvider
{
    private ?array $donnees = null;
    private ?array $appartenancesParMatricule = null;

    public function __construct(
        private readonly ResilientDownloader $downloader,
        private readonly SenatSqlDumpParser $parser,
        private readonly SenatUrls $urls,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, list<array<string, ?string>>>
     */
    public function donnees(): array
    {
        if ($this->donnees !== null) {
            return $this->donnees;
        }

        $repertoire = $this->projectDir . '/data/senat';
        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException('Impossible de créer le répertoire ' . $repertoire);
        }

        $archive = $repertoire . '/export_sens.zip';
        $cache = $repertoire . '/export_sens.json';

        if (!is_file($archive) || filemtime($archive) < time() - 604800) {
            try {
                $this->downloader->download($this->urls->senateursZipUrl(), $archive);
            } catch (\Throwable $exception) {
                if (!is_file($archive)) {
                    throw $exception;
                }
            }
        }

        if (is_file($cache) && filemtime($cache) >= filemtime($archive)) {
            $contenu = file_get_contents($cache);
            $donnees = $contenu !== false ? json_decode($contenu, true) : null;
            if (\is_array($donnees)) {
                return $this->donnees = $donnees;
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('Impossible d’ouvrir ' . $archive);
        }

        $flux = $zip->getStream('export_sens.sql');
        if ($flux === false) {
            $zip->close();
            throw new \RuntimeException('export_sens.sql est absent de l’archive Sénat.');
        }

        $donnees = $this->parser->parseStream($flux);
        fclose($flux);
        $zip->close();
        file_put_contents($cache, json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $this->donnees = $donnees;
    }

    /**
     * @return list<array<string, ?string>>
     */
    public function senateurs(): array
    {
        return $this->donnees()['sen'];
    }

    /**
     * @return array<string, array<string, ?string>>
     */
    public function groupes(): array
    {
        $resultat = [];
        foreach ($this->donnees()['grppol'] as $groupe) {
            $code = $groupe['grppolcod'] ?? null;
            if ($code !== null) {
                $resultat[$code] = $groupe;
            }
        }

        return $resultat;
    }

    public function senateur(string $matricule): ?array
    {
        foreach ($this->senateurs() as $senateur) {
            if (($senateur['senmat'] ?? null) === $matricule) {
                return $senateur;
            }
        }

        return null;
    }

    public function groupePour(string $matricule, \DateTimeImmutable $date): ?string
    {
        $jour = $date->format('Y-m-d');
        $meilleur = null;
        $meilleurDebut = null;

        foreach ($this->appartenances()[$matricule] ?? [] as $appartenance) {
            $debut = $appartenance['debut'];
            $fin = $appartenance['fin'];
            if ($debut !== null && $debut > $jour) {
                continue;
            }
            if ($fin !== null && $fin < $jour) {
                continue;
            }

            if ($meilleurDebut === null || ($debut ?? '') > $meilleurDebut
                || (($debut ?? '') === $meilleurDebut && $this->priorite($appartenance) > $this->priorite($meilleur))) {
                $meilleur = $appartenance;
                $meilleurDebut = $debut ?? '';
            }
        }

        return $meilleur['code'] ?? null;
    }

    /**
     * @param array{code: string, debut: ?string, fin: ?string, type: ?string} $appartenance
     */
    private function priorite(array $appartenance): int
    {
        return match ($appartenance['type'] ?? null) {
            'N' => 3,
            'R' => 2,
            default => 1,
        };
    }

    public function dernierGroupe(string $matricule): ?string
    {
        $dernier = null;
        $dernierDebut = null;
        foreach ($this->appartenances()[$matricule] ?? [] as $appartenance) {
            if ($dernierDebut === null || ($appartenance['debut'] ?? '') > $dernierDebut) {
                $dernier = $appartenance['code'];
                $dernierDebut = $appartenance['debut'] ?? '';
            }
        }

        return $dernier;
    }

    public function photoUrl(array $senateur): ?string
    {
        $nom = $senateur['sennomuse'] ?? null;
        $prenom = $senateur['senprenomuse'] ?? null;
        $matricule = $senateur['senmat'] ?? null;
        if ($nom === null || $prenom === null || $matricule === null) {
            return null;
        }

        return $this->urls->photoUrl($nom, $prenom, $matricule);
    }

    public static function date(?string $valeur): ?\DateTimeImmutable
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(substr($valeur, 0, 10));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, list<array{code: string, debut: ?string, fin: ?string, type: ?string}>>
     */
    private function appartenances(): array
    {
        if ($this->appartenancesParMatricule !== null) {
            return $this->appartenancesParMatricule;
        }

        $groupes = $this->groupes();
        $resultat = [];
        foreach ($this->donnees()['memgrppol'] as $appartenance) {
            $matricule = $appartenance['senmat'] ?? null;
            $code = $appartenance['grppolcod'] ?? null;
            if ($matricule === null || $code === null || !isset($groupes[$code])) {
                continue;
            }
            if (\in_array($code, ['AUCUN', 'AUCUNSEN'], true)) {
                continue;
            }

            $resultat[$matricule][] = [
                'code' => $code,
                'debut' => self::date($appartenance['memgrppoldatdeb'] ?? null)?->format('Y-m-d'),
                'fin' => self::date($appartenance['memgrppoldatfin'] ?? null)?->format('Y-m-d'),
                'type' => $appartenance['typapppolcod'] ?? null,
            ];
        }

        return $this->appartenancesParMatricule = $resultat;
    }
}
