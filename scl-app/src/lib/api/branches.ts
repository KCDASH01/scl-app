import client from './client';
import type { ApiResponse, Branch, User } from '@/types';

export interface BranchPayload {
  name: string;
  code: string;
  address?: string;
  phone?: string;
  email?: string;
  is_active?: boolean;
}

export const branchesApi = {
  list: (params?: { search?: string; is_active?: boolean | string }) =>
    client.get<ApiResponse<Branch[]>>('/branches', { params }).then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<Branch>>(`/branches/${id}`).then((r) => r.data.data),

  create: (payload: BranchPayload) =>
    client.post<ApiResponse<Branch>>('/branches', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<BranchPayload>) =>
    client.put<ApiResponse<Branch>>(`/branches/${id}`, payload).then((r) => r.data.data),

  toggle: (id: number) =>
    client.patch<ApiResponse<Branch>>(`/branches/${id}/status`).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/branches/${id}`).then((r) => r.data),

  managers: (id: number) =>
    client.get<ApiResponse<User[]>>(`/branches/${id}/managers`).then((r) => r.data.data),
};
