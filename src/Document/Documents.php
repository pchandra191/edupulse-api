<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\PersistentCollection;

#[ODM\EmbeddedDocument]
class Documents
{
    #[ODM\EmbedOne(targetDocument: DocumentFile::class)]
    private DocumentFile $questionPaper;

    /** @var DocumentFile[] */
    #[ODM\EmbedMany(targetDocument: DocumentFile::class)]
    private array|PersistentCollection $answerSheets = [];

    public function __construct()
    {
        $this->questionPaper = new DocumentFile();
    }

    public function getQuestionPaper(): DocumentFile
    {
        if ($this->questionPaper === null) {
            $this->questionPaper = new DocumentFile();
        }
        return $this->questionPaper;
    }

    public function setQuestionPaper(DocumentFile $questionPaper): self
    {
        $this->questionPaper = $questionPaper;
        return $this;
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