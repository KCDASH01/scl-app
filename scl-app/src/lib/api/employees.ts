import client from './client';
import type { ApiResponse, User } from '@/types';

export interface EmployeePayload {
  name: string;
  email: string;
  password?: string;
  phone?: string;
  role?: 'manager' | 'employee';
  staff_role_id?: number | null;
  branch_id?: number;
  manager_id?: number | null;
  avatar?: string;
  is_active?: boolean;
}

export const employeesApi = {
  list: (params?: { role?: string; staff_role_id?: number; search?: string; is_active?: boolean | string; branch_id?: number | 'all'; manager_id?: number }) =>
    client.get<ApiResponse<User[]>>('/employees', { params }).then((r) => r.data.data),

  options: (params?: { branch_id?: number | string; role?: 'employee' | 'manager' | 'admin' }) =>
    client.get<ApiResponse<User[]>>('/employees/options', { params }).then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<User>>(`/employees/${id}`).then((r) => r.data.data),

  create: (payload: EmployeePayload) =>
    client.post<ApiResponse<User>>('/employees', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<EmployeePayload>) =>
    client.put<ApiResponse<User>>(`/employees/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/employees/${id}`).then((r) => r.data),
};
