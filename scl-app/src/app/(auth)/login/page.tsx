'use client';

import { useEffect, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { authApi } from '@/lib/api/auth';
import { useAuthStore } from '@/store/authStore';

const HIGHLIGHTS = [
  'Leads, follow-ups and meetings in one place',
  'Student admission with course and batch',
  'Fee collection with receipts',
  'Batch-wise attendance and hostel expenses',
];

function Icon({ d, className = 'w-5 h-5' }: { d: string; className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={d} />
    </svg>
  );
}

const inputCls =
  'w-full rounded-xl border border-gray-200 bg-gray-50/60 pl-11 pr-4 py-3 text-sm text-gray-900 placeholder:text-gray-400 ' +
  'transition-colors focus:bg-white focus:border-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-500/15';

export default function LoginPage() {
  const router = useRouter();
  const { setAuth } = useAuthStore();
  const [form, setForm] = useState({ email: '', password: '' });
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (new URLSearchParams(window.location.search).get('reset') === '1') {
      setNotice('Password reset successfully. Please sign in with your new password.');
    }
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const { token, user } = await authApi.login(form);
      setAuth(user, token);
      router.push('/dashboard');
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg ?? 'Invalid credentials. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex bg-white">
      {/* ── Brand panel (desktop) ─────────────────────────────────────────── */}
      <aside className="hidden lg:flex lg:w-1/2 xl:w-[55%] relative overflow-hidden bg-gradient-to-br from-indigo-700 via-violet-700 to-fuchsia-700 text-white">
        <div className="pointer-events-none absolute -top-32 -left-32 w-[28rem] h-[28rem] rounded-full bg-white/10 blur-3xl mkt-blob" />
        <div className="pointer-events-none absolute -bottom-40 -right-24 w-[30rem] h-[30rem] rounded-full bg-indigo-400/20 blur-3xl mkt-blob2" />
        <div
          className="pointer-events-none absolute inset-0 opacity-[0.07]"
          style={{
            backgroundImage:
              'linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px)',
            backgroundSize: '44px 44px',
          }}
        />

        <div className="relative z-10 flex flex-col justify-between w-full p-10 xl:p-14">
          <Link href="/" className="inline-flex items-center gap-2 text-sm font-medium text-indigo-100 hover:text-white transition-colors w-fit">
            <Icon d="M10 19l-7-7m0 0l7-7m-7 7h18" className="w-4 h-4" />
            Back to home
          </Link>

          <div className="max-w-lg">
            <div className="mkt-float-c inline-flex rounded-3xl bg-white p-3 shadow-2xl shadow-black/20">
              <span className="relative block w-28 h-28 xl:w-32 xl:h-32">
                <Image src="/sairudra-logo.jpg" alt="Sairudra Group of Institution" fill sizes="128px" className="object-contain" priority />
              </span>
            </div>

            <h2 className="mt-8 text-3xl xl:text-4xl font-extrabold tracking-tight leading-tight">
              Sairudra Group of Institution
            </h2>
            <p className="mt-3 text-indigo-100 text-base xl:text-lg leading-relaxed">
              Admissions &amp; Campus CRM. Everything your office needs, from the first enquiry to daily attendance.
            </p>

            <ul className="mt-8 space-y-3">
              {HIGHLIGHTS.map((item) => (
                <li key={item} className="flex items-start gap-3 text-sm text-indigo-50">
                  <span className="mt-0.5 shrink-0 w-5 h-5 rounded-full bg-white/15 ring-1 ring-white/25 flex items-center justify-center">
                    <Icon d="M5 13l4 4L19 7" className="w-3 h-3" />
                  </span>
                  {item}
                </li>
              ))}
            </ul>
          </div>

          <p className="text-xs text-indigo-200">
            &copy; {new Date().getFullYear()} Sairudra Group of Institution
          </p>
        </div>
      </aside>

      {/* ── Form ──────────────────────────────────────────────────────────── */}
      <main className="flex-1 relative flex flex-col bg-gray-50/60">
        <div className="lg:hidden pointer-events-none absolute inset-0 mkt-hero-mesh" />
        <div className="lg:hidden relative flex items-center justify-between px-5 pt-5">
          <Link href="/" className="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-indigo-600 transition-colors">
            <Icon d="M10 19l-7-7m0 0l7-7m-7 7h18" className="w-4 h-4" />
            Home
          </Link>
        </div>

        <div className="relative flex-1 flex items-center justify-center px-5 py-10 sm:px-8">
          <div className="w-full max-w-md">
            {/* Mobile / tablet logo */}
            <div className="lg:hidden text-center mb-8">
              <span className="relative mx-auto block w-24 h-24 sm:w-28 sm:h-28 rounded-3xl bg-white p-2 shadow-xl shadow-indigo-500/10 ring-1 ring-gray-100">
                <span className="relative block w-full h-full">
                  <Image src="/sairudra-logo.jpg" alt="Sairudra Group of Institution" fill sizes="112px" className="object-contain" priority />
                </span>
              </span>
              <p className="mt-4 text-sm font-semibold text-gray-700">Sairudra Group of Institution</p>
            </div>

            <div className="rounded-3xl bg-white border border-gray-100 shadow-xl shadow-indigo-500/[0.07] p-6 sm:p-9 animate-slide-up">
              <div className="mb-7">
                <h1 className="text-2xl sm:text-[1.75rem] font-extrabold tracking-tight text-gray-900">Welcome back</h1>
                <p className="mt-1.5 text-sm text-gray-500">Sign in to continue to your CRM account.</p>
              </div>

              <form onSubmit={handleSubmit} className="space-y-5">
                {notice && !error && (
                  <div className="flex items-start gap-2.5 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
                    <Icon d="M5 13l4 4L19 7" className="w-4 h-4 mt-0.5 shrink-0" />
                    {notice}
                  </div>
                )}

                {error && (
                  <div className="flex items-start gap-2.5 px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
                    <Icon d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" className="w-4 h-4 mt-0.5 shrink-0" />
                    <div>
                      {error}
                      {error.toLowerCase().includes('password') && (
                        <div className="mt-1.5">
                          <Link href="/forgot-password" className="font-semibold underline text-red-700 hover:text-red-900">
                            Reset password →
                          </Link>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                <div>
                  <label htmlFor="email" className="block text-sm font-semibold text-gray-700 mb-1.5">Email</label>
                  <div className="relative">
                    <span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                      <Icon d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" className="w-[18px] h-[18px]" />
                    </span>
                    <input
                      id="email"
                      type="email"
                      required
                      autoComplete="email"
                      placeholder="you@example.com"
                      value={form.email}
                      onChange={(e) => setForm({ ...form, email: e.target.value })}
                      className={inputCls}
                    />
                  </div>
                </div>

                <div>
                  <div className="flex items-center justify-between mb-1.5">
                    <label htmlFor="password" className="block text-sm font-semibold text-gray-700">Password</label>
                    <Link href="/forgot-password" className="text-xs sm:text-sm font-medium text-indigo-600 hover:text-indigo-700">
                      Forgot password?
                    </Link>
                  </div>
                  <div className="relative">
                    <span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                      <Icon d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" className="w-[18px] h-[18px]" />
                    </span>
                    <input
                      id="password"
                      type={showPassword ? 'text' : 'password'}
                      required
                      autoComplete="current-password"
                      placeholder="Enter your password"
                      value={form.password}
                      onChange={(e) => setForm({ ...form, password: e.target.value })}
                      className={`${inputCls} pr-12`}
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((v) => !v)}
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                      className="absolute right-2 top-1/2 -translate-y-1/2 p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
                    >
                      <Icon
                        d={
                          showPassword
                            ? 'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21'
                            : 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'
                        }
                        className="w-[18px] h-[18px]"
                      />
                    </button>
                  </div>
                </div>

                <button
                  type="submit"
                  disabled={loading}
                  className="mkt-btn-primary w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 py-3 px-4 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 disabled:opacity-60 disabled:hover:translate-y-0"
                >
                  {loading ? (
                    <>
                      <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                      </svg>
                      Signing in…
                    </>
                  ) : (
                    <>
                      Sign in
                      <Icon d="M13 7l5 5m0 0l-5 5m5-5H6" className="w-4 h-4" />
                    </>
                  )}
                </button>
              </form>
            </div>

            <p className="mt-6 text-center text-xs text-gray-400">
              Access is provided by your institution administrator.
            </p>
          </div>
        </div>
      </main>
    </div>
  );
}
