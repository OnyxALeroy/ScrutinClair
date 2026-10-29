<?php

namespace App\Enum;

enum Chambre: string
{
    case AssembleeNationale = 'AN';
    case Senat = 'SENAT';

    public function label(): string
    {
        return match ($this) {
            self::AssembleeNationale => 'Assemblée nationale',
            self::Senat => 'Sénat',
        };
    }

    public function libelleElu(): string
    {
        return match ($this) {
            self::AssembleeNationale => 'député',
            self::Senat => 'sénateur',
        };
    }

    public function libelleElus(): string
    {
        return match ($this) {
            self::AssembleeNationale => 'députés',
            self::Senat => 'sénateurs',
        };
    }

    public function article(): string
    {
        return match ($this) {
            self::AssembleeNationale => "l'Assemblée nationale",
            self::Senat => 'le Sénat',
        };
    }

    /**
     * @return self|null null pour « all »
     */
    public static function depuisFiltre(string $filtre): ?self
    {
        return match (mb_strtoupper(trim($filtre), 'UTF-8')) {
            'AN' => self::AssembleeNationale,
            'SENAT' => self::Senat,
            'ALL' => null,
            default => throw new \InvalidArgumentException(sprintf('Chambre inconnue : "%s". Valeurs acceptées : AN, SENAT, all.', $filtre)),
        };
    }
}
