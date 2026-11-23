'use client';

import { useQuery } from '@tanstack/react-query';
import { branchesApi } from '@/lib/api/branches';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';

export function SuperadminBranchField({
  value,
  onChange,
}: {
  value?: number;
  onChange: (id: number | undefined) => void;
}) {
  const { isSuperAdmin } = useAuthStore();
  const selected = useBranchStore((s) => s.selectedBranchId);

  const { data: branches = [] } = useQuery({
    queryKey: ['branches'],
    queryFn: () => branchesApi.list(),
    enabled: isSuperAdmin() && selected === 'all',
  });

  if (!isSuperAdmin() || selected !== 'all') return null;

  const options = branches.filter((b) => b.is_active || b.id === value);

  return (
    <div>
      <label className="block text-xs font-semibold text-gray-600 mb-1">Branch *</label>
      <select
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value ? Number(e.target.value) : undefined)}
        className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
      >
        <option value="">Select a branch</option>
        {options.map((b) => (
          <option key={b.id} value={b.id}>
            {b.name}{b.is_active ? '' : ' (inactive)'}
          </option>
        ))}
      </select>
    </div>
  );
}
