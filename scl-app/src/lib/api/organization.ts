import client from './client';

export interface ReceiptSettings {
  branch_id?: number;
  company_name?: string;
  tagline?: string;
  address_line1?: string;
  address_line2?: string;
  city?: string;
  state?: string;
  pincode?: string;
  phone?: string;
  email?: string;
  website?: string;
  gstin?: string;
  pan?: string;
  footer_note?: string;
  logo?: string; // base64 data URI
}

export const organizationApi = {
  getReceiptSettings: (branchId: number) =>
    client
      .get<{ data: ReceiptSettings }>('/organization/receipt-settings', { params: { branch_id: branchId } })
      .then((r) => r.data.data),

  updateReceiptSettings: (payload: ReceiptSettings & { branch_id: number }) =>
    client
      .put<{ data: ReceiptSettings }>('/organization/receipt-settings', payload)
      .then((r) => r.data.data),
};
