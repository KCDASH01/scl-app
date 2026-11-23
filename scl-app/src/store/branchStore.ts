'use client';

import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type BranchSelection = number | 'all';

interface BranchState {
  selectedBranchId: BranchSelection;
  setSelectedBranchId: (id: BranchSelection) => void;
}

export const useBranchStore = create<BranchState>()(
  persist(
    (set) => ({
      selectedBranchId: 'all',
      setSelectedBranchId: (id) => set({ selectedBranchId: id }),
    }),
    { name: 'crm_branch' }
  )
);
