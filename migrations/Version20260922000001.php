<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace academic *_id foreign keys with free-text columns for independent student academic details';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('user_profiles');

        $table->addColumn('academic_year', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('academic_class', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('section', 'string', ['length' => 50, 'notnull' => false]);

        $table->dropForeignKey('FK_USER_PROFILE_ACADEMIC_YEAR');
        $table->dropForeignKey('FK_USER_PROFILE_ACADEMIC_CLASS');
        $table->dropForeignKey('FK_USER_PROFILE_SECTION');

        $table->dropIndex('idx_user_profile_academic_year');
        $table->dropIndex('idx_user_profile_academic_class');
        $table->dropIndex('idx_user_profile_section');

        $table->dropColumn('academic_year_id');
        $table->dropColumn('academic_class_id');
        $table->dropColumn('section_id');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('user_profiles');

        $table->addColumn('academic_year_id', 'uuid', ['notnull' => false]);
        $table->addColumn('academic_class_id', 'uuid', ['notnull' => false]);
        $table->addColumn('section_id', 'uuid', ['notnull' => false]);

        $table->addForeignKeyConstraint('academic_years', 'academic_year_id', ['id'], ['onDelete' => 'SET NULL', 'localAddForeignKey' => true]);
        $table->addForeignKeyConstraint('classes', 'academic_class_id', ['id'], ['onDelete' => 'SET NULL', 'localAddForeignKey' => true]);
        $table->addForeignKeyConstraint('sections', 'section_id', ['id'], ['onDelete' => 'SET NULL', 'localAddForeignKey' => true]);

        $table->dropColumn('academic_year');
        $table->dropColumn('academic_class');
        $table->dropColumn('section');
    }
}