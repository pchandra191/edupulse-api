<?php
require __DIR__ . '/vendor/autoload.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();

$institute = new App\Entity\Institute();
$institute->setName('Debug Institute');
$institute->setCode('DEBUG');
$institute->setEmail('debug@edupulse.ai');
$institute->setStatus(App\Enum\InstituteStatus::Active);
$em->persist($institute);
$em->flush();

echo "Institute ID: " . $institute->getId() . "\n";
