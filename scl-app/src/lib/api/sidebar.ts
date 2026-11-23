import client from './client';

export type SidebarRoleMap = Record<string, boolean>;
export type SidebarRole = 'manager' | 'employee';

export interface SidebarScope {
  branch_id: number;
  role: SidebarRole;
  user_id: number | null;
  defaults: SidebarRoleMap;
  map: SidebarRoleMap;
  has_customization: boolean;
  users: {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    panel_access: boolean;
    has_customization: boolean;
  }[];
}

export interface SidebarScopePayload {
  branch_id: number;
  role: SidebarRole;
  user_id?: number | null;
  map?: SidebarRoleMap;
  reset?: boolean;
  clear_customizations?: boolean;
}

export interface SidebarSettings {
  branch_id: number;
  manager: SidebarRoleMap;
  employee: SidebarRoleMap;
  resolved: SidebarRoleMap;
}

export const SIDEBAR_WORKSPACE_ITEMS: { href: string; label: string }[] = [
  { href: '/leads', label: 'Leads' },
  { href: '/meetings', label: 'To Visit' },
  { href: '/important', label: 'Important' },
  { href: '/admissions', label: 'Student Admission' },
  { href: '/deals', label: 'Course Fee Collection' },
  { href: '/hostel-fees', label: 'Hostel Fee Collection' },
  { href: '/attendance', label: 'Student Attendance' },
  { href: '/hostel-expenses', label: 'Hostel Expenses' },
  { href: '/pipelines', label: 'Pipelines' },
  { href: '/activities', label: 'Activities' },
  { href: '/calendar', label: 'Calendar' },
  { href: '/reports', label: 'Reports' },
  { href: '/targets', label: 'Targets' },
];

export const SIDEBAR_MANAGER_ADMIN_ITEMS: { href: string; label: string }[] = [
  { href: '/team', label: 'Team' },
  { href: '/staff-attendance', label: 'Staff Attendance' },
  { href: '/departments', label: 'Departments' },
  { href: '/batch-years', label: 'Batch Year' },
];

export const LEAD_EDIT_KEY = '/leads/edit';

const LOCKED_PATHS = ['/dashboard', '/settings', '/manage-sidebar'];

export function canEmployeeEditLeads(sidebar?: Record<string, boolean> | null): boolean {
  return sidebar?.[LEAD_EDIT_KEY] === true;
}

export function isSidebarHrefVisible(href: string, sidebar?: Record<string, boolean> | null): boolean {
  if (!sidebar) return true;
  return sidebar[href] !== false;
}

export function isHiddenDashboardPath(pathname: string, sidebar?: Record<string, boolean> | null): boolean {
  if (!sidebar || LOCKED_PATHS.some((path) => pathname === path || pathname.startsWith(`${path}/`))) {
    return false;
  }

  const keys = Object.keys(sidebar).sort((a, b) => b.length - a.length);
  const match = keys.find((href) => pathname === href || pathname.startsWith(`${href}/`));
  return match ? sidebar[match] === false : false;
}

export const sidebarApi = {
  getScope: (branchId: number, role: SidebarRole, userId?: number | null) =>
    client.get<{ data: SidebarScope }>('/sidebar-settings', {
      params: { branch_id: branchId, role, user_id: userId ?? undefined },
    }).then((r) => r.data.data),

  saveScope: (payload: SidebarScopePayload) =>
    client.put<{ data: SidebarScope }>('/sidebar-settings', payload).then((r) => r.data.data),

  get: (branchId: number) =>
    client.get<{ data: SidebarSettings }>('/sidebar-settings', { params: { branch_id: branchId } }).then((r) => r.data.data),

  update: (payload: { branch_id: number; manager: SidebarRoleMap; employee: SidebarRoleMap }) =>
    client.put<{ data: SidebarSettings }>('/sidebar-settings', payload).then((r) => r.data.data),
};
