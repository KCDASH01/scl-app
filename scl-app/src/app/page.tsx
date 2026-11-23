'use client';

import { useEffect, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useAuthStore } from '@/store/authStore';

const NAV_LINKS = [
  { href: '#home', label: 'Home' },
  { href: '#features', label: 'Features' },
  { href: '#how-it-works', label: 'How it works' },
  { href: '#roles', label: 'Roles' },
];

const FEATURES = [
  {
    title: 'Leads & Follow-ups',
    text: 'Capture every enquiry, assign it to a counsellor, and never miss a follow-up call or meeting.',
    icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    tint: 'from-indigo-500 to-violet-500',
  },
  {
    title: 'Student Admission',
    text: 'Turn a converted lead into an admitted student with course, batch, documents, and an enrollment ID.',
    icon: 'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z',
    tint: 'from-violet-500 to-fuchsia-500',
  },
  {
    title: 'Course Fee Collection',
    text: 'Track course fees, record part payments, see what is pending, and print money receipts.',
    icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    tint: 'from-emerald-500 to-teal-500',
  },
  {
    title: 'Attendance',
    text: 'Pick a course and batch, tick present students, and save attendance for the day in seconds.',
    icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    tint: 'from-sky-500 to-cyan-500',
  },
  {
    title: 'Hostel Expenses',
    text: 'Manage hostels and expense types, and keep a clean, filterable record of every hostel spend.',
    icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    tint: 'from-amber-500 to-orange-500',
  },
  {
    title: 'Reports & Exports',
    text: 'Lead, revenue, and admission reports with filters, search, and CSV or Excel export.',
    icon: 'M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
    tint: 'from-rose-500 to-pink-500',
  },
];

const STEPS = [
  { title: 'Enquiry', text: 'A new lead is added with course interest and assigned to a counsellor.' },
  { title: 'Follow-up', text: 'Calls, meetings, and notes keep the conversation moving.' },
  { title: 'Admission', text: 'The lead is converted and admitted into a course and batch.' },
  { title: 'Course Fee Collection', text: 'Course fees are recorded, part payments tracked, receipts issued.' },
  { title: 'Attendance', text: 'Daily attendance is marked batch by batch.' },
];

const ROLES = [
  {
    name: 'Superadmin',
    text: 'Full control across every branch: branches, courses, team, reports, and which menu items managers and employees can see.',
    points: ['All branches', 'Courses & batches', 'Manage sidebar'],
  },
  {
    name: 'Manager',
    text: 'Runs a branch: assigns leads, manages the team, oversees admissions, fees, and hostel expenses.',
    points: ['Branch team', 'Lead assignment', 'Branch reports'],
  },
  {
    name: 'Employee',
    text: 'Works the day-to-day: follows up with assigned leads, books meetings, and marks attendance.',
    points: ['My leads', 'Follow-ups', 'Attendance'],
  },
];

function Icon({ d, className = 'w-5 h-5' }: { d: string; className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={d} />
    </svg>
  );
}

function ArrowRight({ className = 'w-4 h-4' }: { className?: string }) {
  return <Icon d="M13 7l5 5m0 0l-5 5m5-5H6" className={className} />;
}

