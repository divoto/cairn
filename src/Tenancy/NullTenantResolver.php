<?php

declare(strict_types=1);

namespace Divoto\Cairn\Tenancy;

use Divoto\Cairn\Contracts\TenantResolver;

/**
 * A tenant resolver for single-tenant installations.
 *
 * This is the shipped default: returning null means every entry and every
 * report is unscoped, and the `tenant_id` columns stay empty.
 */
final class NullTenantResolver implements TenantResolver
{
    public function resolve(): int|string|null
    {
        return null;
    }
}
