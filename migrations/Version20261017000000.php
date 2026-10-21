<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create study_tasks table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE study_tasks (id UUID NOT NULL, student_id UUID NOT NULL, ai_recommendation_key VARCHAR(180) DEFAULT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, subject VARCHAR(180) DEFAULT NULL, topic VARCHAR(255) DEFAULT NULL, priority VARCHAR(20) NOT NULL, estimated_minutes INTEGER DEFAULT NULL, due_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_completed BOOLEAN NOT NULL, is_ai_recommended BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_study_task_student ON study_tasks (student_id)');
        $this->addSql('CREATE INDEX idx_study_task_key ON study_tasks (ai_recommendation_key)');
        $this->addSql('CREATE UNIQUE INDEX uniq_study_task_student_key ON study_tasks (student_id, ai_recommendation_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE study_tasks');
    }
}
