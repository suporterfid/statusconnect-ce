<?php

namespace App\Application\GoogleLogin;

/**
 * The identity Google vouched for in a verified ID token (#244).
 * Only these claims are kept: no profile picture, no other Google data.
 */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly string $name,
    ) {
    }

    /** @return array{sub: string, email: string, email_verified: bool, name: string} */
    public function toArray(): array
    {
        return ['sub' => $this->sub, 'email' => $this->email, 'email_verified' => $this->emailVerified, 'name' => $this->name];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['sub'], (string) $data['email'], (bool) $data['email_verified'], (string) $data['name']);
    }
}
