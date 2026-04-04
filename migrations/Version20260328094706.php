<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260328094706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE anamnesis (id INT AUTO_INCREMENT NOT NULL, data JSON NOT NULL, updated_at DATETIME DEFAULT NULL, is_complete TINYINT NOT NULL, patient_id INT NOT NULL, INDEX IDX_F0A580696B899279 (patient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE questionnaire (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(100) DEFAULT NULL, questions JSON NOT NULL, is_active TINYINT NOT NULL, UNIQUE INDEX UNIQ_7A64DAF989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE questionnaire_response (id INT AUTO_INCREMENT NOT NULL, answers JSON NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, is_complete TINYINT NOT NULL, score DOUBLE PRECISION DEFAULT NULL, patient_id INT NOT NULL, questionnaire_id INT NOT NULL, INDEX IDX_A04002766B899279 (patient_id), INDEX IDX_A0400276CE07E8FF (questionnaire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, age INT DEFAULT NULL, gender VARCHAR(10) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE anamnesis ADD CONSTRAINT FK_F0A580696B899279 FOREIGN KEY (patient_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE questionnaire_response ADD CONSTRAINT FK_A04002766B899279 FOREIGN KEY (patient_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE questionnaire_response ADD CONSTRAINT FK_A0400276CE07E8FF FOREIGN KEY (questionnaire_id) REFERENCES questionnaire (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE anamnesis DROP FOREIGN KEY FK_F0A580696B899279');
        $this->addSql('ALTER TABLE questionnaire_response DROP FOREIGN KEY FK_A04002766B899279');
        $this->addSql('ALTER TABLE questionnaire_response DROP FOREIGN KEY FK_A0400276CE07E8FF');
        $this->addSql('DROP TABLE anamnesis');
        $this->addSql('DROP TABLE questionnaire');
        $this->addSql('DROP TABLE questionnaire_response');
        $this->addSql('DROP TABLE `user`');
    }
}
