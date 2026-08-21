<?php
declare(strict_types=1);

namespace App\Workflow;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Stores a workflow marking backed by a backed enum property on the subject.
 *
 * The existing EduPulse entities keep their lifecycle state in a string-backed
 * enum (e.g. ExaminationStatus, QuestionPaperStatus) exposed via a typed
 * getter/setter. Symfony's built-in MethodMarkingStore expects a raw string,
 * so this store translates between the enum instance and its string value,
 * which is used directly as the workflow place name.
 */
class EnumMarkingStore implements MarkingStoreInterface
{
    public function __construct(
        private string $property,
        private string $enumClass,
    ) {
    }

    public function getMarking(object $subject): Marking
    {
        $enum = $subject->{'get' . ucfirst($this->property)}();

        if ($enum === null) {
            return new Marking();
        }

        return new Marking([$enum->value => 1]);
    }

    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $place = array_key_first($marking->getPlaces());
        $enum = $this->enumClass::from($place);

        $subject->{'set' . ucfirst($this->property)}($enum);
    }
}
