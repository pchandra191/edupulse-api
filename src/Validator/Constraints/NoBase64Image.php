<?php
declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects base64 data URIs (e.g. data:image/jpeg;base64,...) in URL-like fields.
 *
 * Base64 data URIs must not be stored in fields that represent external HTTP(S) URLs
 * (e.g. Question.imageUrl). Uploaded binary content must be transported via a
 * dedicated multipart upload endpoint and stored as a file/media reference.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
class NoBase64Image extends Constraint
{
    public string $message = 'This value must not be a base64 data URI. Use the file upload endpoint to attach images.';

    public function validatedBy(): string
    {
        return NoBase64ImageValidator::class;
    }
}
