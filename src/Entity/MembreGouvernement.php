<?php

namespace App\Entity;

use App\Repository\MembreGouvernementRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MembreGouvernementRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_gouvernement_personnalite', columns: ['gouvernement_id', 'personnalite_id'])]
class MembreGouvernement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'membres')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Gouvernement $gouvernement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PersonnalitePolitique $personnalite = null;

    #[ORM\Column(length: 100)]
    private ?string $role = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ministere = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(nullable: true)]
    private ?int $preseance = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGouvernement(): ?Gouvernement
    {
        return $this->gouvernement;
    }

    public function setGouvernement(?Gouvernement $gouvernement): static
    {
        $this->gouvernement = $gouvernement;

        return $this;
    }

    public function getPersonnalite(): ?PersonnalitePolitique
    {
        return $this->personnalite;
    }

    public function setPersonnalite(?PersonnalitePolitique $personnalite): static
    {
        $this->personnalite = $personnalite;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getMinistere(): ?string
    {
        return $this->ministere;
    }

    public function setMinistere(?string $ministere): static
    {
        $this->ministere = $ministere;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getPreseance(): ?int
    {
        return $this->preseance;
    }

    public function setPreseance(?int $preseance): static
    {
        $this->preseance = $preseance;

        return $this;
    }

    public function getCategorie(): string
    {
        $role = mb_strtolower((string) $this->role, 'UTF-8');

        if (str_contains($role, 'premier ministre')) {
            return 'Premier ministre';
        }
        if (str_contains($role, "ministre d'état") || str_contains($role, 'ministre d’état')) {
            return 'Ministres d’État';
        }
        if (str_contains($role, 'secrétaire d')) {
            return 'Secrétaires d’État';
        }
        if (str_contains($role, 'ministre délégué')) {
            return 'Ministres délégués';
        }

        return 'Ministres';
    }
}
