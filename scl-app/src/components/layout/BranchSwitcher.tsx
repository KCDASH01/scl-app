'use client';

import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { branchesApi } from '@/lib/api/branches';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';

export function BranchSwitcher() {
  const { user, isSuperAdmin } = useAuthStore();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const setSelectedBranchId = useBranchStore((s) => s.setSelectedBranchId);

  const { data: branches = [], isSuccess: branchesLoaded } = useQuery({
    queryKey: ['branches'],
    queryFn: () => branchesApi.list(),
    enabled: Boolean(user),
    staleTime: 60_000,
  });

  useEffect(() => {
    if (!isSuperAdmin() || !branchesLoaded) return;
    if (selectedBranchId !== 'all' && !branches.some((b) => b.id === selectedBranchId)) {
      setSelectedBranchId('all');
    }
  }, [branches, branchesLoaded, selectedBranchId, isSuperAdmin, setSelectedBranchId]);

  if (isSuperAdmin()) {
    return (
      <select
        value={selectedBranchId === 'all' ? 'all' : String(selectedBranchId)}
        onChange={(e) => {
          const value = e.target.value === 'all' ? 'all' : Number(e.target.value);
          setSelectedBranchId(value);
        }}
        className="text-xs sm:text-sm border border-gray-200 rounded-xl px-2 sm:px-3 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 max-w-[120px] sm:max-w-[220px]"
        title="Branch scope"
      >
        <option value="all">All Branches</option>
        {branches.map((b) => (
          <option key={b.id} value={b.id}>
            {b.name}{b.is_active ? '' : ' (inactive)'}
          </option>
        ))}
      </select>
    );
  }

  const name = user?.branch?.name;
  if (!name) return null;

  return (
    <span className="text-xs font-medium px-2.5 py-1 rounded-lg bg-gray-100 text-gray-600 max-w-[110px] sm:max-w-none truncate">
      {name}
    </span>
  );
}
