import { Link, usePage, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Dropdown from '@/Components/Dropdown';
import FlashNotification from '@/Components/FlashNotification';

/**
 * The authenticated shell: sidebar, top bar, page header slot.
 *
 * ThirdLine's layout, with one structural change — the sidebar is not a
 * constant in this file. It arrives as the `navigation` shared prop from
 * App\Presenters\NavPresenter, already filtered to what the signed-in user
 * may open, so there is exactly one definition of the menu and one gate
 * (the route's own `permission:` middleware) that it mirrors.
 *
 * Icons are Material Symbols, self-hosted (resources/css/fonts.css), because
 * that is what the product's Blade screens already use and it lets the
 * presenter name an icon as a string.
 */

function Icon({ name, className = 'text-[18px]' }) {
    return <span className={`material-symbols-outlined leading-none ${className}`}>{name}</span>;
}

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

function tryRoute(name, params) {
    try {
        return route(name, params);
    } catch {
        return null;
    }
}

function PrimaryLink({ item, current, collapsed }) {
    const active = isExact(current, item.url) || (item.key === 'hq' && isUnder(current, item.url)) || (item.key === 'dashboards' && isUnder(current, item.url));
    return (
        <Link
            href={item.url}
            title={collapsed ? item.label : undefined}
            className={`flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all text-[13px] ${
                active ? 'text-white bg-white/12' : 'text-white/70 hover:text-white hover:bg-white/8'
            }`}
        >
            <Icon name={item.icon} />
            {!collapsed && <span className="truncate">{item.label}</span>}
        </Link>
    );
}

function SectionItem({ item, current }) {
    const active = isExact(current, item.url);
    return (
        <Link
            href={item.url}
            className={`block px-3 py-1.5 rounded-md text-[12px] transition-all ${
                active ? 'font-semibold bg-[var(--color-accent)] text-[var(--color-primary)]' : 'text-white/50 hover:text-white hover:bg-white/6'
            }`}
        >
            {item.label}
        </Link>
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
        return (
            <Link
                href={first?.url ?? '#'}
                title={section.label}
                className={`flex items-center justify-center px-3 py-2 rounded-lg ${sectionActive ? 'bg-white/12 text-white' : 'text-white/60 hover:bg-white/6 hover:text-white/80'}`}
            >
                <Icon name={section.icon} className={`text-[18px] ${sectionActive ? 'text-[var(--color-accent)]' : ''}`} />
            </Link>
        );
    }

    return (
        <div className="nav-group">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className={`w-full flex items-center justify-between px-3 py-2 rounded-lg text-[13px] font-medium transition-all group ${
                    sectionActive ? 'bg-white/12 text-white' : 'text-white/60 hover:bg-white/6 hover:text-white/80'
                }`}
                aria-expanded={open}
            >
                <span className="flex items-center gap-2.5">
                    <Icon name={section.icon} className={`text-[18px] ${sectionActive ? 'text-[var(--color-accent)]' : 'text-white/40 group-hover:text-white/60'}`} />
                    <span>{section.label}</span>
                </span>
                <Icon name="expand_more" className={`text-[16px] text-white/40 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && (
                <div className="mt-0.5 ml-[30px] border-l border-white/10 pl-2 space-y-0.5">
                    {children}
                </div>
            )}
        </div>
    );
}

export default function AuthenticatedLayout({ header, title, children }) {
    const page = usePage();
    const { auth, navigation, tenant, period, unreadNotifications } = page.props;
    const current = page.url;
    const user = auth?.user ?? {};

    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [liveUnread, setLiveUnread] = useState(unreadNotifications || 0);
    const [bellRing, setBellRing] = useState(false);

    useEffect(() => {
        setLiveUnread(unreadNotifications || 0);
    }, [unreadNotifications]);

    // Poll the bell every 30 s once the JSON count route exists (Phase 1 adds
    // notifications.unread-count). Until then the count is what the server
    // shared with the page.
    useEffect(() => {
        const url = tryRoute('notifications.unread-count');
        if (!url) return undefined;

        const poll = setInterval(async () => {
            try {
                const res = await fetch(url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) return;
                const data = await res.json();
                const next = data.unread_count || 0;
                if (next > liveUnread) {
                    setBellRing(true);
                    setTimeout(() => setBellRing(false), 2000);
                }
                setLiveUnread(next);
            } catch {
                // polling failures are not the user's problem
            }
        }, 30000);

        return () => clearInterval(poll);
    }, [liveUnread]);

    const nav = navigation || { primary: [], sections: [], admin: null };
    const notificationsUrl = tryRoute('notifications.index');
    const myUrl = tryRoute('my.index');
    const logoutUrl = tryRoute('logout');

    return (
        <div className="min-h-screen bg-[var(--color-bg)]">
            <FlashNotification />

            {mobileMenuOpen && (
                <div className="fixed inset-0 z-40 bg-black/50 lg:hidden" onClick={() => setMobileMenuOpen(false)} />
            )}

            {/* Sidebar */}
            <aside
                data-sidebar
                className={`fixed top-0 left-0 z-50 h-full bg-[var(--color-primary)] text-white transition-all duration-300 flex flex-col ${
                    sidebarCollapsed ? 'w-[var(--sidebar-collapsed-width)]' : 'w-[var(--sidebar-width)]'
                } ${mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}
            >
                {/* Brand */}
                <div className="px-5 pt-5 pb-3 border-b border-white/10 flex-shrink-0">
                    <div className="flex items-center gap-3">
                        <div className="w-9 h-9 bg-white/15 rounded-lg flex items-center justify-center flex-shrink-0">
                            <Icon name="shield" className="text-[var(--color-accent)] text-xl" />
                        </div>
                        {!sidebarCollapsed && (
                            <div>
                                <div className="text-[13px] font-bold text-white leading-tight">Atheris ERM</div>
                                <div className="text-[10px] text-white/50 font-medium">GRC Suite</div>
                            </div>
                        )}
                    </div>
                </div>

                {/* Navigation */}
                <nav className="flex-1 overflow-y-auto sidebar-scroll px-3 py-3 space-y-0.5" data-testid="sidebar-nav">
                    {nav.primary.map((item) => (
                        <PrimaryLink key={item.key} item={item} current={current} collapsed={sidebarCollapsed} />
                    ))}

                    {nav.primary.length > 0 && <div className="h-px bg-white/10 mx-1 my-2" />}

                    {nav.sections.map((section) => (
                        <Section key={section.key} section={section} current={current} collapsed={sidebarCollapsed}>
                            {section.items.map((item) => (
                                <SectionItem key={item.key} item={item} current={current} />
                            ))}
                        </Section>
                    ))}

                    {nav.admin && (
                        <>
                            <div className="h-px bg-white/10 mx-1 my-3" />
                            <Section section={nav.admin} current={current} collapsed={sidebarCollapsed}>
                                {nav.admin.groups.map((group) => (
                                    <div key={group.label}>
                                        <div className="px-3 pt-2 pb-1 text-[9px] font-semibold uppercase tracking-[0.12em] text-white/30">{group.label}</div>
                                        {group.items.map((item) => (
                                            <SectionItem key={item.key} item={item} current={current} />
                                        ))}
                                    </div>
                                ))}
                            </Section>
                        </>
                    )}
                </nav>

                {/* Footer */}
                <div className="border-t border-white/10 px-3 py-3 flex-shrink-0 space-y-1">
                    {!sidebarCollapsed && (
                        <div className="flex items-center gap-2 px-1 text-[10px] text-white/30">
                            <Icon name="architecture" className="text-[14px]" />
                            <span>Designed for CBN ORMS reporting</span>
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
                        className="sidebar-nav-item w-full text-white/50 hover:text-white hidden lg:flex"
                    >
                        <Icon name={sidebarCollapsed ? 'keyboard_double_arrow_right' : 'keyboard_double_arrow_left'} className="text-[18px]" />
                        {!sidebarCollapsed && <span>Collapse</span>}
                    </button>
                </div>
            </aside>

            {/* Main content area */}
            <div className={`transition-all duration-300 ${sidebarCollapsed ? 'lg:ml-[var(--sidebar-collapsed-width)]' : 'lg:ml-[var(--sidebar-width)]'}`}>
                {/* Top bar */}
                <header className="sticky top-0 z-30 bg-white border-b border-gray-200 h-14">
                    <div className="flex items-center justify-between h-full px-4 sm:px-6">
                        <div className="flex items-center gap-3 min-w-0">
                            <button
                                type="button"
                                onClick={() => setMobileMenuOpen(true)}
                                className="lg:hidden p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100"
                                aria-label="Open navigation"
                            >
                                <Icon name="menu" className="text-[22px]" />
                            </button>

                            {/* Breadcrumb path, as the Blade topbar renders it */}
                            <div className="flex items-center gap-2 text-xs min-w-0">
                                <span className="text-gray-400">Risk Management</span>
                                {title && (
                                    <>
                                        <span className="text-gray-300">/</span>
                                        <span className="text-[var(--color-primary)] font-semibold truncate">{title}</span>
                                    </>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-3">
                            {/* Tenant chip */}
                            {tenant && (
                                <span
                                    className="hidden md:inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-700"
                                    title={tenant.name}
                                    data-testid="tenant-chip"
                                >
                                    <Icon name="corporate_fare" className="text-[16px] text-gray-400" />
                                    <span className="truncate max-w-[180px]">{tenant.code || tenant.name}</span>
                                </span>
                            )}

                            {/* Reporting period. Read-only until Phase 1 ports the
                                selector; everything on the page is "as at" this. */}
                            {period && (
                                <span
                                    className="hidden sm:inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-[var(--color-primary)]"
                                    title={`Reporting period: ${period.name}`}
                                    data-testid="period-chip"
                                >
                                    <Icon name="calendar_month" className="text-[16px] text-gray-400" />
                                    {period.name}
                                    {period.is_closed && <Icon name="lock" className="text-[13px] text-gray-400" />}
                                </span>
                            )}

                            {/* Notifications */}
                            {notificationsUrl && (
                                <Link
                                    href={notificationsUrl}
                                    className="relative p-1.5 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                                    aria-label="Notifications"
                                >
                                    <Icon name="notifications" className={`text-[22px] ${bellRing ? 'animate-bell-ring' : ''}`} />
                                    {liveUnread > 0 && (
                                        <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center leading-none">
                                            {liveUnread > 9 ? '9+' : liveUnread}
                                        </span>
                                    )}
                                </Link>
                            )}

                            {/* User dropdown */}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button type="button" className="flex items-center gap-2 p-1 rounded-lg hover:bg-gray-100 transition-colors">
                                        <div className="w-8 h-8 bg-[var(--color-primary)] rounded-full flex items-center justify-center text-white text-sm font-semibold">
                                            {user.name ? user.name.charAt(0).toUpperCase() : 'U'}
                                        </div>
                                        <div className="hidden sm:block text-left">
                                            <div className="text-sm font-medium text-gray-700 leading-tight">{user.name}</div>
                                            <div className="text-[11px] text-gray-500">{user.email}</div>
                                        </div>
                                        <Icon name="expand_more" className="text-[18px] text-gray-400 hidden sm:block" />
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content>
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

                {header && (
                    <div className="bg-white border-b border-gray-100">
                        <div className="px-4 sm:px-6 py-4">{header}</div>
                    </div>
                )}

                <main className="p-4 sm:p-6">{children}</main>
            </div>
        </div>
    );
}
