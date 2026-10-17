import { Link, usePage, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Dropdown from '../Components/Dropdown';
import FlashNotification from '../Components/FlashNotification';
import Icon from '../Components/Icon';
import tryRoute from '../lib/tryRoute';
import { PageHeaderSlotContext } from './PageHeaderSlot';

/**
 * The authenticated shell: sidebar, top bar, page-header band.
 *
 * ThirdLine Internal Audit's layout (64px bars, 260px/72px sidebar, Heroicons),
 * with one structural change - the sidebar is not a constant in this file. It
 * arrives as the `navigation` shared prop from App\Presenters\NavPresenter,
 * already filtered to what the signed-in user may open, so there is exactly
 * one definition of the menu and one gate (the route's own `permission:`
 * middleware) that it mirrors.
 *
 * Icons go through <Icon>: Heroicons for the names it maps, the self-hosted
 * Material Symbols glyph for the rest (see Components/Icon.jsx).
 *
 * Focus rings here are `focus-visible` only and never remove the outline
 * without a replacement (a booked finding against .btn-*).
 */

const RING_DARK = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white';
const RING_LIGHT = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2';

function pathOf(url) {
    try {
        return new URL(url, 'http://x').pathname.replace(/\/+$/, '') || '/';
    } catch {
        return url;
    }
}

function isExact(current, url) {
    return pathOf(current) === pathOf(url);
}

function isUnder(current, prefix) {
    const path = pathOf(current);
    const p = pathOf(prefix);
    return path === p || path.startsWith(p + '/');
}

function useMediaQuery(query) {
    const get = () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query).matches : true);
    const [matches, setMatches] = useState(get);
    useEffect(() => {
        const mq = window.matchMedia(query);
        const on = () => setMatches(mq.matches);
        on();
        mq.addEventListener('change', on);
        return () => mq.removeEventListener('change', on);
    }, [query]);
    return matches;
}

/**
 * A navigation link that knows which renderer is on the other end. Until
 * Phase 6 an Inertia <Link> to a Blade page would show the Blade HTML in an
 * error modal, so entries the NavPresenter did not mark `inertia` are plain
 * anchors (a full-page navigation).
 */
function NavAnchor({ item, children, ...props }) {
    if (item.inertia) {
        return (
            <Link href={item.url} {...props}>
                {children}
            </Link>
        );
    }

    return (
        <a href={item.url} {...props}>
            {children}
        </a>
    );
}

function PrimaryLink({ item, current, collapsed }) {
    const active = isExact(current, item.url) || (item.key === 'hq' && isUnder(current, item.url)) || (item.key === 'dashboards' && isUnder(current, item.url));
    return (
        <NavAnchor
            item={item}
            title={collapsed ? item.label : undefined}
            aria-label={collapsed ? item.label : undefined}
            aria-current={active ? 'page' : undefined}
            className={`sidebar-nav-item ${active ? 'active' : ''} ${RING_DARK}`}
        >
            <Icon name={item.icon} className="w-5 h-5 flex-shrink-0" />
            <span className={collapsed ? 'sr-only' : 'truncate'}>{item.label}</span>
        </NavAnchor>
    );
}

function ChildLink({ item, current }) {
    const active = isExact(current, item.url);
    return (
        <NavAnchor
            item={item}
            aria-current={active ? 'page' : undefined}
            className={`sidebar-nav-item ${active ? 'active' : ''} py-1.5 px-2.5 text-[12.5px] ${RING_DARK}`}
        >
            <span className="truncate">{item.label}</span>
        </NavAnchor>
    );
}

