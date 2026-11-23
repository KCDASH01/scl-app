import client from './client';
import type { ApiResponse, StaffRole } from '@/types';

export interface StaffRolePayload {
  name: string;
}

export const staffRolesApi = {
  list: () =>
    client.get<ApiResponse<StaffRole[]>>('/staff-roles').then((r) => r.data.data),

  create: (payload: StaffRolePayload) =>
    client.post<ApiResponse<StaffRole>>('/staff-roles', payload).then((r) => r.data.data),

  update: (id: number, payload: StaffRolePayload) =>
    client.put<ApiResponse<StaffRole>>(`/staff-roles/${id}`, payload).then((r) => r.data.data),

  remove: (id: number) =>
    client.delete(`/staff-roles/${id}`).then((r) => r.data),
};
