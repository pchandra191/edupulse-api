<?php
declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NoBase64ImageValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoBase64Image) {
            throw new UnexpectedTypeException($constraint, NoBase64Image::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value)) {
            return;
        }

        $trimmed = ltrim($value);
        if (stripos($trimmed, 'data:') === 0 && preg_match('/^data:[a-z0-9\-\+\.\/]+;base64,/i', $trimmed) === 1) {
            $this->context->buildViolation($constraint->message)
                ->setCode('NO_BASE64_IMAGE')
                ->addViolation();
        }
    }
}
