<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016094349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vote ADD groupe_politique_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vote ADD CONSTRAINT FK_5A108564897BF834 FOREIGN KEY (groupe_politique_id) REFERENCES groupe_politique (id)');
        $this->addSql('CREATE INDEX IDX_5A108564897BF834 ON vote (groupe_politique_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vote DROP CONSTRAINT FK_5A108564897BF834');
        $this->addSql('DROP INDEX IDX_5A108564897BF834');
        $this->addSql('ALTER TABLE vote DROP groupe_politique_id');
    }
}
