import client from './client';
import type { AcademicYear, ApiResponse } from '@/types';

export const academicYearsApi = {
  list: () =>
    client.get<{ data: AcademicYear[] }>('/academic-years').then((r) => r.data.data),

  create: (name: string) =>
    client.post<ApiResponse<AcademicYear>>('/academic-years', { name }).then((r) => r.data.data),

  remove: (id: number) =>
    client.delete(`/academic-years/${id}`).then((r) => r.data),
};
