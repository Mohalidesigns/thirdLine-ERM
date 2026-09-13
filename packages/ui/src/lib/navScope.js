import { usePage } from '@inertiajs/react';

/**
 * Client-side view of App\Support\RestrictedRoleScope.
 *
 * Single-purpose roles (e.g. Internal Control Officer) are handed an explicit
 * route-name allowlist in the `auth.navScope` shared prop — the very list the
 * `role.scope` middleware enforces. Anything that renders a destination should
 * ask this first, so the UI never offers a link the server will bounce.
 *
 * `null` means "no ceiling", which is every ordinary role.
 *
 * Patterns mirror the server's Str::is() semantics: a trailing `*` is a prefix
 * match, anything else is exact.
 */
export function matchesScope(navScope, routeName) {
    if (!navScope) return true;
    if (!routeName) return false;

    return navScope.some((pattern) =>
        pattern.endsWith('*') ? routeName.startsWith(pattern.slice(0, -1)) : routeName === pattern
    );
}

/**
 * `const inScope = useInScope();` then `inScope('reports.index')` to decide
 * whether to render a link, tile or action.
 */
export function useInScope() {
    const navScope = usePage().props.auth?.navScope || null;

    return (routeName) => matchesScope(navScope, routeName);
}
