<?php
require __DIR__ . '/vendor/autoload.php';
use App\Service\Analysis\Processing\MeaningfulTextDetector;
use Psr\Log\NullLogger;

$detector = new MeaningfulTextDetector(new NullLogger());

// Test 1: Meaningful typed answer sheet
$pages1 = [
    ['page' => 1, 'text' => "Question 1\nWhat is the capital of France?\nAnswer: Paris is the capital city of France.\n\nQuestion 2\nExplain photosynthesis.\nAnswer: Photosynthesis is the process by which plants convert sunlight into energy."],
    ['page' => 2, 'text' => "Question 3\nSolve for x: 2x + 5 = 15\nAnswer: x = 5"],
];
$result1 = $detector->analyze($pages1);
echo "Test 1 - Meaningful typed: " . $result1['decision']->value . " - " . $result1['reason'] . "\n";
foreach ($result1['pageAnalysis'] as $p) {
    echo "  Page " . $p['page'] . ": meaningful=" . $p['meaningfulTextLength'] . " has=" . ($p['hasMeaningfulContent']?'Y':'N') . " reason=" . ($p['rejectionReason'] ?? 'none') . "\n";
}

// Test 2: Empty PDF
$pages2 = [
    ['page' => 1, 'text' => ''],
    ['page' => 2, 'text' => ''],
];
$result2 = $detector->analyze($pages2);
echo "\nTest 2 - Empty: " . $result2['decision']->value . " - " . $result2['reason'] . "\n";
foreach ($result2['pageAnalysis'] as $p) {
    echo "  Page " . $p['page'] . ": meaningful=" . $p['meaningfulTextLength'] . " has=" . ($p['hasMeaningfulContent']?'Y':'N') . " reason=" . ($p['rejectionReason'] ?? 'none') . "\n";
}

// Test 3: Scanned PDF
$pages3 = [
    ['page' => 1, 'text' => "\x00\x01\x02\x03"],
    ['page' => 2, 'text' => ""],
    ['page' => 3, 'text' => " "],
];
$result3 = $detector->analyze($pages3);
echo "\nTest 3 - Scanned: " . $result3['decision']->value . " - " . $result3['reason'] . "\n";
foreach ($result3['pageAnalysis'] as $p) {
    echo "  Page " . $p['page'] . ": meaningful=" . $p['meaningfulTextLength'] . " has=" . ($p['hasMeaningfulContent']?'Y':'N') . " reason=" . ($p['rejectionReason'] ?? 'none') . "\n";
}

// Test 4: Header-only PDF
$pages4 = [
    ['page' => 1, 'text' => "School Name\nClass: 10\nSection: A\nRoll Number:\nSubject: Mathematics\nDate: 2024-01-15\n\n"],
    ['page' => 2, 'text' => "School Name\nClass: 10\nSection: A\nRoll Number:\nSubject: Mathematics\nDate: 2024-01-15\n\n"],
];
$result4 = $detector->analyze($pages4);
echo "\nTest 4 - Header-only: " . $result4['decision']->value . " - " . $result4['reason'] . "\n";
foreach ($result4['pageAnalysis'] as $p) {
    echo "  Page " . $p['page'] . ": meaningful=" . $p['meaningfulTextLength'] . " has=" . ($p['hasMeaningfulContent']?'Y':'N') . " reason=" . ($p['rejectionReason'] ?? 'none') . "\n";
}

// Test 5: Marks-only PDF
$pages5 = [
    ['page' => 1, 'text' => "Question 1 (5 marks)\nQuestion 2 (3 marks)\nQuestion 3 (10 marks)\nQuestion 4 (2 marks)\nTotal: 20 marks"],
    ['page' => 2, 'text' => "Question 5 (4 marks)\nQuestion 6 (6 marks)\n(2 marks)"],
];
$result5 = $detector->analyze($pages5);
echo "\nTest 5 - Marks-only: " . $result5['decision']->value . " - " . $result5['reason'] . "\n";
foreach ($result5['pageAnalysis'] as $p) {
    echo "  Page " . $p['page'] . ": meaningful=" . $p['meaningfulTextLength'] . " has=" . ($p['hasMeaningfulContent']?'Y':'N') . " reason=" . ($p['rejectionReason'] ?? 'none') . "\n";
}

// Test 6: Mixed meaningful/insignificant
$pages6 = [
    ['page' => 1, 'text' => "School Header\nPage 1\n\nQuestion 1 (5 marks)\nAnswer: The mitochondria is the powerhouse of the cell.\n\nQuestion 2 (3 marks)\nAnswer: Photosynthesis converts CO2 and water into glucose.\n\nFooter text"],
    ['page' => 2, 'text' => "Page 2\n\nQuestion 3 (2 marks)\nAnswer: \n\nQuestion 4 (5 marks)\nAnswer: The capital of Japan is Tokyo.\n\n(2 marks)\nEnd of paper"],
];
$result6 = $detector->analyze($pages6);
echo "\nTest 6 - Mixed: " . $result6['decision']->value . " - " . $result6['reason'] . "\n";
foreach ($result6['pageAnalysis'] as $p) {
    echo "  Page " . $p['page'] . ": meaningful=" . $p['meaningfulTextLength'] . " has=" . ($p['hasMeaningfulContent']?'Y':'N') . " reason=" . ($p['rejectionReason'] ?? 'none') . "\n";
}