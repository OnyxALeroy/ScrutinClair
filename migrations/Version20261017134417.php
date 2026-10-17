<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017134417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE texte ADD objet TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE texte ADD synthese TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE texte ADD lien VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE texte ADD dossier_ref VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE texte ADD uid_scrutin VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EAE1A6EE8557595B ON texte (uid_scrutin)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_EAE1A6EE8557595B');
        $this->addSql('ALTER TABLE texte DROP objet');
        $this->addSql('ALTER TABLE texte DROP synthese');
        $this->addSql('ALTER TABLE texte DROP lien');
        $this->addSql('ALTER TABLE texte DROP dossier_ref');
        $this->addSql('ALTER TABLE texte DROP uid_scrutin');
    }
}
