<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use Mpdf\Mpdf;

$mpdf = new Mpdf();
$content = <<<TEXT
Sample Science Answer Sheet
Unit Test Examination · Class 8 · Section C
Student Name Aarav Kumar Roll Number 24
Subject Science Total Marks 30
Exam Unit Test Date 24 September 2026

Question 1 (5 marks)
Solve: 3x + 7 = 22.
Answer / Working:
Working: 3x = 15, therefore x = 5.

Question 2 (5 marks)
Find the area of a rectangle whose length is 12 cm and breadth is 7 cm.
Answer / Working:
Area = length × breadth = 12 × 7 = 84 cm².

Question 3 (5 marks)
Simplify: 3/4 + 2/5.
Answer / Working:
LCM of 4 and 5 is 20. 15/20 + 8/20 = 23/20 = 1 3/20.

Question 4 (5 marks)
A shopkeeper buys an item for ₹800 and sells it for ₹920. Find the profit percentage.
Answer / Working:
Profit = ₹920 − ₹800 = ₹120. Profit % = 120/800 × 100 = 15%.

Question 5 (5 marks)
The angles of a triangle are 50° and 60°. Find the third angle.
Answer / Working:
Sum of angles = 180°. Third angle = 180° - 50° - 60° = 70°.

End of Answer Sheet
TEXT;

$mpdf->WriteHTML($content);
$mpdf->Output(__DIR__ . '/answer-sheet.pdf', 'F');

echo "PDF created successfully\n";