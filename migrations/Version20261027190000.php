<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add log_preview column to import_job';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_job ADD log_preview TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_job DROP log_preview');
    }
}