function Section({ section, current, collapsed, children }) {
    const sectionActive = (section.prefixes || []).some((p) => isUnder(current, p));
    const [open, setOpen] = useState(sectionActive);

    useEffect(() => {
        if (sectionActive) setOpen(true);
    }, [sectionActive]);

    if (collapsed) {
        const first = section.items?.[0] ?? section.groups?.[0]?.items?.[0];
        if (!first) return null;
        return (
            <NavAnchor
                item={first}
                title={section.label}
                aria-label={section.label}
                className={`sidebar-nav-item ${sectionActive ? 'active' : ''} ${RING_DARK}`}
            >
                <Icon name={section.icon} className="w-5 h-5 flex-shrink-0" />
                <span className="sr-only">{section.label}</span>
            </NavAnchor>
        );
    }

    const panelId = `nav-sec-${section.key}`;
    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className={`sidebar-nav-item w-full ${sectionActive ? 'active' : ''} ${RING_DARK}`}
                aria-expanded={open}
                aria-controls={panelId}
            >
                <Icon name={section.icon} className="w-5 h-5 flex-shrink-0" />
                <span className="truncate flex-1 text-left">{section.label}</span>
                <Icon
                    name="chevron_right"
                    strokeWidth={2}
                    className={`w-4 h-4 flex-shrink-0 transition-transform motion-reduce:transition-none ${open ? 'rotate-90' : ''}`}
                />
            </button>
            {open && (
                <div id={panelId} className="ml-4 pl-3 border-l border-white/10 space-y-0.5 mt-0.5 mb-1">
                    {children}
                </div>
            )}
        </div>
    );
}

/**
 * The application shell.
 *
 * `search` and `periodSelector` are SLOTS rather than imports. Both were
 * components this layout reached for directly, and both hardcode one product's
 * route names - `search.suggest`, `risk.periods.select` - which a shared shell
 * cannot name: the route does not exist in the other product and Ziggy throws
 * on a name it has never heard of.
 *
 * The layout keeps the decision that matters (WHERE they sit in the topbar, and
 * that search appears only with `search.view`, and the period chip only when a
 * period is resolved). The application supplies what goes in them.
 *
 * `title` is still accepted (pages pass it) but no longer rendered: the page's
 * own title lives in the band below the bar.
 */
