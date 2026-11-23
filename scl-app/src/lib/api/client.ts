import axios from 'axios';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';

const client = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false,
});

client.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = localStorage.getItem('crm_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    const role = useAuthStore.getState().user?.role;
    const branchId = useBranchStore.getState().selectedBranchId;
    if (['superadmin', 'owner'].includes(role ?? '') && branchId && branchId !== 'all') {
      config.headers['X-Branch-Id'] = String(branchId);
    }
    if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
      delete config.headers['Content-Type'];
    }
  }
  return config;
});

client.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;

    if (status === 401 && typeof window !== 'undefined') {
      useAuthStore.getState().clearAuth();
      window.location.href = '/login';
    }

    return Promise.reject(error);
  }
);

export default client;
