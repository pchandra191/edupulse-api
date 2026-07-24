<?php

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\DependencyInjection\AsValidator;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

#[AsValidator]
class PasswordPolicyValidator extends \Symfony\Component\Validator\ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PasswordPolicy) {
            return;
        }

        if (null === $value || '' === $value) {
            return;
        }

        $minLength = 8;

        if (strlen($value) < $minLength) {
            $this->context->buildViolation($constraint->messageMinLength)
                ->setParameter('{{ limit }}', (string) $minLength)
                ->addViolation();
        }

        if (!preg_match('/[A-Z]/', $value)) {
            $this->context->buildViolation($constraint->messageUppercase)
                ->addViolation();
        }

        if (!preg_match('/[a-z]/', $value)) {
            $this->context->buildViolation($constraint->messageLowercase)
                ->addViolation();
        }

        if (!preg_match('/[0-9]/', $value)) {
            $this->context->buildViolation($constraint->messageNumber)
                ->addViolation();
        }

        if (!preg_match('/[^A-Za-z0-9]/', $value)) {
            $this->context->buildViolation($constraint->messageSpecialChar)
                ->addViolation();
        }
    }
}
