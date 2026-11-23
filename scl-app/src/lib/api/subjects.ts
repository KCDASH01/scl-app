import client from './client';
import type { ApiResponse, Subject } from '@/types';

export interface SubjectPayload {
  course_id: number;
  name: string;
  code: string;
}

export const subjectsApi = {
  list: (courseId: number) =>
    client.get<ApiResponse<Subject[]>>('/subjects', { params: { course_id: courseId } }).then((r) => r.data.data),

  create: (payload: SubjectPayload) =>
    client.post<ApiResponse<Subject>>('/subjects', payload).then((r) => r.data.data),

  update: (id: number, payload: SubjectPayload) =>
    client.put<ApiResponse<Subject>>(`/subjects/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/subjects/${id}`).then((r) => r.data),
};
