<?php

namespace App\Entity;

use App\Repository\TexteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TexteRepository::class)]
class Texte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * @var Collection<int, Vote>
     */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'texte', orphanRemoval: true)]
    private Collection $votes;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, Vote>
     */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(Vote $vote): static
    {
        if (!$this->votes->contains($vote)) {
            $this->votes->add($vote);
            $vote->setTexte($this);
        }

        return $this;
    }

    public function removeVote(Vote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            // set the owning side to null (unless already changed)
            if ($vote->getTexte() === $this) {
                $vote->setTexte(null);
            }
        }

        return $this;
    }

    public function getHemicyclePolitique(): array
    {
        $votesParGroupe = [];
        $totalVotes = 0;

        // 1. Groupement des votes
        foreach ($this->getVotes() as $vote) {
            $groupe = $vote->getGroupePolitique();
            if (!$groupe)
                continue;

            $uid = $groupe->getUid();

            if (!isset($votesParGroupe[$uid])) {
                $votesParGroupe[$uid] = [
                    'groupe' => $groupe,
                    'Pour' => 0,
                    'Contre' => 0,
                    'Abstention' => 0,
                    'total' => 0
                ];
            }

            $statut = $vote->getStatut();
            if (isset($votesParGroupe[$uid][$statut])) {
                $votesParGroupe[$uid][$statut]++;
                $votesParGroupe[$uid]['total']++;
                $totalVotes++;
            }
        }

        if ($totalVotes === 0)
            return ['total' => 0];

        // 2. Tri dynamique basé sur la colonne "ordre" de votre base de données
        usort($votesParGroupe, function ($a, $b) {
            return $a['groupe']->getOrdre() <=> $b['groupe']->getOrdre();
        });

        // 3. Calcul du CSS
        $degresActuels = 0;
        $gradientPartis = [];
        $gradientVotes = [];
        $details = [];

        foreach ($votesParGroupe as $data) {
            $groupe = $data['groupe'];
            $partDeg = ($data['total'] / $totalVotes) * 180;
            $finParti = $degresActuels + $partDeg;

            // La couleur vient de l'entité GroupePolitique
            $gradientPartis[] = "{$groupe->getCouleur()} {$degresActuels}deg {$finParti}deg";

            $degPour = ($data['Pour'] / $data['total']) * $partDeg;
            $degContre = ($data['Contre'] / $data['total']) * $partDeg;

            $finPour = $degresActuels + $degPour;
            $finContre = $finPour + $degContre;

            if ($degPour > 0)
                $gradientVotes[] = "#22c55e {$degresActuels}deg {$finPour}deg";
            if ($degContre > 0)
                $gradientVotes[] = "#ef4444 {$finPour}deg {$finContre}deg";
            if ($data['Abstention'] > 0)
                $gradientVotes[] = "#9ca3af {$finContre}deg {$finParti}deg";

            $degresActuels = $finParti;

            $details[] = [
                'nom' => $groupe->getAcronyme() ?? $groupe->getNom(),
                'couleur' => $groupe->getCouleur(),
                'Pour' => $data['Pour'],
                'Contre' => $data['Contre'],
                'Abstention' => $data['Abstention']
            ];
        }

        return [
            'total' => $totalVotes,
            'css_partis' => implode(', ', $gradientPartis),
            'css_votes' => implode(', ', $gradientVotes),
            'details' => $details
        ];
    }
}
