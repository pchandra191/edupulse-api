<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\PersistentCollection;

#[ODM\EmbeddedDocument]
class Documents
{
    /** @var DocumentFile[] */
    #[ODM\EmbedMany(targetDocument: DocumentFile::class)]
    private array|PersistentCollection $questionPapers = [];

    /** @var DocumentFile[] */
    #[ODM\EmbedMany(targetDocument: DocumentFile::class)]
    private array|PersistentCollection $answerSheets = [];

    // Legacy field for backward compatibility - loaded during hydration
    #[ODM\Field(type: 'hash', nullable: true)]
    private ?array $questionPaper = null;

    public function __construct()
    {
    }

    /** @return DocumentFile[] */
    public function getQuestionPapers(): array
    {
        // Migrate legacy questionPaper if present
        if ($this->questionPaper !== null && empty($this->questionPapers)) {
            $this->migrateLegacyQuestionPaper();
        }

        if ($this->questionPapers instanceof PersistentCollection) {
            return $this->questionPapers->toArray();
        }
        return $this->questionPapers;
    }

    public function setQuestionPapers(array|PersistentCollection $questionPapers): self
    {
        if ($questionPapers instanceof PersistentCollection) {
            $this->questionPapers = $questionPapers;
        } else {
            $this->questionPapers = $questionPapers;
        }
        return $this;
    }

    public function addQuestionPaper(DocumentFile $questionPaper): self
    {
        if ($this->questionPapers instanceof PersistentCollection) {
            $this->questionPapers->add($questionPaper);
        } else {
            $this->questionPapers[] = $questionPaper;
        }
        return $this;
    }

    public function removeQuestionPaper(int $index): self
    {
        $papers = $this->getQuestionPapers();
        if (isset($papers[$index])) {
            unset($papers[$index]);
            $this->questionPapers = array_values($papers);
        }
        return $this;
    }

    public function getQuestionPaperCount(): int
    {
        if ($this->questionPapers instanceof PersistentCollection) {
            return $this->questionPapers->count();
        }
        return count($this->questionPapers);
    }

    /**
     * Get the first question paper (for backward compatibility)
     */
    public function getQuestionPaper(): ?DocumentFile
    {
        $papers = $this->getQuestionPapers();
        return $papers[0] ?? null;
    }

    /**
     * Migrate legacy single questionPaper to questionPapers array
     */
    private function migrateLegacyQuestionPaper(): void
    {
        if ($this->questionPaper !== null && is_array($this->questionPaper) && $this->questionPaper['fileId'] ?? null) {
            $legacyPaper = new DocumentFile();
            $legacyPaper->setFileId($this->questionPaper['fileId'] ?? null);
            $legacyPaper->setStoredFilename($this->questionPaper['storedFilename'] ?? null);
            $legacyPaper->setOriginalFilename($this->questionPaper['originalFilename'] ?? null);
            $legacyPaper->setMimeType($this->questionPaper['mimeType'] ?? null);
            $legacyPaper->setFileSize($this->questionPaper['fileSize'] ?? null);
            $legacyPaper->setStatus($this->questionPaper['status'] ?? 'NOT_UPLOADED');
            $legacyPaper->setPage($this->questionPaper['page'] ?? null);
            $legacyPaper->setPageCount($this->questionPaper['pageCount'] ?? 1);

            $this->questionPapers = [$legacyPaper];
            $this->questionPaper = null;
        }
    }

    /** @return DocumentFile[] */
    public function getAnswerSheets(): array
    {
        if ($this->answerSheets instanceof PersistentCollection) {
            return $this->answerSheets->toArray();
        }
        return $this->answerSheets;
    }

    public function setAnswerSheets(array|PersistentCollection $answerSheets): self
    {
        if ($answerSheets instanceof PersistentCollection) {
            $this->answerSheets = $answerSheets;
        } else {
            $this->answerSheets = $answerSheets;
        }
        return $this;
    }

    public function addAnswerSheet(DocumentFile $answerSheet): self
    {
        if ($this->answerSheets instanceof PersistentCollection) {
            $this->answerSheets->add($answerSheet);
        } else {
            $this->answerSheets[] = $answerSheet;
        }
        return $this;
    }

    public function setAnswerSheetsArray(array $answerSheets): self
    {
        $this->answerSheets = $answerSheets;
        return $this;
    }

    public function getAnswerSheetCount(): int
    {
        if ($this->answerSheets instanceof PersistentCollection) {
            return $this->answerSheets->count();
        }
        return count($this->answerSheets);
    }

    /**
     * Get the first answer sheet (for backward compatibility)
     */
    public function getAnswerSheet(): ?DocumentFile
    {
        $sheets = $this->getAnswerSheets();
        return $sheets[0] ?? null;
    }

    /**
     * Remove an answer sheet by index
     */
    public function removeAnswerSheet(int $index): self
    {
        $sheets = $this->getAnswerSheets();
        if (isset($sheets[$index])) {
            unset($sheets[$index]);
            $this->answerSheets = array_values($sheets);
        }
        return $this;
    }
}