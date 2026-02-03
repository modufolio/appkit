<?php

namespace Modufolio\Appkit\Attributes;

/**
 * Marks a property as containing sensitive data requiring protection.
 *
 * This attribute declares the data classification policy - actual enforcement
 * is handled by external systems (HSM/KMS, audit logging, access control).
 *
 * Classifications:
 * - public: No restrictions
 * - internal: Internal use only
 * - confidential: Business confidential
 * - secret: Highly sensitive (PII, financial)
 * - regulated: Subject to regulations (PCI, PSD2, KYC, AML)
 *
 * Protection types:
 * - encrypt: Must be encrypted at rest
 * - mask: Must be masked in UI/logs
 * - hash: One-way hash (passwords)
 * - none: Classification only, no protection required
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Sensitive
{
    public function __construct(
        public readonly string $classification = 'confidential',
        public readonly string $protection = 'encrypt',
        public readonly ?string $purpose = null,
        public readonly ?string $retention = null,
    ) {}
}
