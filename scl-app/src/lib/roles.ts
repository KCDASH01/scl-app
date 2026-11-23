export type CrmRole = 'superadmin' | 'admin' | 'manager' | 'employee' | 'owner';

export const ROLE_LABELS: Record<string, string> = {
  superadmin: 'Superadmin',
  admin: 'Admin',
  manager: 'Manager',
  employee: 'Employee',
  owner: 'Superadmin',
};

export function roleLabel(role?: string | null): string {
  if (!role) return '';
  return ROLE_LABELS[role as CrmRole] ?? role;
}

export function roleBadgeClass(role?: string | null): string {
  switch (role) {
    case 'superadmin':
      return 'bg-violet-100 text-violet-700';
    case 'admin':
      return 'bg-indigo-100 text-indigo-700';
    case 'manager':
      return 'bg-teal-100 text-teal-700';
    default:
      return 'bg-gray-100 text-gray-600';
  }
}
