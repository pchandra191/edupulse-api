<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/config/bootstrap.php';
$container = $app->getContainer();
$em = $container->get('doctrine')->getManager();
$conn = $em->getConnection();

$tables = $conn->fetchAllAssociative("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
foreach ($tables as $t) {
    echo $t['tablename'] . PHP_EOL;
}

echo "\n--- Indexes on examinations ---\n";
$indexes = $conn->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'examinations'");
foreach ($indexes as $idx) {
    echo $idx['indexname'] . ": " . $idx['indexdef'] . PHP_EOL;
}

echo "\n--- Indexes on exam_subjects ---\n";
$indexes = $conn->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'exam_subjects'");
foreach ($indexes as $idx) {
    echo $idx['indexname'] . ": " . $idx['indexdef'] . PHP_EOL;
}

echo "\n--- Indexes on question_papers ---\n";
$indexes = $conn->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'question_papers'");
foreach ($indexes as $idx) {
    echo $idx['indexname'] . ": " . $idx['indexdef'] . PHP_EOL;
}

echo "\n--- Indexes on student_enrollments ---\n";
$indexes = $conn->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'student_enrollments'");
foreach ($indexes as $idx) {
    echo $idx['indexname'] . ": " . $idx['indexdef'] . PHP_EOL;
}

echo "\n--- Indexes on classes ---\n";
$indexes = $conn->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'classes'");
foreach ($indexes as $idx) {
    echo $idx['indexname'] . ": " . $idx['indexdef'] . PHP_EOL;
}

echo "\n--- Indexes on sections ---\n";
$indexes = $conn->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'sections'");
foreach ($indexes as $idx) {
    echo $idx['indexname'] . ": " . $idx['indexdef'] . PHP_EOL;
}

echo "\n--- Indexes on academic_years ---\n";
$indexes = $conn->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'academic_years'");
foreach ($indexes as $idx) {
    echo $idx['indexname'] . ": " . $idx['indexdef'] . PHP_EOL;
}
