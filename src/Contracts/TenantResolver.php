<?php

declare(strict_types=1);

namespace Divoto\Cairn\Contracts;

/**
 * Identifies which tenant the current request belongs to.
 *
 * Cairn applies the resolved tenant automatically — on write, so entries are
 * attributed correctly, and on read, so a report can only ever see one
 * tenant's data. It is never a caller's responsibility to remember to scope a
 * query. Isolation that depends on every call site getting it right is not
 * isolation.
 *
 * Returning null means "not multi-tenant", and is what the shipped default
 * does.
 */
interface TenantResolver
{
    /**
     * The current tenant identifier, or null when tenancy does not apply.
     */
    public function resolve(): int|string|null;
}
