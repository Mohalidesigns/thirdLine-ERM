import {
    BookmarkIcon,
    ViewColumnsIcon,
    ArrowDownIcon,
    ArrowUpIcon,
    AdjustmentsHorizontalIcon,
    AdjustmentsVerticalIcon,
    ArrowDownOnSquareIcon,
    ArrowDownTrayIcon,
    ArrowLeftIcon,
    ArrowPathIcon,
    ArrowPathRoundedSquareIcon,
    ArrowRightOnRectangleIcon,
    ArrowTrendingUpIcon,
    ArrowUpTrayIcon,
    Bars3Icon,
    BellIcon,
    BellSlashIcon,
    BugAntIcon,
    BuildingOffice2Icon,
    CalculatorIcon,
    CalendarDaysIcon,
    ChartBarIcon,
    ChartBarSquareIcon,
    ChatBubbleLeftRightIcon,
    CheckIcon,
    CheckBadgeIcon,
    CheckCircleIcon,
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
    ChevronDownIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ChevronUpIcon,
    ClipboardDocumentCheckIcon,
    ClipboardDocumentListIcon,
    ClockIcon,
    Cog6ToothIcon,
    DocumentTextIcon,
    EllipsisVerticalIcon,
    ExclamationCircleIcon,
    ExclamationTriangleIcon,
    EyeIcon,
    FolderIcon,
    FolderOpenIcon,
    HandThumbUpIcon,
    HomeIcon,
    InformationCircleIcon,
    LifebuoyIcon,
    LinkIcon,
    ListBulletIcon,
    LockClosedIcon,
    MagnifyingGlassIcon,
    MagnifyingGlassPlusIcon,
    MegaphoneIcon,
    PaperAirplaneIcon,
    PencilSquareIcon,
    PlayIcon,
    PlusIcon,
    PlusCircleIcon,
    PresentationChartLineIcon,
    PrinterIcon,
    QueueListIcon,
    RectangleStackIcon,
    ScaleIcon,
    ShareIcon,
    ShieldCheckIcon,
    ShieldExclamationIcon,
    SignalIcon,
    SparklesIcon,
    Squares2X2Icon,
    TableCellsIcon,
    TrashIcon,
    UserIcon,
    UserGroupIcon,
    WrenchScrewdriverIcon,
    XCircleIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline';

/**
 * Heroicons v2 (outline) behind the Material Symbols names the product already
 * speaks: NavPresenter names its icons as strings, and so do search results and
 * widget payloads. Anything not in this map falls back to the self-hosted
 * Material Symbols glyph, so the ~330 page-level uses keep rendering until the
 * booked icon-sweep PR. Named imports only, so the bundle tree-shakes.
 */
const MAP = {
    bookmark: BookmarkIcon,
    view_column: ViewColumnsIcon,
    arrow_upward: ArrowUpIcon,
    arrow_downward: ArrowDownIcon,
    report_problem: ExclamationTriangleIcon,
    bug_report: BugAntIcon,
    account_tree: ShareIcon,
    fact_check: ClipboardDocumentCheckIcon,
    view_list: QueueListIcon,
    verified_user: ShieldCheckIcon,
    verified: CheckBadgeIcon,
    upload_file: ArrowUpTrayIcon,
    update: ArrowPathIcon,
    tune: AdjustmentsHorizontalIcon,
    trending_up: ArrowTrendingUpIcon,
    task_alt: CheckCircleIcon,
    summarize: DocumentTextIcon,
    speed: ChartBarSquareIcon,
    shield_moon: ShieldExclamationIcon,
    shield: ShieldCheckIcon,
    radar: SignalIcon,
    linear_scale: AdjustmentsVerticalIcon,
    health_and_safety: LifebuoyIcon,
    handshake: UserGroupIcon,
    grid_on: TableCellsIcon,
    gavel: ScaleIcon,
    folder_open: FolderOpenIcon,
    device_hub: ArrowPathRoundedSquareIcon,
    dashboard: Squares2X2Icon,
    corporate_fare: BuildingOffice2Icon,
    checklist: ClipboardDocumentListIcon,
    category: RectangleStackIcon,
    campaign: MegaphoneIcon,
    calendar_month: CalendarDaysIcon,
    calculate: CalculatorIcon,
    assessment: ChartBarIcon,
    approval: HandThumbUpIcon,
    analytics: PresentationChartLineIcon,
    admin_panel_settings: Cog6ToothIcon,
    rate_review: ChatBubbleLeftRightIcon,
    psychology: SparklesIcon,
    home: HomeIcon,
    healing: WrenchScrewdriverIcon,
    add: PlusIcon,
    save: ArrowDownOnSquareIcon,
    edit: PencilSquareIcon,
    add_circle: PlusCircleIcon,
    download: ArrowDownTrayIcon,
    delete: TrashIcon,
    close: XMarkIcon,
    history: ClockIcon,
    play_arrow: PlayIcon,
    info: InformationCircleIcon,
    send: PaperAirplaneIcon,
    search: MagnifyingGlassIcon,
    link: LinkIcon,
    visibility: EyeIcon,
    chevron_right: ChevronRightIcon,
    check_circle: CheckCircleIcon,
    check: CheckIcon,
    arrow_back: ArrowLeftIcon,
    more_vert: EllipsisVerticalIcon,
    lock: LockClosedIcon,
    list: ListBulletIcon,
    expand_more: ChevronDownIcon,
    menu: Bars3Icon,
    notifications: BellIcon,
    keyboard_double_arrow_left: ChevronDoubleLeftIcon,
    logout: ArrowRightOnRectangleIcon,
    person: UserIcon,
    warning: ExclamationTriangleIcon,
    print: PrinterIcon,
    chevron_left: ChevronLeftIcon,
    keyboard_double_arrow_right: ChevronDoubleRightIcon,
    cancel: XCircleIcon,
    done_all: CheckCircleIcon,
    notifications_off: BellSlashIcon,
    refresh: ArrowPathIcon,
    error_outline: ExclamationCircleIcon,
    zoom_in: MagnifyingGlassPlusIcon,
    topic: FolderIcon,
    expand_less: ChevronUpIcon,
};

const warned = new Set();

// Material-era callers size glyphs with text-[Npx]; SVGs only obey w-/h-/size-.
const SIZED = /(^|\s)(w|h|size)-/;
const TEXT_PX = /(^|\s)text-\[(\d+(?:\.\d+)?)px\]/;
const W_TO_PX = { 'w-3': 12, 'w-3.5': 14, 'w-4': 16, 'w-5': 20, 'w-6': 24, 'w-8': 32, 'w-10': 40, 'w-12': 48 };

export default function Icon({ name, className = 'w-5 h-5', strokeWidth = 1.5, label, style, ...rest }) {
    const Cmp = MAP[name];
    const a11y = label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': 'true', focusable: 'false' };

    if (!Cmp) {
        if (import.meta.env?.DEV && name && !warned.has(name)) {
            warned.add(name);
            console.debug(`[Icon] "${name}" is not mapped; using Material Symbols`);
        }
        const wTok = className.split(/\s+/).map((c) => (W_TO_PX[c] ? W_TO_PX[c] : (c.match(/^w-\[(\d+(?:\.\d+)?)px\]$/) || [])[1])).find(Boolean);
        // The glyph is a font, so w-5 means nothing to it: derive font-size inline.
        const glyphStyle = wTok && !TEXT_PX.test(className) ? { fontSize: Number(wTok), ...style } : style;
        return (
            <span className={`material-symbols-outlined leading-none ${className}`} style={glyphStyle} {...a11y} {...rest}>
                {name}
            </span>
        );
    }

    let svgStyle = style;
    if (!SIZED.test(className)) {
        const m = className.match(TEXT_PX);
        if (m) svgStyle = { width: Number(m[2]), height: Number(m[2]), ...style };
    }

    return <Cmp className={className} strokeWidth={strokeWidth} style={svgStyle} {...a11y} {...rest} />;
}
