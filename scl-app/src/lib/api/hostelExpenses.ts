import client from './client';
import type {
  ApiResponse,
  ExpenseCategory,
  Hostel,
  HostelExpense,
  HostelExpenseSummary,
  PaginatedResponse,
} from '@/types';

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export interface HostelPayload {
  branch_id?: number;
  name: string;
  code: string;
  address?: string | null;
  capacity?: number | null;
  is_active?: boolean;
}

export interface ExpenseCategoryPayload {
  branch_id?: number;
  name: string;
  is_active?: boolean;
}

export interface HostelExpensePayload {
  branch_id?: number;
  hostel_id: number;
  expense_category_id: number;
  amount: number;
  expense_date: string;
  payment_mode?: string | null;
  notes?: string | null;
}

export interface HostelExpenseListParams {
  search?: string;
  hostel_id?: number;
  expense_category_id?: number;
  date_from?: string;
  date_to?: string;
  payment_mode?: string;
  page?: number;
  per_page?: number;
}

export const hostelsApi = {
  list: (params?: { search?: string; is_active?: boolean; page?: number; per_page?: number }) =>
    client.get<PaginatedResponse<Hostel>>('/hostels', { params }).then((r) => r.data),

  options: () =>
    client.get<ApiResponse<Hostel[]>>('/hostels/options').then((r) => r.data.data),

  create: (payload: HostelPayload) =>
    client.post<ApiResponse<Hostel>>('/hostels', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<HostelPayload>) =>
    client.put<ApiResponse<Hostel>>(`/hostels/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/hostels/${id}`).then((r) => r.data),
};

export const expenseCategoriesApi = {
  list: (params?: { search?: string; is_active?: boolean; page?: number; per_page?: number }) =>
    client.get<PaginatedResponse<ExpenseCategory>>('/expense-categories', { params }).then((r) => r.data),

  options: (params?: { hostel_id?: number; branch_id?: number }) =>
    client.get<ApiResponse<ExpenseCategory[]>>('/expense-categories/options', { params }).then((r) => r.data.data),

  create: (payload: ExpenseCategoryPayload) =>
    client.post<ApiResponse<ExpenseCategory>>('/expense-categories', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<ExpenseCategoryPayload>) =>
    client.put<ApiResponse<ExpenseCategory>>(`/expense-categories/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/expense-categories/${id}`).then((r) => r.data),
};

export const hostelExpensesApi = {
  list: (params?: HostelExpenseListParams) =>
    client.get<PaginatedResponse<HostelExpense> & { summary: HostelExpenseSummary }>('/hostel-expenses', { params })
      .then((r) => r.data),

  create: (payload: HostelExpensePayload) =>
    client.post<ApiResponse<HostelExpense>>('/hostel-expenses', payload).then((r) => r.data.data),

  update: (id: number, payload: HostelExpensePayload) =>
    client.put<ApiResponse<HostelExpense>>(`/hostel-expenses/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/hostel-expenses/${id}`).then((r) => r.data),

  exportCsv: async (params?: HostelExpenseListParams) => {
    const response = await client.get('/hostel-expenses/export', {
      params,
      responseType: 'blob',
    });
    downloadBlob(response.data, `hostel-expenses-${new Date().toISOString().slice(0, 10)}.csv`);
  },
};
