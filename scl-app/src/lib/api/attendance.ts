import client from './client';
import type { ApiResponse, AttendanceSession, AttendanceStudent, AttendanceStatus, Subject } from '@/types';

export const attendanceApi = {
  subjects: (courseId: number) =>
    client.get<ApiResponse<Subject[]>>('/attendance/subjects', { params: { course_id: courseId } }).then((r) => r.data.data),

  students: (params: { course_id: number; batch_id: number; date: string; subject_id?: number; search?: string }) =>
    client.get<ApiResponse<AttendanceSession>>('/attendance/students', { params }).then((r) => r.data.data),

  mark: (payload: {
    course_id: number;
    batch_id: number;
    date: string;
    subject_id?: number;
    marked_at?: string;
    present_admission_ids: number[];
  }) => client.post<ApiResponse<AttendanceSession>>('/attendance/mark', payload).then((r) => r.data.data),

  update: (id: number, payload: { status: AttendanceStatus; marked_at?: string }) =>
    client.patch<ApiResponse<AttendanceStudent>>(`/attendance/${id}`, payload).then((r) => r.data.data),
};
