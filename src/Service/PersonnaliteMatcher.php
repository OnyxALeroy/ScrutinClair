<?php

namespace App\Service;

use App\Entity\PersonnalitePolitique;

final class PersonnaliteMatcher
{
    public function cle(string $nom, string $prenom, ?\DateTimeImmutable $dateNaissance): string
    {
        return implode('|', [
            $this->normaliser($nom),
            $this->normaliser($prenom),
            $dateNaissance?->format('Y-m-d') ?? '',
        ]);
    }

    public function cleSenateur(array $senateur): string
    {
        return $this->cle(
            (string) ($senateur['sennomuse'] ?? ''),
            (string) ($senateur['senprenomuse'] ?? ''),
            SenatDataProvider::date($senateur['sendatnai'] ?? null),
        );
    }

    public function clePersonnalite(PersonnalitePolitique $personnalite): string
    {
        return $this->cle(
            (string) $personnalite->getNom(),
            (string) $personnalite->getPrenom(),
            $personnalite->getDateNaissance(),
        );
    }

    private function normaliser(string $valeur): string
    {
        $valeur = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valeur) ?: $valeur;
        $valeur = str_replace(["'", '’'], '', strtolower($valeur));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $valeur));
    }
}
