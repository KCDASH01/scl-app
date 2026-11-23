import client from './client';
import type { Admission, AdmissionDocType, AdmissionDocument, PaginatedResponse, ApiResponse } from '@/types';

export interface AdmissionListParams {
  search?: string;
  course_id?: number;
  batch_id?: number;
  status?: string;
  hostel_stay?: 0 | 1;
  date_from?: string;
  date_to?: string;
  assigned_to?: number;
  page?: number;
  per_page?: number;
}

export interface AdmissionPayload {
  fee_periods?: import('@/lib/feePeriods').FeePrice[];
  fee_change_reason?: string;
  course_id?: number | null;
  batch_id?: number | null;
  academic_year_id?: number | null;
  student_year?: string | null;
  admission_date?: string | null;
  hostel_stay?: boolean;
  hostel_amount?: number | null;
}

export const admissionsApi = {
  list: (params?: AdmissionListParams) =>
    client.get<PaginatedResponse<Admission>>('/admissions', { params }).then((r) => r.data),

  get: (id: number) =>
    client.get<ApiResponse<Admission>>(`/admissions/${id}`).then((r) => r.data.data),

  update: (id: number, payload: AdmissionPayload) =>
    client.put<ApiResponse<Admission>>(`/admissions/${id}`, payload).then((r) => r.data.data),

  uploadDocument: (id: number, file: File, docType: AdmissionDocType, docLabel?: string) => {
    const form = new FormData();
    form.append('file', file);
    form.append('doc_type', docType);
    if (docLabel) form.append('doc_label', docLabel);
    return client
      .post<ApiResponse<AdmissionDocument>>(`/admissions/${id}/documents`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data.data);
  },

  deleteDocument: (id: number, documentId: number) =>
    client.delete(`/admissions/${id}/documents/${documentId}`).then((r) => r.data),

  downloadDocument: async (id: number, documentId: number, fileName: string) => {
    const res = await client.get(`/admissions/${id}/documents/${documentId}/download`, {
      responseType: 'blob',
    });
    const url = window.URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    a.click();
    window.URL.revokeObjectURL(url);
  },
};
