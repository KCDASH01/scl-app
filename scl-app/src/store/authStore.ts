'use client';

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { User } from '@/types';

const SESSION_TTL_MS = 30 * 24 * 60 * 60 * 1000; // 30 days

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  expiresAt: number | null;
  _hasHydrated: boolean;

  setAuth: (user: User, token: string) => void;
  setUser: (user: User) => void;
  clearAuth: () => void;
  setHasHydrated: (v: boolean) => void;
  extendSession: () => void;

  isOwner: () => boolean;
  isSuperAdmin: () => boolean;
  isAdmin: () => boolean;
  isEmployee: () => boolean;
  canManageTeam: () => boolean;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      expiresAt: null,
      _hasHydrated: false,

      setAuth: (user, token) => {
        localStorage.setItem('crm_token', token);
        set({
          user,
          token,
          isAuthenticated: true,
          expiresAt: Date.now() + SESSION_TTL_MS,
        });
      },

      setUser: (user) => set({ user }),

      clearAuth: () => {
        localStorage.removeItem('crm_token');
        localStorage.removeItem('crm_auth');
        set({ user: null, token: null, isAuthenticated: false, expiresAt: null });
      },

      setHasHydrated: (v) => set({ _hasHydrated: v }),

      extendSession: () => {
        if (get().isAuthenticated) {
          set({ expiresAt: Date.now() + SESSION_TTL_MS });
        }
      },

      isOwner: () => ['superadmin', 'owner'].includes(get().user?.role ?? ''),
      isSuperAdmin: () => ['superadmin', 'owner'].includes(get().user?.role ?? ''),
      isAdmin: () => ['superadmin', 'admin', 'manager', 'owner'].includes(get().user?.role ?? ''),
      isEmployee: () => get().user?.role === 'employee',
      canManageTeam: () => ['superadmin', 'admin', 'manager', 'owner'].includes(get().user?.role ?? ''),
    }),
    {
      name: 'crm_auth',
      partialize: (state) => ({
        user: state.user,
        token: state.token,
        isAuthenticated: state.isAuthenticated,
        expiresAt: state.expiresAt,
      }),
      onRehydrateStorage: () => (state) => {
        if (!state) return;

        if (state.expiresAt && Date.now() > state.expiresAt) {
          localStorage.removeItem('crm_token');
          state.clearAuth();
        } else {
          state.extendSession();
        }

        state.setHasHydrated(true);
      },
    }
  )
);