export default function HomePage() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const hasHydrated = useAuthStore((s) => s._hasHydrated);
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    document.body.style.overflow = '';
  }, []);

  const goToSection = (e: React.MouseEvent<HTMLAnchorElement>, href: string) => {
    e.preventDefault();
    setMenuOpen(false);

    window.setTimeout(() => {
      const target = document.querySelector<HTMLElement>(href);
      if (!target) return;

      const header = document.querySelector('header');
      const offset = href === '#home' ? 0 : (header?.getBoundingClientRect().height ?? 64);
      const top = href === '#home' ? 0 : Math.max(0, target.getBoundingClientRect().top + window.scrollY - offset);
      const scroller = document.scrollingElement ?? document.documentElement;
      const startY = scroller.scrollTop;

      scroller.scrollTo({ top, behavior: 'smooth' });

      window.setTimeout(() => {
        if (Math.abs(scroller.scrollTop - startY) < 2 && Math.abs(startY - top) > 2) {
          scroller.scrollTop = top;
          window.scrollTo(0, top);
          if (Math.abs((document.scrollingElement ?? document.documentElement).scrollTop - top) > 2) {
            target.scrollIntoView({ block: 'start' });
          }
        }
      }, 350);

      window.history.replaceState(null, '', href);
    }, 0);
  };

  const signedIn = hasHydrated && isAuthenticated;
  const primaryHref = signedIn ? '/dashboard' : '/login';
  const primaryLabel = signedIn ? 'Go to Dashboard' : 'Login';

  return (
    <div className="min-h-screen bg-white text-gray-900 overflow-x-hidden">
      {/* ── Navbar ─────────────────────────────────────────────────────────── */}
      <header
        className={[
          'fixed inset-x-0 top-0 z-50 transition-all duration-300',
          scrolled ? 'bg-white/85 backdrop-blur-xl border-b border-gray-100 shadow-sm' : 'bg-transparent',
        ].join(' ')}
      >
        <nav className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 sm:h-20 flex items-center justify-between gap-4">
          <a href="#home" onClick={(e) => goToSection(e, '#home')} className="flex items-center gap-3 min-w-0">
            <span className="relative w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-white ring-1 ring-gray-100 shadow-sm overflow-hidden shrink-0">
              <Image src="/sairudra-logo.jpg" alt="Sairudra Group of Institution logo" fill sizes="48px" className="object-contain p-0.5" priority />
            </span>
            <span className="min-w-0 leading-tight">
              <span className="block text-sm sm:text-base font-extrabold tracking-tight text-gray-900 truncate">Sairudra</span>
              <span className="block text-[10px] sm:text-xs font-bold text-gray-700 truncate">Group of Institution</span>
            </span>
          </a>

          <div className="hidden md:flex items-center gap-1">
            {NAV_LINKS.map((link) => (
              <a
                key={link.href}
                href={link.href}
                onClick={(e) => goToSection(e, link.href)}
                className="px-4 py-2 rounded-xl text-base font-semibold text-gray-700 hover:text-indigo-700 hover:bg-indigo-50 transition-colors"
              >
                {link.label}
              </a>
            ))}
          </div>

          <div className="flex items-center gap-2">
            <Link
              href={primaryHref}
              className="mkt-btn-primary hidden sm:inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold shadow-lg shadow-indigo-500/25"
            >
              {primaryLabel}
              <ArrowRight />
            </Link>
            <button
              type="button"
              onClick={() => setMenuOpen((open) => !open)}
              aria-label={menuOpen ? 'Close menu' : 'Open menu'}
              aria-expanded={menuOpen}
              className="md:hidden p-2.5 rounded-xl text-gray-700 hover:bg-gray-100 transition-colors"
            >
              <Icon d={menuOpen ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16'} className="w-6 h-6" />
            </button>
          </div>
        </nav>

        {menuOpen && (
          <div className="md:hidden border-t border-gray-100 bg-white/95 backdrop-blur-xl shadow-lg animate-slide-up">
            <div className="px-4 py-4 space-y-1">
              {NAV_LINKS.map((link) => (
                <a
                  key={link.href}
                  href={link.href}
                  onClick={(e) => goToSection(e, link.href)}
                  className="block px-4 py-3 rounded-xl text-base font-semibold text-gray-700 hover:bg-indigo-50 hover:text-indigo-700"
                >
                  {link.label}
                </a>
              ))}
              <Link
                href={primaryHref}
                className="mt-2 flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-indigo-600 text-white text-sm font-semibold"
              >
                {primaryLabel}
                <ArrowRight />
              </Link>
            </div>
          </div>
        )}
      </header>

      {/* ── Hero ───────────────────────────────────────────────────────────── */}
      <section id="home" className="relative mkt-hero-mesh pt-28 sm:pt-36 pb-20 sm:pb-28">
        <div className="pointer-events-none absolute -top-24 -left-24 w-72 h-72 sm:w-96 sm:h-96 bg-indigo-300/30 blur-3xl mkt-blob" />
        <div className="pointer-events-none absolute top-40 -right-24 w-72 h-72 sm:w-96 sm:h-96 bg-violet-300/30 blur-3xl mkt-blob2" />

        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid lg:grid-cols-2 gap-14 lg:gap-10 items-center">
          <div className="text-center lg:text-left">
            <span className="mkt-li-1 inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white border border-indigo-100 shadow-sm text-xs sm:text-sm font-semibold">
              <span className="relative w-2 h-2 rounded-full bg-emerald-500 mkt-pulse-dot" />
              <span className="mkt-shimmer-badge">Admissions &amp; Campus Management</span>
            </span>

            <h1 className="mkt-li-2 mt-6 text-4xl sm:text-5xl xl:text-6xl font-extrabold tracking-tight leading-[1.08]">
              Smarter admissions.
              <br />
              <span className="mkt-gradient-text">Simpler campus.</span>
            </h1>

            <p className="mkt-li-3 mt-6 text-base sm:text-lg text-gray-600 max-w-xl mx-auto lg:mx-0 leading-relaxed">
              One workspace for Sairudra Group of Institution to manage enquiries, follow-ups,
              student admissions, fee collection, attendance, and hostel expenses across every branch.
            </p>

            <div className="mkt-li-4 mt-9 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3">
              <Link
                href={primaryHref}
                className="mkt-btn-primary w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 text-white font-semibold shadow-xl shadow-indigo-500/30"
              >
                {primaryLabel}
                <ArrowRight />
              </Link>
              <a
                href="#features"
                onClick={(e) => goToSection(e, '#features')}
                className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-white border border-gray-200 text-gray-800 font-semibold hover:border-indigo-200 hover:text-indigo-700 transition-colors"
              >
                Explore features
              </a>
            </div>

            <div className="mkt-li-5 mt-10 flex flex-wrap items-center justify-center lg:justify-start gap-x-6 gap-y-2 text-xs sm:text-sm text-gray-500">
              {['Multi-branch', 'Course & batch based', 'Role-based access'].map((item) => (
                <span key={item} className="inline-flex items-center gap-1.5">
                  <Icon d="M5 13l4 4L19 7" className="w-4 h-4 text-emerald-500" />
                  {item}
                </span>
              ))}
            </div>
          </div>

          {/* Logo showcase */}
          <div className="mkt-li-3 relative mx-auto w-full max-w-md lg:max-w-lg">
            <div className="absolute inset-6 rounded-[2.5rem] bg-gradient-to-br from-indigo-500 via-violet-500 to-fuchsia-500 opacity-25 blur-2xl" />
            <div className="relative rounded-[2rem] bg-white/90 backdrop-blur border border-white shadow-2xl shadow-indigo-500/15 p-6 sm:p-10">
              <div className="relative aspect-square w-full">
                <Image
                  src="/sairudra-logo.jpg"
                  alt="Sairudra Group of Institution"
                  fill
                  sizes="(max-width: 1024px) 80vw, 480px"
                  className="object-contain"
                  priority
                />
              </div>
            </div>

            <div className="mkt-float-a absolute -left-3 sm:-left-10 top-8 sm:top-12 w-44 sm:w-52 rounded-2xl bg-white border border-gray-100 shadow-xl p-3.5">
              <div className="flex items-center gap-2.5">
                <span className="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                  <Icon d={FEATURES[0].icon} className="w-[18px] h-[18px]" />
                </span>
                <div className="min-w-0">
                  <p className="text-xs font-semibold text-gray-900">New enquiry</p>
                  <p className="text-[11px] text-gray-400 truncate">Assigned to counsellor</p>
                </div>
              </div>
            </div>

            <div className="mkt-float-b absolute -right-3 sm:-right-8 top-1/2 w-44 sm:w-52 rounded-2xl bg-white border border-gray-100 shadow-xl p-3.5">
              <div className="flex items-center gap-2.5">
                <span className="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                  <Icon d={FEATURES[2].icon} className="w-[18px] h-[18px]" />
                </span>
                <div className="min-w-0">
                  <p className="text-xs font-semibold text-gray-900">Fee received</p>
                  <p className="text-[11px] text-gray-400 truncate">Receipt generated</p>
                </div>
              </div>
            </div>

            <div className="mkt-float-c absolute left-4 sm:left-8 -bottom-6 w-48 sm:w-56 rounded-2xl bg-white border border-gray-100 shadow-xl p-3.5">
              <div className="flex items-center justify-between mb-2">
                <p className="text-xs font-semibold text-gray-900">Today&apos;s attendance</p>
                <span className="text-[10px] font-semibold text-sky-600 bg-sky-50 px-1.5 py-0.5 rounded-full">Batch</span>
              </div>
              <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
                <div className="h-full w-[88%] rounded-full bg-gradient-to-r from-sky-500 to-indigo-500" />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── Features ───────────────────────────────────────────────────────── */}
      <section id="features" className="scroll-mt-16 sm:scroll-mt-20 py-20 sm:py-28 bg-gray-50/70">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="max-w-2xl mx-auto text-center">
            <p className="text-sm font-semibold text-indigo-600 uppercase tracking-widest">Features</p>
            <h2 className="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">
              Everything the campus office needs
            </h2>
            <p className="mt-4 text-gray-600">
              From the first enquiry to daily attendance, every step lives in one place.
            </p>
          </div>

          <div className="mt-14 grid sm:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6">
            {FEATURES.map((feature) => (
              <div
                key={feature.title}
                className="mkt-card-lift group rounded-2xl bg-white border border-gray-100 p-6 sm:p-7 shadow-sm"
              >
                <span className={`inline-flex w-12 h-12 rounded-xl bg-gradient-to-br ${feature.tint} text-white items-center justify-center shadow-lg`}>
                  <Icon d={feature.icon} className="w-6 h-6" />
                </span>
                <h3 className="mt-5 text-lg font-bold text-gray-900">{feature.title}</h3>
                <p className="mt-2 text-sm text-gray-600 leading-relaxed">{feature.text}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── How it works ───────────────────────────────────────────────────── */}
      <section id="how-it-works" className="scroll-mt-16 sm:scroll-mt-20 py-20 sm:py-28">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="max-w-2xl mx-auto text-center">
            <p className="text-sm font-semibold text-indigo-600 uppercase tracking-widest">How it works</p>
            <h2 className="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">
              From enquiry to classroom
            </h2>
            <p className="mt-4 text-gray-600">A clear path every student follows, visible to the whole team.</p>
          </div>

          <ol className="mt-14 relative grid gap-6 md:grid-cols-5">
            <div className="hidden md:block absolute top-6 left-[10%] right-[10%] h-0.5 bg-gradient-to-r from-indigo-200 via-violet-200 to-sky-200" />
            {STEPS.map((step, index) => (
              <li key={step.title} className="relative flex md:flex-col items-start md:items-center gap-4 md:gap-0 md:text-center">
                <span className="relative z-10 shrink-0 w-12 h-12 rounded-full bg-gradient-to-br from-indigo-600 to-violet-600 text-white font-bold flex items-center justify-center shadow-lg shadow-indigo-500/30 ring-4 ring-white">
                  {index + 1}
                </span>
                <div className="md:mt-5">
                  <h3 className="font-bold text-gray-900">{step.title}</h3>
                  <p className="mt-1.5 text-sm text-gray-600 leading-relaxed md:px-2">{step.text}</p>
                </div>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* ── Roles ──────────────────────────────────────────────────────────── */}
      <section id="roles" className="scroll-mt-16 sm:scroll-mt-20 py-20 sm:py-28 bg-gray-950 text-white relative overflow-hidden">
        <div className="pointer-events-none absolute -top-32 left-1/4 w-96 h-96 bg-indigo-600/25 blur-3xl rounded-full" />
        <div className="pointer-events-none absolute -bottom-32 right-1/4 w-96 h-96 bg-violet-600/25 blur-3xl rounded-full" />

        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="max-w-2xl mx-auto text-center">
            <p className="text-sm font-semibold text-indigo-300 uppercase tracking-widest">Roles</p>
            <h2 className="mt-3 text-3xl sm:text-4xl font-extrabold tracking-tight">The right view for every person</h2>
            <p className="mt-4 text-gray-400">Each role sees only what it needs, scoped to its branch.</p>
          </div>

          <div className="mt-14 grid md:grid-cols-3 gap-5 sm:gap-6">
            {ROLES.map((role) => (
              <div
                key={role.name}
                className="rounded-2xl bg-white/[0.04] border border-white/10 p-6 sm:p-7 backdrop-blur hover:bg-white/[0.07] hover:border-indigo-400/40 transition-colors"
              >
                <h3 className="text-xl font-bold">{role.name}</h3>
                <p className="mt-3 text-sm text-gray-400 leading-relaxed">{role.text}</p>
                <ul className="mt-5 flex flex-wrap gap-2">
                  {role.points.map((point) => (
                    <li key={point} className="text-xs font-medium text-indigo-200 bg-indigo-500/15 border border-indigo-400/20 px-2.5 py-1 rounded-full">
                      {point}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── CTA ────────────────────────────────────────────────────────────── */}
      <section className="py-20 sm:py-24">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 px-6 py-12 sm:px-12 sm:py-16 text-center shadow-2xl shadow-indigo-500/30">
            <div className="pointer-events-none absolute -top-16 -right-16 w-64 h-64 rounded-full bg-white/10 blur-2xl" />
            <div className="pointer-events-none absolute -bottom-20 -left-10 w-64 h-64 rounded-full bg-white/10 blur-2xl" />
            <h2 className="relative text-2xl sm:text-4xl font-extrabold text-white tracking-tight">
              Ready to run the campus office in one place?
            </h2>
            <p className="relative mt-4 text-indigo-100 max-w-xl mx-auto">
              Sign in to manage leads, admissions, fees, and attendance for your branch.
            </p>
            <Link
              href={primaryHref}
              className="relative mt-8 inline-flex items-center gap-2 px-7 py-3.5 rounded-xl bg-white text-indigo-700 font-semibold shadow-lg hover:-translate-y-0.5 hover:shadow-xl transition-all"
            >
              {primaryLabel}
              <ArrowRight />
            </Link>
          </div>
        </div>
      </section>

      {/* ── Footer ─────────────────────────────────────────────────────────── */}
      <footer className="border-t border-gray-100 bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 flex flex-col md:flex-row items-center justify-between gap-6">
          <div className="flex items-center gap-3">
            <span className="relative w-11 h-11 rounded-xl bg-white ring-1 ring-gray-100 overflow-hidden">
              <Image src="/sairudra-logo.jpg" alt="" fill sizes="44px" className="object-contain p-0.5" />
            </span>
            <div className="leading-tight">
              <p className="font-bold text-gray-900">Sairudra Group of Institution</p>
              <p className="text-xs text-gray-500">Admissions &amp; Campus CRM</p>
            </div>
          </div>

          <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-gray-500">
            {NAV_LINKS.map((link) => (
              <a key={link.href} href={link.href} onClick={(e) => goToSection(e, link.href)} className="hover:text-indigo-600 transition-colors">
                {link.label}
              </a>
            ))}
          </div>

          <div className="flex items-center gap-2 text-xs text-gray-400">
            <span>Powered by</span>
            <Image src="/KanpriTechText.png" alt="KanpriTech" width={96} height={24} className="h-5 w-auto object-contain" />
          </div>
        </div>
        <div className="border-t border-gray-100 py-5 text-center text-xs text-gray-400">
          &copy; {new Date().getFullYear()} Sairudra Group of Institution. All rights reserved.
        </div>
      </footer>
    </div>
  );
}
