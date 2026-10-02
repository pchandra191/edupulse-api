<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260922165736 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE analyses (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, analysis_id VARCHAR(36) NOT NULL, source VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, student_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AC86883C7941003F ON analyses (analysis_id)');
        $this->addSql('CREATE INDEX idx_analysis_student ON analyses (student_id)');
        $this->addSql('CREATE INDEX idx_analysis_status ON analyses (status)');
        $this->addSql('CREATE INDEX idx_analysis_source ON analyses (source)');
        $this->addSql('CREATE INDEX idx_analysis_created_at ON analyses (created_at)');
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_AC86883CCB944F1A FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT FK_AC86883CCB944F1A');
        $this->addSql('DROP TABLE analyses');
    }
}
