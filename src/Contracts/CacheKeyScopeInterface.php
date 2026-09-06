<?php

namespace Jayanta\Jeeves\Contracts;

/**
 * Who is asking, as far as the query cache is concerned.
 *
 * A cached row holds an intent or a SQL recipe - never result rows - and the
 * SQL is re-executed per request. So sharing a row between two people asking
 * the same words is normally correct, and is why the cache pays for itself.
 *
 * It stops being correct when the recipe was built under a rule that is not the
 * same for everyone: an adopter who varies `required_filter` per tenant, or
 * rewrites schema config per request, stores a statement carrying one tenant's
 * predicate under a key that says only "total revenue last month". Implement
 * this, name the class in `cache.key_scope`, and the returned value becomes
 * part of the key.
 *
 *     class TenantScope implements CacheKeyScopeInterface
 *     {
 *         public function resolve(): ?string
 *         {
 *             return (string) auth()->user()?->tenant_id;
 *         }
 *     }
 *
 * Return the narrowest thing that is actually a boundary. A tenant id is
 * usually right; a user id is right when rules differ per user, and costs one
 * cache miss per user per wording. Returning null means "no scope" and is a
 * deliberate choice, not an error - it is the shared behaviour every install
 * has today.
 *
 * Resolved from the container, so constructor injection works.
 */
interface CacheKeyScopeInterface
{
    /**
     * The current caller's cache scope, or null for the shared scope.
     *
     * Called on every cache read and write, so it must be cheap. Reading the
     * authenticated user is fine; a database round trip per question is not.
     */
    public function resolve(): ?string;
}
