'use client';

import { useEffect } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { authApi } from '@/lib/api/auth';
import { isHiddenDashboardPath } from '@/lib/api/sidebar';
import { useAuthStore } from '@/store/authStore';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { ScheduleAlertFab } from './ScheduleAlertFab';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { user, setUser, isOwner } = useAuthStore();

  const { data: freshMe } = useQuery({
    queryKey: ['me'],
    queryFn: authApi.me,
    staleTime: 30_000,
    refetchOnWindowFocus: true,
  });

  useEffect(() => {
    if (freshMe) setUser(freshMe);
  }, [freshMe, setUser]);

  const sidebar = freshMe?.sidebar ?? user?.sidebar;
  const hidden = !isOwner() && isHiddenDashboardPath(pathname, sidebar);

  useEffect(() => {
    if (hidden) router.replace('/dashboard');
  }, [hidden, router]);

  return (
    <div className="flex h-screen overflow-hidden bg-gray-50">
      <Sidebar />
      <div className="flex-1 min-w-0 flex flex-col overflow-hidden relative">
        <Topbar />
        <main className="flex-1 overflow-y-auto overflow-x-hidden p-3 sm:p-5 lg:p-6">
          {hidden ? null : children}
        </main>
      </div>
      <ScheduleAlertFab />
    </div>
  );
}
