<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

#[ODM\EmbeddedDocument]
class Documents
{
    #[ODM\EmbedOne(targetDocument: DocumentFile::class)]
    private DocumentFile $questionPaper;

    #[ODM\EmbedOne(targetDocument: DocumentFile::class)]
    private DocumentFile $answerSheet;

    public function __construct()
    {
        $this->questionPaper = new DocumentFile();
        $this->answerSheet = new DocumentFile();
    }

    public function getQuestionPaper(): DocumentFile
    {
        return $this->questionPaper;
    }

    public function setQuestionPaper(DocumentFile $questionPaper): self
    {
        $this->questionPaper = $questionPaper;
        return $this;
    }

    public function getAnswerSheet(): DocumentFile
    {
        return $this->answerSheet;
    }

    public function setAnswerSheet(DocumentFile $answerSheet): self
    {
        $this->answerSheet = $answerSheet;
        return $this;
    }
}