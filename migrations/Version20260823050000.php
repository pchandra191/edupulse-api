<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill academic_year_id, academic_class_id and exam_session_sections for existing ExamSessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE exam_sessions es
            SET academic_year_id = e.academic_year_id,
                academic_class_id = e.academic_class_id
            FROM examinations e
            WHERE es.examination_id = e.id
              AND es.academic_year_id IS NULL
        SQL);

        $this->addSql(<<<SQL
            UPDATE exam_sessions es
            SET academic_year_id = qp.academic_year_id,
                academic_class_id = (SELECT qpc.class_id FROM question_paper_classes qpc WHERE qpc.question_paper_id = qp.id LIMIT 1)
            FROM question_papers qp
            WHERE es.question_paper_id = qp.id
              AND es.examination_id IS NULL
              AND es.academic_year_id IS NULL
        SQL);

        $this->addSql(<<<SQL
            INSERT INTO exam_session_sections (exam_session_id, section_id)
            SELECT es.id, s.id
            FROM exam_sessions es
            INNER JOIN sections s ON s.academic_class_id = es.academic_class_id
            WHERE es.academic_year_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM exam_session_sections ess WHERE ess.exam_session_id = es.id
              )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM exam_session_sections WHERE exam_session_id IN (SELECT id FROM exam_sessions WHERE examination_id IS NOT NULL)');
        $this->addSql('UPDATE exam_sessions SET academic_year_id = NULL, academic_class_id = NULL WHERE examination_id IS NOT NULL');
    }
}
