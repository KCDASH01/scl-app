import client from './client';
import type { ApiResponse, AttendanceStatus, StaffAttendanceEmployee, StaffAttendanceSession } from '@/types';

export const staffAttendanceApi = {
  list: (date: string) =>
    client.get<ApiResponse<StaffAttendanceSession>>('/staff-attendance', { params: { date } }).then((r) => r.data.data),

  mark: (payload: { date: string; marked_at?: string; present_user_ids: number[] }) =>
    client.post<ApiResponse<StaffAttendanceSession>>('/staff-attendance/mark', payload).then((r) => r.data.data),

  update: (id: number, payload: { status: AttendanceStatus; marked_at?: string }) =>
    client.patch<ApiResponse<StaffAttendanceEmployee>>(`/staff-attendance/${id}`, payload).then((r) => r.data.data),
};
