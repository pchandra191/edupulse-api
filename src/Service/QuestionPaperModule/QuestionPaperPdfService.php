<?php
declare(strict_types=1);

namespace App\Service\QuestionPaperModule;

use App\DTO\QuestionPaperModule\QuestionPaperPreviewResponseDTO;
use Mpdf\Output\Destination;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

readonly class QuestionPaperPdfService
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function generatePdf(QuestionPaperPreviewResponseDTO $preview): string
    {
        try {
            $defaultConfig = (new ConfigVariables())->getDefaults();
            $fontDirs = $defaultConfig['fontDir'];

            $defaultFontConfig = (new FontVariables())->getDefaults();
            $fontData = $defaultFontConfig['fontdata'];

            $mpdf = new Mpdf([
                'tempDir' => sys_get_temp_dir(),
                'fontDir' => array_merge($fontDirs, [__DIR__ . '/../../../../var/fonts']),
                'fontdata' => $fontData + [
                    'dejavusans' => [
                        'R' => 'DejaVuSans.ttf',
                        'B' => 'DejaVuSans-Bold.ttf',
                        'I' => 'DejaVuSans-Oblique.ttf',
                        'BI' => 'DejaVuSans-BoldOblique.ttf',
                    ],
                ],
                'default_font' => 'dejavusans',
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 20,
                'margin_right' => 20,
                'margin_top' => 20,
                'margin_bottom' => 20,
            ]);

            $classNames = implode(', ', $preview->classNames);
            $sectionNames = !empty($preview->sectionNames) ? implode(', ', $preview->sectionNames) : 'N/A';

            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: dejavusans, Arial, sans-serif; font-size: 12px; }
        h1 { text-align: center; font-size: 18px; margin-bottom: 4px; }
        h2 { text-align: center; font-size: 16px; margin-top: 0; margin-bottom: 16px; }
        .meta { text-align: center; margin-bottom: 20px; font-size: 12px; }
        .info { margin-bottom: 20px; }
        .instructions { margin-bottom: 20px; padding: 10px; border: 1px solid #ccc; }
        .question { margin-bottom: 14px; page-break-inside: avoid; }
        .question-text { font-weight: bold; margin-bottom: 6px; }
        .options { margin-left: 20px; }
        .options li { margin-bottom: 4px; }
        .marks { color: #555; }
    </style>
</head>
<body>
    <h1>' . htmlspecialchars($preview->instituteName, ENT_QUOTES) . '</h1>
    <h2>' . htmlspecialchars($preview->title, ENT_QUOTES) . '</h2>
    <div class="meta">
        <p><strong>Subject:</strong> ' . htmlspecialchars($preview->subjectName, ENT_QUOTES) . ' | <strong>Class:</strong> ' . htmlspecialchars($classNames, ENT_QUOTES) . ' | <strong>Section:</strong> ' . htmlspecialchars($sectionNames, ENT_QUOTES) . '</p>
        <p><strong>Academic Year:</strong> ' . htmlspecialchars($preview->academicYearName, ENT_QUOTES) . ' | <strong>Date:</strong> ' . htmlspecialchars($preview->date, ENT_QUOTES) . ' | <strong>Duration:</strong> ' . $preview->duration . ' minutes | <strong>Max Marks:</strong> ' . $preview->maximumMarks . '</p>
    </div>
    ' . ($preview->instructions ? '<div class="instructions"><strong>Instructions:</strong><br>' . nl2br(htmlspecialchars($preview->instructions, ENT_QUOTES)) . '</div>' : '') . '
    <div class="questions">
        ' . $this->renderQuestions($preview->questions) . '
    </div>
</body>
</html>';

            $mpdf->WriteHTML($html);
            $mpdf->setTitle($preview->title);

            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate PDF.', [
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to generate PDF. Please try again.', previous: $e);
        }
    }

    private function renderQuestions(array $questions): string
    {
        $html = '';

        foreach ($questions as $question) {
            $html .= '<div class="question">';
            $html .= '<div class="question-text">Q' . $question->displayOrder . '. ' . htmlspecialchars($question->questionText, ENT_QUOTES) . ' <span class="marks">[' . $question->marks . ' marks]</span></div>';

            if ($question->type === 'MCQ' && !empty($question->options)) {
                $html .= '<div>Options:</div>';
                $html .= '<ol class="options" type="1">';
                foreach ($question->options as $option) {
                    $html .= '<li>' . htmlspecialchars($option->text, ENT_QUOTES) . '</li>';
                }
                $html .= '</ol>';
            }

            $html .= '</div>';
        }

        return $html;
    }
}
