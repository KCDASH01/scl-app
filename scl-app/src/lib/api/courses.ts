import client from './client';
import type { ApiResponse, Batch, Course, PaginatedResponse } from '@/types';

export interface CoursePayload {
  period_prices?: import('@/lib/feePeriods').FeePrice[] | null;
  branch_id: number;
  name: string;
  code: string;
  description?: string | null;
  duration_value?: number | null;
  duration_unit?: 'days' | 'weeks' | 'months' | 'years' | null;
  price?: number | null;
  is_active?: boolean;
}

export interface BatchPayload {
  name: string;
  code: string;
  start_date: string;
  end_date?: string | null;
  capacity?: number | null;
  schedule?: string | null;
  status?: 'upcoming' | 'ongoing' | 'completed' | 'cancelled';
  notes?: string | null;
}

export const coursesApi = {
  list: (params?: {
    search?: string;
    is_active?: boolean;
    branch_id?: number;
    page?: number;
    per_page?: number;
  }) =>
    client.get<PaginatedResponse<Course>>('/courses', { params }).then((r) => r.data),

  options: (params?: { branch_id?: number }) =>
    client.get<ApiResponse<Course[]>>('/courses/options', { params }).then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<Course>>(`/courses/${id}`).then((r) => r.data.data),

  create: (payload: CoursePayload) =>
    client.post<ApiResponse<Course>>('/courses', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<CoursePayload>) =>
    client.put<ApiResponse<Course>>(`/courses/${id}`, payload).then((r) => r.data.data),

  toggle: (id: number) =>
    client.patch<ApiResponse<Course>>(`/courses/${id}/status`).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/courses/${id}`).then((r) => r.data),

  batches: (courseId: number, params?: {
    search?: string;
    status?: string;
    page?: number;
    per_page?: number;
  }) =>
    client.get<PaginatedResponse<Batch>>(`/courses/${courseId}/batches`, { params }).then((r) => r.data),

  createBatch: (courseId: number, payload: BatchPayload) =>
    client.post<ApiResponse<Batch>>(`/courses/${courseId}/batches`, payload).then((r) => r.data.data),
};

export const batchesApi = {
  options: (params?: { course_id?: number; branch_id?: number }) =>
    client.get<ApiResponse<Batch[]>>('/batches/options', { params }).then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<Batch>>(`/batches/${id}`).then((r) => r.data.data),

  update: (id: number, payload: Partial<BatchPayload>) =>
    client.put<ApiResponse<Batch>>(`/batches/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/batches/${id}`).then((r) => r.data),
};
