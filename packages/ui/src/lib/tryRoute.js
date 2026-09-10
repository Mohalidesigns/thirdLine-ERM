/**
 * route() throws for a name the Ziggy manifest does not carry — a route
 * behind a feature flag, or one the user's permission set never registers.
 * Pages use this for optional links, so a missing route hides the link
 * instead of breaking the page.
 */
export default function tryRoute(name, params) {
    try {
        return route(name, params);
    } catch {
        return null;
    }
}
