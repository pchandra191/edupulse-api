<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create holidays, leave_types, leave_requests, leave_balances, attendance_records, attendance_corrections, and attendance_policies tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE holidays (id UUID NOT NULL, institute_id UUID DEFAULT NULL, academic_year_id UUID DEFAULT NULL, holiday_date DATE NOT NULL, name VARCHAR(150) NOT NULL, description TEXT DEFAULT NULL, enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_holiday_institute_date ON holidays (institute_id, holiday_date)');
        $this->addSql('CREATE INDEX idx_holiday_institute ON holidays (institute_id)');
        $this->addSql('CREATE INDEX idx_holiday_academic_year ON holidays (academic_year_id)');
        $this->addSql('CREATE INDEX idx_holiday_date ON holidays (holiday_date)');
        $this->addSql('ALTER TABLE holidays ADD CONSTRAINT FK_HOLIDAY_INSTITUTE FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE holidays ADD CONSTRAINT FK_HOLIDAY_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE leave_types (id UUID NOT NULL, institute_id UUID DEFAULT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(30) NOT NULL, description TEXT DEFAULT NULL, enabled BOOLEAN NOT NULL, requires_approval BOOLEAN NOT NULL, maximum_days INT DEFAULT NULL, carry_forward BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_leave_type_institute_code ON leave_types (institute_id, code)');
        $this->addSql('CREATE INDEX idx_leave_type_enabled ON leave_types (enabled)');
        $this->addSql('ALTER TABLE leave_types ADD CONSTRAINT FK_LEAVE_TYPE_INSTITUTE FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE leave_requests (id UUID NOT NULL, institute_id UUID DEFAULT NULL, student_id UUID NOT NULL, academic_year_id UUID NOT NULL, academic_class_id UUID DEFAULT NULL, section_id UUID DEFAULT NULL, leave_type_id UUID NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, reason TEXT NOT NULL, attachment VARCHAR(500) DEFAULT NULL, status VARCHAR(20) NOT NULL, approved_by_id UUID DEFAULT NULL, approval_remarks TEXT DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancellation_reason TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_leave_request_student ON leave_requests (student_id)');
        $this->addSql('CREATE INDEX idx_leave_request_status ON leave_requests (status)');
        $this->addSql('CREATE INDEX idx_leave_request_dates ON leave_requests (start_date, end_date)');
        $this->addSql('CREATE INDEX idx_leave_request_institute ON leave_requests (institute_id)');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_LEAVE_REQUEST_INSTITUTE FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_LEAVE_REQUEST_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_LEAVE_REQUEST_STUDENT FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_LEAVE_REQUEST_ACADEMIC_CLASS FOREIGN KEY (academic_class_id) REFERENCES classes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_LEAVE_REQUEST_SECTION FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_LEAVE_REQUEST_LEAVE_TYPE FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_LEAVE_REQUEST_APPROVED_BY FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE leave_balances (id UUID NOT NULL, institute_id UUID DEFAULT NULL, student_id UUID NOT NULL, academic_year_id UUID NOT NULL, leave_type_id UUID NOT NULL, allocated INT NOT NULL, used INT NOT NULL, pending INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_leave_balance_student_year_type ON leave_balances (student_id, academic_year_id, leave_type_id)');
        $this->addSql('CREATE INDEX idx_leave_balance_institute ON leave_balances (institute_id)');
        $this->addSql('CREATE INDEX idx_leave_balance_student ON leave_balances (student_id)');
        $this->addSql('ALTER TABLE leave_balances ADD CONSTRAINT FK_LEAVE_BALANCE_INSTITUTE FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_balances ADD CONSTRAINT FK_LEAVE_BALANCE_STUDENT FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_balances ADD CONSTRAINT FK_LEAVE_BALANCE_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leave_balances ADD CONSTRAINT FK_LEAVE_BALANCE_LEAVE_TYPE FOREIGN KEY (leave_type_id) REFERENCES leave_types (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE attendance_records (id UUID NOT NULL, institute_id UUID DEFAULT NULL, academic_year_id UUID NOT NULL, student_id UUID NOT NULL, academic_class_id UUID NOT NULL, section_id UUID DEFAULT NULL, attendance_date DATE NOT NULL, status VARCHAR(20) NOT NULL, marked_by_id UUID DEFAULT NULL, marked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, remarks TEXT DEFAULT NULL, source VARCHAR(20) NOT NULL, leave_request_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_attendance_date ON attendance_records (attendance_date)');
        $this->addSql('CREATE INDEX idx_attendance_student ON attendance_records (student_id)');
        $this->addSql('CREATE INDEX idx_attendance_institute ON attendance_records (institute_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_attendance_student_date_class ON attendance_records (student_id, attendance_date, academic_class_id, section_id)');
        $this->addSql('ALTER TABLE attendance_records ADD CONSTRAINT FK_ATTENDANCE_INSTITUTE FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_records ADD CONSTRAINT FK_ATTENDANCE_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_records ADD CONSTRAINT FK_ATTENDANCE_STUDENT FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_records ADD CONSTRAINT FK_ATTENDANCE_ACADEMIC_CLASS FOREIGN KEY (academic_class_id) REFERENCES classes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_records ADD CONSTRAINT FK_ATTENDANCE_SECTION FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_records ADD CONSTRAINT FK_ATTENDANCE_MARKED_BY FOREIGN KEY (marked_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_records ADD CONSTRAINT FK_ATTENDANCE_LEAVE_REQUEST FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE attendance_corrections (id UUID NOT NULL, institute_id UUID DEFAULT NULL, academic_year_id UUID NOT NULL, attendance_record_id UUID NOT NULL, student_id UUID NOT NULL, academic_class_id UUID DEFAULT NULL, section_id UUID DEFAULT NULL, original_status VARCHAR(20) NOT NULL, corrected_status VARCHAR(20) NOT NULL, reason TEXT NOT NULL, requested_by_id UUID DEFAULT NULL, approved_by_id UUID DEFAULT NULL, status VARCHAR(20) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, remarks TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_correction_status ON attendance_corrections (status)');
        $this->addSql('CREATE INDEX idx_correction_attendance_record ON attendance_corrections (attendance_record_id)');
        $this->addSql('CREATE INDEX idx_correction_student ON attendance_corrections (student_id)');
        $this->addSql('CREATE INDEX idx_correction_institute ON attendance_corrections (institute_id)');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_INSTITUTE FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_RECORD FOREIGN KEY (attendance_record_id) REFERENCES attendance_records (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_STUDENT FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_ACADEMIC_CLASS FOREIGN KEY (academic_class_id) REFERENCES classes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_SECTION FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_REQUESTED_BY FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_corrections ADD CONSTRAINT FK_ATTENDANCE_CORRECTION_APPROVED_BY FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE attendance_policies (id UUID NOT NULL, institute_id UUID DEFAULT NULL, academic_year_id UUID DEFAULT NULL, minimum_attendance_percentage INT NOT NULL, late_threshold_count INT DEFAULT NULL, half_day_behavior VARCHAR(50) DEFAULT NULL, approved_leave_counts BOOLEAN NOT NULL, working_days VARCHAR(50) DEFAULT NULL, is_active BOOLEAN NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_attendance_policy_institute_year ON attendance_policies (institute_id, academic_year_id)');
        $this->addSql('CREATE INDEX idx_attendance_policy_institute ON attendance_policies (institute_id)');
        $this->addSql('ALTER TABLE attendance_policies ADD CONSTRAINT FK_ATTENDANCE_POLICY_INSTITUTE FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attendance_policies ADD CONSTRAINT FK_ATTENDANCE_POLICY_ACADEMIC_YEAR FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('attendance_policies');
        $schema->dropTable('attendance_corrections');
        $schema->dropTable('attendance_records');
        $schema->dropTable('leave_balances');
        $schema->dropTable('leave_requests');
        $schema->dropTable('leave_types');
        $schema->dropTable('holidays');
    }
}
