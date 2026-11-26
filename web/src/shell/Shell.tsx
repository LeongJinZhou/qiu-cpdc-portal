// The signed-in frame: brand, navigation by permission, the person and sign-out.
import { ClipboardList, GraduationCap, LogOut, Menu, ScrollText, UserRound, Users, X, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { BadgeCheck, BookOpenCheck, BriefcaseBusiness, Building2, Gauge } from 'lucide-react'; // slice 2b
// slice 3a (workshops, CV, career path)
import { CalendarDays, FileText, Route } from 'lucide-react';
// slice 3b icons
import { HandHelping, ListChecks, MessagesSquare, Network } from 'lucide-react';
import { ChartColumn, House, MessageSquareHeart } from 'lucide-react'; // slice B
import { ChartPie } from 'lucide-react'; // slice C
import { can, ROLE_LABEL, type Me } from '../auth';
import { Avatar } from '../ui/parts';
import { NAV_2A } from '../logbook/nav2a'; // slice 2a
import './shell.css';

interface NavItem {
  path: string;
  label: string;
  icon: LucideIcon;
  permission: string;
}

export const NAV: NavItem[] = [
  { path: '/home', label: 'Home', icon: House, permission: 'home.view' }, // slice B: first, so it is the landing page
  { path: '/profile', label: 'My profile', icon: UserRound, permission: 'profile.own' },
  { path: '/alumni', label: 'Alumni tracker', icon: GraduationCap, permission: 'alumni.track' },
  { path: '/people', label: 'People', icon: Users, permission: 'people.view' },
  { path: '/registrations', label: 'Graduate sign-ups', icon: ClipboardList, permission: 'registrations.decide' },
  { path: '/audit', label: 'Audit log', icon: ScrollText, permission: 'audit.view' },
  ...NAV_2A, // slice 2a
  // --- slice 2b (start) ---
  { path: '/jobs', label: 'Jobs', icon: BriefcaseBusiness, permission: 'jobs.browse' },
  { path: '/skills', label: 'Skills', icon: Gauge, permission: 'skills.own' },
  { path: '/learning', label: 'Learning', icon: BookOpenCheck, permission: 'learning.own' },
  { path: '/employers', label: 'Employers', icon: Building2, permission: 'jobs.manage' },
  { path: '/learning-checks', label: 'Learning checks', icon: BadgeCheck, permission: 'learning.verify' },
  // --- slice 2b (end) ---
  // slice 3a (workshops, CV, career path)
  { path: '/workshops', label: 'Workshops', icon: CalendarDays, permission: 'workshops.view' },
  { path: '/cv', label: 'CV builder', icon: FileText, permission: 'cv.own' },
  { path: '/career-path', label: 'Career path', icon: Route, permission: 'careerpath.view' },
  // --- slice 3b: alumni network, mentoring, interview practice (begin) ---
  { path: '/network', label: 'Alumni network', icon: Network, permission: 'network.view' },
  { path: '/interview', label: 'Interview practice', icon: MessagesSquare, permission: 'interview.practice' },
  { path: '/mentoring', label: 'Mentoring activity', icon: HandHelping, permission: 'mentoring.activity' },
  { path: '/questions', label: 'Interview questions', icon: ListChecks, permission: 'interview.bank' },
  // --- slice 3b (end) ---
  // --- slice B (begin) ---
  { path: '/surveys', label: 'Yearly survey', icon: MessageSquareHeart, permission: 'survey.take' },
  { path: '/surveys/manage', label: 'Survey results', icon: ChartColumn, permission: 'survey.manage' },
  // --- slice B (end) ---
  { path: '/analytics', label: 'Analytics', icon: ChartPie, permission: 'analytics.view' }, // slice C
];

/** Menu sections. NAV keeps its order (the first allowed item is the person's home); this only groups it. */
const GROUPS: { label: string; paths: string[] }[] = [
  {
    label: 'My career',
    paths: ['/home', '/profile', '/cv', '/skills', '/learning', '/logbook', '/my-project', '/assessments', '/surveys',
      '/career-path'],
  },
  { label: 'Opportunities', paths: ['/jobs', '/workshops', '/network', '/interview'] },
  {
    label: 'CPDC',
    paths: ['/analytics', '/alumni', '/people', '/registrations', '/internships', '/showcase', '/employers', '/learning-checks',
      '/assessment-builder', '/surveys/manage', '/mentoring', '/questions', '/audit'],
  },
];

/** The menu item for a path: the exact item, or the longest item the path sits under (/surveys/manage over /surveys). */
export function currentNav(path: string, to: string, items: NavItem[]): boolean {
  if (path === to) return true;
  if (!path.startsWith(`${to}/`)) return false;
  return !items.some((item) => item.path !== to && item.path.length > to.length && (path === item.path || path.startsWith(`${item.path}/`)));
}

export function navGroups(items: NavItem[]): { label: string; items: NavItem[] }[] {
  const grouped = GROUPS.map((group) => ({
    label: group.label,
    items: group.paths.flatMap((to) => items.filter((item) => item.path === to)),
  }));
  const rest = items.filter((item) => !GROUPS.some((group) => group.paths.includes(item.path)));
  if (rest.length > 0) grouped.push({ label: 'More', items: rest });
  return grouped.filter((group) => group.items.length > 0);
}

export function Shell({
  me,
  path,
  go,
  onSignOut,
  children,
}: {
  me: Me;
  path: string;
  go: (to: string) => void;
  onSignOut: () => void;
  children: ReactNode;
}) {
  const [menuOpen, setMenuOpen] = useState(false);
  const menuButton = useRef<HTMLButtonElement>(null);
  const nav = useRef<HTMLElement>(null);
  const groups = navGroups(NAV.filter((item) => can(me, item.permission)));

  // The phone drawer: opens at the top with focus on the first item, the page behind does not scroll, and Escape
  // closes it and returns focus to the Menu button.
  useEffect(() => {
    if (!menuOpen) return;
    const drawer = nav.current;
    drawer?.scrollTo(0, 0);
    drawer?.querySelector<HTMLElement>('a')?.focus();
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      setMenuOpen(false);
      menuButton.current?.focus();
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = overflow;
      document.removeEventListener('keydown', onKey);
    };
  }, [menuOpen]);
  const follow = (to: string) => (event: MouseEvent) => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
    event.preventDefault();
    setMenuOpen(false);
    go(to);
  };
  return (
    <>
      <header className="shell-top">
        <div className="shell-bar">
          <button
            ref={menuButton}
            type="button"
            className="btn btn-quiet btn-small shell-menu-button"
            aria-expanded={menuOpen}
            aria-controls="shell-nav"
            onClick={() => setMenuOpen((open) => !open)}
          >
            {menuOpen ? <X size={18} aria-hidden="true" /> : <Menu size={18} aria-hidden="true" />}
            <span>Menu</span>
          </button>
          <a className="shell-brand" href="/profile" onClick={follow('/profile')}>
            <img src="/brand/qiu-mark-dark.png" alt="" />
            <span>Career Portal</span>
          </a>
          <div className="shell-user">
            <div className="shell-user-text">
              <strong>{me.user.display_name}</strong>
              <span>{ROLE_LABEL[me.user.role]}</span>
            </div>
            <Avatar name={me.user.display_name} src={me.user.picture_url} size={34} />
            <button type="button" className="btn btn-quiet btn-small" onClick={onSignOut} aria-label="Sign out" title="Sign out">
              <LogOut size={17} aria-hidden="true" />
            </button>
          </div>
        </div>
      </header>
      <div className="shell-body">
        <nav ref={nav} id="shell-nav" className="shell-nav" data-open={menuOpen} aria-label="Main">
          {groups.map((group) => (
            <div key={group.label} className="shell-nav-group">
              <h2>{group.label}</h2>
              {group.items.map(({ path: to, label, icon: Icon }) => (
                <a key={to} href={to} onClick={follow(to)} aria-current={currentNav(path, to, NAV) ? 'page' : undefined}>
                  <Icon size={16} aria-hidden="true" />
                  {label}
                </a>
              ))}
            </div>
          ))}
        </nav>
        <main className="shell-main">{children}</main>
      </div>
    </>
  );
}

export function PageHead({ title, children, actions }: { title: string; children?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="page-head">
      <div>
        <h1>{title}</h1>
        {children && <p>{children}</p>}
      </div>
      {actions && <div className="row">{actions}</div>}
    </div>
  );
}
