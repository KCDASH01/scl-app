import type { BranchSelection } from '@/store/branchStore';

/** Keep paginated rows only when the branch in the query key has not changed. */
export function keepPreviousIfSameBranch<T>(selectedBranchId: BranchSelection) {
  return (
    previousData: T | undefined,
    previousQuery?: { queryKey: readonly unknown[] },
  ): T | undefined => {
    if (previousData === undefined || !previousQuery) return undefined;
    return previousQuery.queryKey[1] === selectedBranchId ? previousData : undefined;
  };
}
