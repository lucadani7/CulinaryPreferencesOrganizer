import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import ThemeToggle from './ThemeToggle';

interface NavItem {
  to: string;
  label: string;
  icon: string;
  /** Custom "active" rule; by default a link is active on its path and everything below it. */
  match?: (pathname: string) => boolean;
}

const NAV: NavItem[] = [
  { to: '/', label: 'Home', icon: '🏠', match: (p) => p === '/' },
  {
    to: '/products',
    label: 'Products',
    icon: '🧺',
    // product pages (/products/5, /products/new) but not the Open Food Facts search
    match: (p) => p.startsWith('/products') && !p.startsWith('/products/import'),
  },
  { to: '/products/import', label: 'Open Food Facts', icon: '🔎' },
  { to: '/preferences', label: 'Preferences', icon: '❤️' },
  { to: '/shopping-lists', label: 'Shopping lists', icon: '📝' },
  { to: '/groups', label: 'Groups', icon: '👪' },
  { to: '/restaurants', label: 'Restaurants', icon: '🍽️' },
  { to: '/stats', label: 'Statistics', icon: '📊' },
];

const ADMIN_NAV: NavItem[] = [
  { to: '/admin/users', label: 'Users', icon: '🛡️' },
  { to: '/admin/taxonomy', label: 'Categories & allergens', icon: '🏷️' },
];

export default function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const [open, setOpen] = useState(false);

  // the mobile menu closes on navigation and with Escape
  useEffect(() => setOpen(false), [pathname]);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  const link = (n: NavItem) => (
    <NavLink
      key={n.to}
      to={n.to}
      className={({ isActive }) =>
        `nav__link ${(n.match ? n.match(pathname) : isActive) ? 'active' : ''}`
      }
    >
      <span aria-hidden>{n.icon}</span> {n.label}
    </NavLink>
  );

  const onLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  return (
    <div className="shell">
      <aside className={`sidebar ${open ? 'sidebar--open' : ''}`}>
        <Link to="/" className="brand">
          <span className="brand__logo" aria-hidden>
            🥕
          </span>
          <span className="brand__name">Culinary Preferences Organizer</span>
        </Link>
        <nav className="nav" aria-label="Main">
          {NAV.map(link)}
        </nav>
        {user?.role === 'admin' && (
          <>
            <div className="nav__section">Administration</div>
            <nav className="nav" aria-label="Administration">
              {ADMIN_NAV.map(link)}
            </nav>
          </>
        )}
        <div className="sidebar__footer">
          <small>
            Product data:{' '}
            <a href="https://world.openfoodfacts.org" target="_blank" rel="noreferrer">
              Open Food Facts
            </a>{' '}
            (ODbL)
          </small>
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <button
            type="button"
            className="icon-btn topbar__menu"
            onClick={() => setOpen((o) => !o)}
            aria-label="Menu"
            aria-expanded={open}
          >
            ☰
          </button>
          <div className="topbar__spacer" />
          <ThemeToggle />
          <Link to="/profile" className="user-chip" title="Your profile">
            <span className="avatar" aria-hidden>
              {user?.name.slice(0, 1).toUpperCase()}
            </span>
            {/* hidden on phones: the avatar alone links to the profile */}
            <span className="user-chip__name">
              {user?.name}
              {user?.role === 'admin' && <small className="muted"> · admin</small>}
            </span>
          </Link>
          <button type="button" className="btn btn--ghost topbar__logout" onClick={() => void onLogout()}>
            Log out
          </button>
        </header>
        <main className="content">
          <Outlet />
        </main>
      </div>
      {open && <div className="sidebar-backdrop" onClick={() => setOpen(false)} aria-hidden />}
    </div>
  );
}