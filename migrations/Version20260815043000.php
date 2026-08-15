<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815043000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add onboarding_completed_at to institutes table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutes ADD onboarding_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutes DROP onboarding_completed_at');
    }
}
