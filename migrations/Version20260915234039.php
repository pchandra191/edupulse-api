<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915234039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE holidays ADD academic_class_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE holidays ADD section_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE holidays ADD CONSTRAINT FK_3A66A10CD5308307 FOREIGN KEY (academic_class_id) REFERENCES classes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE holidays ADD CONSTRAINT FK_3A66A10CD823E37A FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3A66A10CD5308307 ON holidays (academic_class_id)');
        $this->addSql('CREATE INDEX IDX_3A66A10CD823E37A ON holidays (section_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE holidays DROP CONSTRAINT FK_3A66A10CD5308307');
        $this->addSql('ALTER TABLE holidays DROP CONSTRAINT FK_3A66A10CD823E37A');
        $this->addSql('DROP INDEX IDX_3A66A10CD5308307');
        $this->addSql('DROP INDEX IDX_3A66A10CD823E37A');
        $this->addSql('ALTER TABLE holidays DROP academic_class_id');
        $this->addSql('ALTER TABLE holidays DROP section_id');
    }
}