export default function AuthenticatedLayout({ header, children, search = null, periodSelector = null }) {
    const page = usePage();
    const { auth, navigation, tenant, period, unreadNotifications, features } = page.props;
    const permissions = auth?.permissions ?? [];
    const current = page.url;
    const user = auth?.user ?? {};

    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [liveUnread, setLiveUnread] = useState(unreadNotifications || 0);
    const [bellRing, setBellRing] = useState(false);
    const [announce, setAnnounce] = useState('');
    const liveUnreadRef = useRef(liveUnread);
    const navRef = useRef(null);
    const hamburgerRef = useRef(null);
    const asideRef = useRef(null);
    const wasOpen = useRef(false);

    // The collapsed rail is a desktop state; the mobile drawer is always full.
    const collapsed = sidebarCollapsed && isDesktop;

    useEffect(() => {
        setLiveUnread(unreadNotifications || 0);
    }, [unreadNotifications]);

    useEffect(() => {
        liveUnreadRef.current = liveUnread;
    }, [liveUnread]);

    // Poll the bell every 30 s once the JSON count route exists. Until then the
    // count is what the server shared with the page. Skipped while the tab is
    // hidden so a degraded link is not spent on a background tab.
    useEffect(() => {
        const url = tryRoute('notifications.unread-count');
        if (!url) return undefined;

        let ringTimer;
        const poll = setInterval(async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) return;
                const data = await res.json();
                const next = data.unread_count || 0;
                if (next > liveUnreadRef.current) {
                    setBellRing(true);
                    clearTimeout(ringTimer);
                    ringTimer = setTimeout(() => setBellRing(false), 2000);
                    setAnnounce(`You have ${next} unread notification${next === 1 ? '' : 's'}`);
                }
                setLiveUnread(next);
            } catch {
                // polling failures are not the user's problem
            }
        }, 30000);

        return () => {
            clearInterval(poll);
            clearTimeout(ringTimer);
        };
    }, []);

    // Drawer: close on navigation and on Escape; move focus in and back out.
    useEffect(() => router.on('navigate', () => setMobileMenuOpen(false)), []);

    useEffect(() => {
        if (!mobileMenuOpen) return undefined;
        const onKey = (e) => e.key === 'Escape' && setMobileMenuOpen(false);
        document.addEventListener('keydown', onKey);
        // The drawer is still inert until the effect below runs; lift it so focus can land.
        asideRef.current?.removeAttribute('inert');
        navRef.current?.querySelector('a,button')?.focus();
        return () => document.removeEventListener('keydown', onKey);
    }, [mobileMenuOpen]);

    useEffect(() => {
        if (wasOpen.current && !mobileMenuOpen) hamburgerRef.current?.focus();
        wasOpen.current = mobileMenuOpen;
    }, [mobileMenuOpen]);

    // Off-canvas drawer links must not be tabbable.
    const drawerHidden = !isDesktop && !mobileMenuOpen;
    useEffect(() => {
        const el = asideRef.current;
        if (!el) return;
        if (drawerHidden) el.setAttribute('inert', '');
        else el.removeAttribute('inert');
    }, [drawerHidden]);

    useEffect(() => {
        navRef.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, []);

    // Page-header band: PageHeader portals here. `header` wins and takes the band itself.
    const [bandTarget, setBandTarget] = useState(null);
    const [claimedTitle, setClaimedTitle] = useState(null);
    const [slotVersion, setSlotVersion] = useState(0);
    const claimedRef = useRef(null);
    const claim = useCallback((id, titleId) => {
        if (claimedRef.current && claimedRef.current !== id) return false;
        claimedRef.current = id;
        setClaimedTitle(titleId ?? true);
        return true;
    }, []);
    const release = useCallback((id) => {
        if (claimedRef.current !== id) return;
        claimedRef.current = null;
        setClaimedTitle(null);
        setSlotVersion((v) => v + 1);
    }, []);
    const slotValue = useMemo(() => (header ? null : { target: bandTarget, claim, release, version: slotVersion }), [header, bandTarget, claim, release, slotVersion]);

    const nav = navigation || { primary: [], sections: [], admin: null };
    const primary = (nav.primary || []).filter((i) => i.url);
    const sections = (nav.sections || [])
        .map((s) => ({ ...s, items: (s.items || []).filter((i) => i.url) }))
        .filter((s) => s.items.length > 0);
    const admin = nav.admin
        ? { ...nav.admin, groups: (nav.admin.groups || []).map((g) => ({ ...g, items: (g.items || []).filter((i) => i.url) })).filter((g) => g.items.length > 0) }
        : null;
    const adminShown = admin && admin.groups.length > 0;

    const notificationsUrl = tryRoute('notifications.index');
    const searchUrl = tryRoute('search.index');
    const myUrl = tryRoute('my.index');
    const profileUrl = tryRoute('profile.edit');
    const mfaSetupUrl = features?.mfa_totp ? tryRoute('mfa.setup') : null;
    const logoutUrl = tryRoute('logout');
    const showSearch = Boolean(search) && permissions.includes('search.view');
    const bellLabel = liveUnread > 0 ? `Notifications (${liveUnread} unread)` : 'Notifications';
    const closeNav = () => setMobileMenuOpen(false);

    return (
        <div className="min-h-screen bg-[var(--color-bg)]">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-[var(--color-primary)] focus:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
            >
                Skip to content
            </a>
            <FlashNotification />

            {mobileMenuOpen && (
                <button
                    type="button"
                    aria-label="Close navigation"
                    className="fixed inset-0 z-40 bg-black/50 lg:hidden"
                    onClick={closeNav}
                />
            )}

            {/* Sidebar */}
            <aside
                ref={asideRef}
                id="app-sidebar"
                data-sidebar
                aria-label="Primary"
                className={`fixed top-0 left-0 z-50 h-full bg-[var(--color-primary)] text-white flex flex-col transition-all duration-300 motion-reduce:transition-none w-[var(--sidebar-width)] ${
                    sidebarCollapsed ? 'lg:w-[var(--sidebar-collapsed-width)]' : ''
                } ${mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}
            >
                {/* Logo bar */}
                <div className="flex items-center px-4 h-16 border-b border-white/10 flex-shrink-0">
                    <span className="h-9 w-9 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                        <Icon name="shield" className="w-5 h-5 text-[var(--color-accent)]" />
                    </span>
                    {!collapsed && (
                        <span className="ml-3 min-w-0 flex-1">
                            <span className="block text-sm font-bold leading-tight truncate">Atheris ERM</span>
                            <span className="block text-[11px] text-white/60 leading-tight">GRC Suite</span>
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={closeNav}
                        aria-label="Close navigation"
                        className={`lg:hidden ml-auto p-1.5 rounded-lg text-white/70 hover:text-white hover:bg-white/10 ${RING_DARK}`}
                    >
                        <Icon name="close" className="w-5 h-5" />
                    </button>
                </div>

                {/* Navigation */}
                <nav ref={navRef} aria-label="Main" className="flex-1 overflow-y-auto sidebar-scroll py-4 px-3 space-y-1" data-testid="sidebar-nav">
                    {primary.map((item) => (
                        <PrimaryLink key={item.key} item={item} current={current} collapsed={collapsed} />
                    ))}

                    {primary.length > 0 && (sections.length > 0 || adminShown) && <div role="separator" className="h-px bg-white/10 mx-1 my-2" />}

                    {sections.map((section) => (
                        <Section key={section.key} section={section} current={current} collapsed={collapsed}>
                            {section.items.map((item) => (
                                <ChildLink key={item.key} item={item} current={current} />
                            ))}
                        </Section>
                    ))}

                    {adminShown && (
                        <>
                            <div role="separator" className="h-px bg-white/10 mx-1 my-3" />
                            <Section section={admin} current={current} collapsed={collapsed}>
                                {admin.groups.map((group, gi) => {
                                    const labelId = `nav-grp-${gi}`;
                                    return (
                                        <div key={group.label} role="group" aria-labelledby={labelId} className={gi > 0 ? 'mt-1 border-t border-white/10' : ''}>
                                            <div id={labelId} className="px-2.5 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-white/60">
                                                {group.label}
                                            </div>
                                            <div className="space-y-0.5">
                                                {group.items.map((item) => (
                                                    <ChildLink key={item.key} item={item} current={current} />
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </Section>
                        </>
                    )}
                </nav>

                {/* Footer */}
                <div className="border-t border-white/10 py-4 px-3 space-y-1 flex-shrink-0">
                    {!collapsed && (
                        <div className="px-3 pb-1 text-[11px] text-white/60 flex items-center gap-2">
                            <Icon name="info" className="w-4 h-4 flex-shrink-0" />
                            <span>Designed for CBN ORMS reporting</span>
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
                        className={`sidebar-nav-item w-full text-white/70 hover:text-white hidden lg:flex ${RING_DARK}`}
                        aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        aria-pressed={sidebarCollapsed}
                        title={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                    >
                        <Icon
                            name="keyboard_double_arrow_left"
                            className={`w-5 h-5 flex-shrink-0 transition-transform motion-reduce:transition-none ${sidebarCollapsed ? 'rotate-180' : ''}`}
                        />
                        {!sidebarCollapsed && <span>Collapse</span>}
                    </button>
                </div>
            </aside>

            {/* Main content area */}
            <div className={`transition-all duration-300 motion-reduce:transition-none ${sidebarCollapsed ? 'lg:ml-[var(--sidebar-collapsed-width)]' : 'lg:ml-[var(--sidebar-width)]'}`}>
                {/* Top bar */}
                <header className="sticky top-0 z-30 bg-white border-b border-gray-200 h-16">
                    <div className="flex items-center justify-between h-full px-4 sm:px-6 gap-3">
                        <div className="flex items-center gap-4 min-w-0">
                            <button
                                ref={hamburgerRef}
                                type="button"
                                onClick={() => setMobileMenuOpen(true)}
                                className={`lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 ${RING_LIGHT}`}
                                aria-label="Open navigation"
                                aria-expanded={mobileMenuOpen}
                                aria-controls="app-sidebar"
                            >
                                <Icon name="menu" className="w-6 h-6" />
                            </button>

                            {/* Global search - type-ahead over the object graph, permission-filtered server-side. */}
                            {showSearch && <div className="hidden sm:block">{search}</div>}
                            {showSearch && searchUrl && (
                                <Link
                                    href={searchUrl}
                                    aria-label="Search"
                                    className={`sm:hidden p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 ${RING_LIGHT}`}
                                >
                                    <Icon name="search" className="w-5 h-5" />
                                </Link>
                            )}
                        </div>

                        <div className="flex items-center gap-3">
                            {/* Tenant chip */}
                            {tenant && (
                                <span
                                    className="hidden xl:inline-flex items-center gap-1.5 h-9 rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm font-medium text-gray-700"
                                    title={tenant.name}
                                    data-testid="tenant-chip"
                                >
                                    <Icon name="corporate_fare" className="w-4 h-4 text-gray-400" />
                                    <span className="truncate max-w-[180px]">{tenant.code || tenant.name}</span>
                                </span>
                            )}

                            {/* Reporting period: everything on the page is "as at" this. */}
                            {period && <div className="hidden sm:block">{periodSelector}</div>}

                            {/* Notifications */}
                            {notificationsUrl && (
                                <Link
                                    href={notificationsUrl}
                                    className={`relative p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors motion-reduce:transition-none ${RING_LIGHT}`}
                                    aria-label={bellLabel}
                                >
                                    <Icon name="notifications" className={`w-5 h-5 ${bellRing ? 'animate-bell-ring motion-reduce:animate-none' : ''}`} />
                                    {liveUnread > 0 && (
                                        <span
                                            aria-hidden="true"
                                            className={`absolute -top-0.5 -right-0.5 w-5 h-5 bg-[var(--color-error)] text-white text-[10px] font-bold rounded-full flex items-center justify-center ${
                                                bellRing ? 'animate-pulse motion-reduce:animate-none' : ''
                                            }`}
                                        >
                                            {liveUnread > 9 ? '9+' : liveUnread}
                                        </span>
                                    )}
                                </Link>
                            )}
                            <span className="sr-only" role="status" aria-live="polite">
                                {announce}
                            </span>

                            {/* User dropdown */}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        aria-label={`Account menu for ${user.name ?? 'user'}`}
                                        className={`flex items-center gap-3 p-1.5 rounded-lg hover:bg-gray-100 transition-colors motion-reduce:transition-none ${RING_LIGHT}`}
                                    >
                                        <span aria-hidden="true" className="w-8 h-8 bg-[var(--color-primary)] rounded-full flex items-center justify-center text-white text-sm font-semibold">
                                            {user.name ? user.name.charAt(0).toUpperCase() : 'U'}
                                        </span>
                                        <span className="hidden lg:block text-left">
                                            <span className="block text-sm font-medium text-gray-700">{user.name}</span>
                                            <span className="block text-xs text-gray-500 truncate max-w-[180px]">{user.email}</span>
                                        </span>
                                        <Icon name="expand_more" className="w-4 h-4 text-gray-400 hidden lg:block" />
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content>
                                    {tenant && <div className="xl:hidden px-4 py-2 text-xs text-gray-500 border-b border-gray-100">Signed in to {tenant.name}</div>}
                                    {profileUrl && <Dropdown.Link href={profileUrl}>Profile</Dropdown.Link>}
                                    {mfaSetupUrl && <Dropdown.Link href={mfaSetupUrl}>2FA Setup</Dropdown.Link>}
                                    {myUrl && <Dropdown.Link href={myUrl}>My Responsibilities</Dropdown.Link>}
                                    {logoutUrl && (
                                        <Dropdown.Link href={logoutUrl} method="post" as="button">
                                            Log Out
                                        </Dropdown.Link>
                                    )}
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                </header>

                {/* Page-header band */}
                <div className={`bg-white border-b border-gray-100 ${header || claimedTitle ? '' : 'hidden'}`} data-testid="page-header-band">
                    <div className="px-4 sm:px-6 py-4" ref={setBandTarget}>
                        {header}
                    </div>
                </div>

                <main
                    id="main-content"
                    tabIndex={-1}
                    aria-labelledby={typeof claimedTitle === 'string' ? claimedTitle : undefined}
                    className="p-4 sm:p-6 focus:outline-none"
                >
                    <PageHeaderSlotContext.Provider value={slotValue}>{children}</PageHeaderSlotContext.Provider>
                </main>
            </div>
        </div>
    );
}
