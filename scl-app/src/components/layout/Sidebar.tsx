'use client';

import { useEffect, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { usePathname, useSearchParams } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useUiStore } from '@/store/uiStore';
import { isSidebarHrefVisible } from '@/lib/api/sidebar';
import { roleLabel } from '@/lib/roles';

function NavIcon({ d }: { d: string }) {
  return (
    <svg className="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={d} />
    </svg>
  );
}

interface NavItem { href: string; label: string; icon: string; children?: { href: string; label: string }[] }

const HOSTEL_CHILDREN = [
  { href: '/hostel-expenses', label: 'Expenses' },
  { href: '/hostel-expenses?tab=hostels', label: 'Hostels' },
  { href: '/hostel-expenses?tab=types', label: 'Expense types' },
];

const SECTIONS: { label: string; items: NavItem[] }[] = [
  {
    label: 'Workspace',
    items: [
      { href: '/dashboard',        label: 'Dashboard',         icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
      { href: '/leads',            label: 'Leads',             icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
      { href: '/follow-ups',       label: 'FollowUp',          icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
      { href: '/meetings',         label: 'To Visit',          icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
      { href: '/important',        label: 'Important',         icon: 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z' },
      { href: '/admissions',       label: 'Student Admission', icon: 'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z' },
      { href: '/deals',            label: 'Course Fee Collection', icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
      { href: '/hostel-fees',      label: 'Hostel Fee Collection', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
      { href: '/attendance',       label: 'Student Attendance', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4' },
      { href: '/staff-attendance', label: 'Staff Attendance',   icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
      { href: '/hostel-expenses',  label: 'Hostel Expenses',   icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', children: HOSTEL_CHILDREN },
      { href: '/pipelines',        label: 'Pipelines',         icon: 'M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2' },
      { href: '/activities',       label: 'Activities',        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4' },
      { href: '/calendar',         label: 'Calendar',          icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
      { href: '/reports',          label: 'Reports',           icon: 'M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z' },
      { href: '/targets',          label: 'Targets',           icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' },
    ],
  },
  {
    label: 'Admin',
    items: [
      { href: '/branches',    label: 'Branches',    icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
      { href: '/courses',     label: 'Courses',     icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253' },
      { href: '/batch-years', label: 'Batch Year',  icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
      { href: '/team',            label: 'Team',            icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z' },
      { href: '/roles',           label: 'Add Role',        icon: 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z' },
      { href: '/departments',     label: 'Departments',     icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4' },
      { href: '/manage-sidebar',  label: 'Manage Sidebar',  icon: 'M4 6h16M4 12h16M4 18h7' },
    ],
  },
  {
    label: 'Account',
    items: [
      { href: '/settings', label: 'Settings', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
    ],
  },
];

export default function Sidebar() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { user, canManageTeam, isOwner, clearAuth } = useAuthStore();
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const onHostelPage = pathname.startsWith('/hostel-expenses');
  const [hostelOpen, setHostelOpen] = useState(onHostelPage);
  const mobileNavOpen = useUiStore((s) => s.mobileNavOpen);
  const setMobileNavOpen = useUiStore((s) => s.setMobileNavOpen);

  useEffect(() => {
    if (onHostelPage) setHostelOpen(true);
  }, [onHostelPage]);

  useEffect(() => {
    setMobileNavOpen(false);
  }, [pathname, searchParams, setMobileNavOpen]);

  useEffect(() => {
    if (!mobileNavOpen) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setMobileNavOpen(false); };
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = prevOverflow;
      window.removeEventListener('keydown', onKey);
    };
  }, [mobileNavOpen, setMobileNavOpen]);

  const isVisible = (href: string) => {
    if (href === '/follow-ups') return false;
    if (href === '/branches' || href === '/courses' || href === '/roles' || href === '/manage-sidebar') return isOwner();
    if (href === '/team' || href === '/departments' || href === '/batch-years' || href === '/hostel-expenses' || href === '/staff-attendance') {
      if (!canManageTeam()) return false;
    }
    if (isOwner()) return true;
    return isSidebarHrefVisible(href, user?.sidebar);
  };

  const isActive = (href: string) =>
    pathname === href || (href !== '/dashboard' && pathname.startsWith(href));

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      const { authApi } = await import('@/lib/api/auth');
      await authApi.logout();
    } catch { /* ignore */ } finally {
      clearAuth();
      window.location.href = '/login';
    }
  };

  return (
    <>
    {mobileNavOpen && (
      <div
        className="lg:hidden fixed inset-0 z-40 bg-black/50 backdrop-blur-[2px] animate-fade-in"
        onClick={() => setMobileNavOpen(false)}
        aria-hidden="true"
      />
    )}
    <aside
      className={[
        'w-64 bg-gray-950 flex flex-col h-full shrink-0 border-r border-white/5',
        'fixed inset-y-0 left-0 z-50 transition-transform duration-300 ease-out lg:static lg:z-auto lg:transform-none',
        mobileNavOpen ? 'translate-x-0 shadow-2xl shadow-black/40' : '-translate-x-full',
      ].join(' ')}
    >

      {/* Logo */}
      <div className="flex items-center gap-3 px-5 py-4 border-b border-white/5">
        <Link href="/dashboard" className="flex items-center gap-3 min-w-0 flex-1">
          <span className="relative w-10 h-10 rounded-xl bg-white overflow-hidden shrink-0 shadow-lg shadow-black/30 ring-1 ring-white/10">
            <Image src="/sairudra-logo.jpg" alt="Sairudra Group of Institution" fill sizes="40px" className="object-contain p-0.5" priority />
          </span>
          <span className="text-white font-bold text-base tracking-tight">CRM</span>
        </Link>
        <button
          type="button"
          onClick={() => setMobileNavOpen(false)}
          aria-label="Close menu"
          className="lg:hidden p-2 -mr-2 rounded-lg text-gray-500 hover:text-white hover:bg-white/10 transition-colors"
        >
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      {/* Nav */}
      <nav className="flex-1 px-3 py-4 space-y-5 overflow-y-auto">
        {SECTIONS.map((section) => {
          const visible = section.items.filter((item) => isVisible(item.href));
          if (!visible.length) return null;
          return (
            <div key={section.label}>
              <p className="px-3 mb-1.5 text-[10px] font-semibold tracking-widest text-gray-600 uppercase select-none">
                {section.label}
              </p>
              <div className="space-y-0.5">
                {visible.map((item) => {
                  const active = isActive(item.href);
                  const expanded = Boolean(item.children && hostelOpen);

                  if (item.children) {
                    const currentTab = searchParams.get('tab') || 'expenses';
                    return (
                      <div key={item.href}>
                        <button
                          type="button"
                          onClick={() => setHostelOpen((open) => !open)}
                          className={[
                            'w-full flex items-center gap-3 pl-[10px] pr-3 py-2.5 rounded-xl text-sm font-medium',
                            'transition-all duration-150 group relative border-l-2',
                            active
                              ? 'bg-white/10 text-white border-indigo-400'
                              : 'text-gray-500 hover:bg-white/5 hover:text-gray-200 border-transparent',
                          ].join(' ')}
                        >
                          <span className={`transition-colors duration-150 ${
                            active ? 'text-indigo-400' : 'text-gray-600 group-hover:text-gray-400'
                          }`}>
                            <NavIcon d={item.icon} />
                          </span>
                          <span className="flex-1 text-left">{item.label}</span>
                          <svg
                            className={`w-3.5 h-3.5 shrink-0 transition-transform ${expanded ? 'rotate-180 text-gray-300' : 'text-gray-600'}`}
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                          >
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                          </svg>
                        </button>
                        {expanded && (
                          <div className="mt-0.5 ml-4 pl-3 border-l border-white/10 space-y-0.5">
                            {item.children.map((child) => {
                              const childTab = new URL(child.href, 'http://local').searchParams.get('tab') || 'expenses';
                              const childActive = pathname.startsWith('/hostel-expenses') && currentTab === childTab;
                              return (
                                <Link
                                  key={child.href}
                                  href={child.href}
                                  className={[
                                    'block px-3 py-2 rounded-lg text-[13px] font-medium transition-colors',
                                    childActive
                                      ? 'bg-white/10 text-white'
                                      : 'text-gray-500 hover:bg-white/5 hover:text-gray-200',
                                  ].join(' ')}
                                >
                                  {child.label}
                                </Link>
                              );
                            })}
                          </div>
                        )}
                      </div>
                    );
                  }

                  return (
                    <Link
                      key={item.href}
                      href={item.href}
                      className={[
                        'flex items-center gap-3 pl-[10px] pr-3 py-2.5 rounded-xl text-sm font-medium',
                        'transition-all duration-150 group relative border-l-2',
                        active
                          ? 'bg-white/10 text-white border-indigo-400'
                          : 'text-gray-500 hover:bg-white/5 hover:text-gray-200 border-transparent',
                      ].join(' ')}
                    >
                      <span className={`transition-colors duration-150 ${
                        active ? 'text-indigo-400' : 'text-gray-600 group-hover:text-gray-400'
                      }`}>
                        <NavIcon d={item.icon} />
                      </span>
                      <span className="flex-1">{item.label}</span>
                      {active && (
                        <span className="w-1.5 h-1.5 rounded-full bg-indigo-400 shrink-0" />
                      )}
                    </Link>
                  );
                })}
              </div>
            </div>
          );
        })}
      </nav>

      {/* User */}
      <div className="px-4 py-4 border-t border-white/5">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500
                          flex items-center justify-center text-white text-sm font-bold shrink-0 shadow-md">
            {user?.name?.[0]?.toUpperCase() ?? 'U'}
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold text-white truncate leading-tight">{user?.name}</p>
            <span className="text-xs text-gray-500 truncate">{roleLabel(user?.role)}</span>
          </div>
          <button
            onClick={() => setShowLogoutConfirm(true)}
            title="Logout"
            className="text-gray-600 hover:text-red-400 transition-colors duration-150
                       p-1.5 rounded-lg hover:bg-red-500/10"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </button>
        </div>
      </div>
    </aside>

      {/* ── Logout confirmation modal ─────────────────────────────────────── */}
      {showLogoutConfirm && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
          {/* Backdrop */}
          <div
            className="absolute inset-0 bg-black/60 backdrop-blur-sm"
            onClick={() => !loggingOut && setShowLogoutConfirm(false)}
          />

          {/* Card */}
          <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col gap-4">
            {/* Icon */}
            <div className="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mx-auto">
              <svg className="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
            </div>

            {/* Text */}
            <div className="text-center">
              <h2 className="text-lg font-semibold text-gray-900">Sign out?</h2>
              <p className="mt-1 text-sm text-gray-500">
                Are you sure you want to sign out of your account?
              </p>
            </div>

            {/* Actions */}
            <div className="flex gap-3 mt-1">
              <button
                onClick={() => setShowLogoutConfirm(false)}
                disabled={loggingOut}
                className="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium
                           text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={handleLogout}
                disabled={loggingOut}
                className="flex-1 px-4 py-2.5 rounded-xl bg-red-500 hover:bg-red-600 text-white
                           text-sm font-medium transition-colors disabled:opacity-70 flex items-center
                           justify-center gap-2"
              >
                {loggingOut ? (
                  <>
                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                    </svg>
                    Signing out…
                  </>
                ) : (
                  'Proceed'
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
