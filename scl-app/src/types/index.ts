// ── Organization ──────────────────────────────────────────────────────────────
export interface Organization {
  id: number;
  name: string;
  slug: string;
  email: string;
  phone?: string;
  website?: string;
  logo?: string;
  address?: string;
  city?: string;
  country?: string;
  timezone: string;
  is_active: boolean;
}

// ── User ──────────────────────────────────────────────────────────────────────
export interface Branch {
  id: number;
  name: string;
  code: string;
  address?: string | null;
  phone?: string | null;
  email?: string | null;
  is_active: boolean;
  managers_count?: number;
  employees_count?: number;
  managers?: User[];
  employees?: User[];
  created_at?: string;
}

export type CourseDurationUnit = 'days' | 'weeks' | 'months' | 'years';
export type BatchStatus = 'upcoming' | 'ongoing' | 'completed' | 'cancelled';

export interface Course {
  period_prices?: import('@/lib/feePeriods').FeePrice[] | null;
  id: number;
  branch_id: number;
  name: string;
  code: string;
  description?: string | null;
  duration_value?: number | null;
  duration_unit?: CourseDurationUnit | null;
  duration?: string | null;
  price?: number | null;
  is_active: boolean;
  batches_count?: number;
  branch?: { id: number; name: string; code: string; is_active: boolean } | null;
  batches?: Batch[];
  created_at?: string;
  updated_at?: string;
}

export interface Subject {
  id: number;
  branch_id: number;
  course_id: number;
  name: string;
  code: string;
  is_active: boolean;
  course?: { id: number; name: string; code: string } | null;
  branch?: { id: number; name: string; code: string } | null;
}

export interface Hostel {
  id: number;
  branch_id: number;
  name: string;
  code: string;
  address?: string | null;
  capacity?: number | null;
  is_active: boolean;
  expenses_count?: number;
  branch?: { id: number; name: string; code: string; is_active: boolean } | null;
  created_at?: string;
  updated_at?: string;
}

export interface ExpenseCategory {
  id: number;
  branch_id: number;
  name: string;
  is_active: boolean;
  expenses_count?: number;
  branch?: { id: number; name: string; code: string; is_active: boolean } | null;
  created_at?: string;
  updated_at?: string;
}

export interface HostelExpense {
  id: number;
  branch_id: number;
  hostel_id: number;
  expense_category_id: number;
  amount: number;
  expense_date: string;
  payment_mode?: string | null;
  notes?: string | null;
  hostel?: { id: number; name: string; code: string; is_active: boolean; branch_id?: number } | null;
  category?: { id: number; name: string; is_active: boolean; branch_id?: number } | null;
  branch?: { id: number; name: string; code: string; is_active: boolean } | null;
  created_by?: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

export interface HostelExpenseSummary {
  count: number;
  total_amount: number;
}

export interface Batch {
  id: number;
  branch_id: number;
  course_id: number;
  name: string;
  code: string;
  start_date: string;
  end_date?: string | null;
  capacity?: number | null;
  schedule?: string | null;
  status: BatchStatus;
  notes?: string | null;
  course?: { id: number; name: string; code: string; is_active: boolean } | null;
  branch?: { id: number; name: string; code: string; is_active: boolean } | null;
  created_at?: string;
  updated_at?: string;
}

export interface StaffRole {
  id: number;
  name: string;
  access: 'employee' | 'manager' | 'none';
  is_system?: boolean;
  users_count?: number;
}

export interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
  avatar?: string;
  role: 'superadmin' | 'admin' | 'manager' | 'employee';
  staff_role_id?: number | null;
  staff_role?: { id: number; name: string; access: 'employee' | 'manager' | 'none' } | null;
  has_password: boolean;
  is_active: boolean;
  branch_id?: number | null;
  manager_id?: number | null;
  branch?: { id: number; name: string; code: string; is_active: boolean } | null;
  manager?: { id: number; name: string; email: string } | null;
  organization?: Organization;
  sidebar?: Record<string, boolean>;
  created_at: string;
}

// ── Pipeline & Stage ──────────────────────────────────────────────────────────
export interface Stage {
  id: number;
  pipeline_id: number;
  name: string;
  color: string;
  sort_order: number;
  probability: number;
  is_won: boolean;
  is_lost: boolean;
}

export interface Pipeline {
  id: number;
  branch_id?: number;
  name: string;
  description?: string;
  is_default: boolean;
  sort_order: number;
  stages: Stage[];
}

// ── Lead ──────────────────────────────────────────────────────────────────────
export interface Lead {
  id: number;
  full_name: string;
  first_name: string;
  last_name?: string;
  email?: string;
  phone?: string;
  status: 'new' | 'contacted' | 'ringing' | 'important' | 'converted' | 'lost' | 'followup' | 'meeting' | 'visited' | 'not_interested';
  source?: string;
  course_id?: number | null;
  course?: { id: number; name: string; code: string; is_active: boolean; branch_id?: number; price?: number | null; period_prices?: import('@/lib/feePeriods').FeePrice[] | null; duration_unit?: CourseDurationUnit | null; duration_value?: number | null } | null;
  city?: string;
  state?: string;
  country?: string;
  pin?: string;
  pipeline_id?: number;
  stage_id?: number;
  branch_id?: number;
  external_lead_id?: string;
  custom_fields?: Record<string, unknown>;
  lost_reason?: string;
  lead_date?: string;
  proposals_count?: number;
  assigned_to?: User;
  created_by?: User;
  stage?: Stage;
  pipeline?: Pipeline;
  created_at: string;
  updated_at: string;
}

// ── Admission ─────────────────────────────────────────────────────────────────
export type AdmissionStatus = 'pending' | 'admitted';
export type AdmissionDocType = 'id_proof' | 'photo' | 'marksheet' | 'other';
export type StudentYear = '1st' | '2nd' | '3rd' | '4th';

export interface AcademicYear {
  id: number;
  name: string;
  is_active: boolean;
}

export interface AdmissionDocument {
  id: number;
  file_name: string;
  doc_type: AdmissionDocType;
  doc_label?: string | null;
  mime_type?: string | null;
  size?: number | null;
  uploaded_by?: { id: number; name: string } | null;
  created_at: string;
}

export interface Admission {
  fee_schedule?: import('@/lib/feePeriods').FeeSchedule | null;
  id: number;
  enrollment_id: string;
  branch_id: number;
  lead_id: number;
  course_id?: number | null;
  batch_id?: number | null;
  academic_year_id?: number | null;
  student_year?: StudentYear | null;
  admission_date?: string | null;
  status: AdmissionStatus;
  hostel_stay?: boolean;
  hostel_amount?: number | null;
  lead?: {
    id: number;
    full_name: string;
    first_name: string;
    last_name?: string | null;
    email?: string | null;
    phone?: string | null;
    city?: string | null;
    state?: string | null;
    country?: string | null;
    pin?: string | null;
    assigned_to?: { id: number; name: string } | null;
  } | null;
  course?: { id: number; name: string; code: string; is_active: boolean; branch_id?: number } | null;
  academic_year?: AcademicYear | null;
  batch?: { id: number; name: string; code: string; status: string; course_id: number } | null;
  branch?: { id: number; name: string; code: string; is_active: boolean } | null;
  documents?: AdmissionDocument[];
  created_at: string;
  updated_at: string;
}

// ── Attendance ────────────────────────────────────────────────────────────────
export type AttendanceStatus = 'present' | 'absent';

export interface AttendanceStudent {
  admission_id: number;
  enrollment_id: string;
  student_name?: string | null;
  phone?: string | null;
  email?: string | null;
  attendance_id?: number | null;
  status?: AttendanceStatus | null;
  marked_at?: string | null;
  marked_by?: string | null;
}

export interface StaffAttendanceEmployee {
  user_id: number;
  name: string;
  email?: string | null;
  phone?: string | null;
  role: string;
  branch_id?: number | null;
  branch_name?: string | null;
  attendance_id?: number | null;
  status?: AttendanceStatus | null;
  marked_at?: string | null;
  marked_by?: string | null;
}

export interface StaffAttendanceSession {
  date: string;
  is_marked: boolean;
  total: number;
  present_count: number;
  absent_count: number;
  employees: StaffAttendanceEmployee[];
}

export interface AttendanceSession {
  branch?: { id: number; name: string; code: string } | null;
  course?: { id: number; name: string; code: string } | null;
  subject?: { id: number; name: string; code: string } | null;
  batch?: { id: number; name: string; code: string } | null;
  date: string;
  is_marked: boolean;
  total: number;
  present_count: number;
  absent_count: number;
  students: AttendanceStudent[];
}

// ── Deal ──────────────────────────────────────────────────────────────────────
export interface Deal {
  fee_periods?: import('@/lib/feePeriods').FeePeriodSummary[];
  fee_unit?: CourseDurationUnit | null;
  current_period?: import('@/lib/feePeriods').FeePeriodSummary | null;
  id: number;
  branch_id?: number;
  title: string;
  fee_kind?: 'course' | 'hostel';
  value?: number;
  currency: string;
  status: 'open' | 'won' | 'lost';
  lost_reason?: string;
  original_value?: number;
  counter_offer_value?: number;
  negotiation_notes?: string;
  probability?: number;
  description?: string;
  expected_close_date?: string;
  closed_at?: string;
  handed_off_at?: string;
  total_received?: number | null;
  payments_count?: number;
  custom_fields?: Record<string, unknown>;
  pipeline_id: number;
  stage_id: number;
  lead_id?: number;
  lead?: Lead;
  stage?: Stage;
  pipeline?: Pipeline;
  assigned_to?: User;
  created_by?: User;
  created_at: string;
  updated_at: string;
}

// ── Deal Payment ──────────────────────────────────────────────────────────────
export interface DealPayment {
  allocations?: import('@/lib/feePeriods').PaymentAllocation[] | null;
  receipt_snapshot?: import('@/lib/feePeriods').ReceiptSnapshot | null;
  id: number;
  deal_id?: number;
  amount: number;
  payment_date: string;
  payment_mode: 'cash' | 'cheque' | 'bank_transfer' | 'upi' | 'card' | 'aggregator' | 'other';
  txn_or_utr_number?: string | null;
  notes?: string | null;
  created_by?: { id: number; name: string } | null;
  created_at: string;
}

// ── Activity ──────────────────────────────────────────────────────────────────
export type ActivityType = 'call' | 'email' | 'meeting' | 'task' | 'note' | 'deadline' | 'whatsapp';
export type Priority = 'low' | 'medium' | 'high';

export interface Activity {
  id: number;
  branch_id?: number;
  type: ActivityType;
  title: string;
  description?: string;
  due_at?: string;
  completed_at?: string;
  is_done: boolean;
  priority: Priority;
  subject_type: string;
  subject_id: number;
  assigned_to?: User;
  created_by?: User;
  created_at: string;
}

// ── Note ──────────────────────────────────────────────────────────────────────
export interface Note {
  id: number;
  content: string;
  is_pinned: boolean;
  notable_type: string;
  notable_id: number;
  created_by?: User;
  created_at: string;
  updated_at: string;
}

// ── Department ────────────────────────────────────────────────────────────────
export interface Department {
  id: number;
  branch_id?: number;
  name: string;
  description?: string;
  members: User[];
  members_count?: number;
  created_at: string;
}

// ── Sales Targets ─────────────────────────────────────────────────────────────
export interface SalesTarget {
  id: number;
  user_id: number;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  notes?: string;
  period_start: string;
  user?: { id: number; name: string };
}

// Single-month progress row returned by /sales-targets (list)
export interface SalesTargetRow {
  id: number;
  user: { id: number; name: string } | null;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  achieved_amount: number;
  notes?: string;
  period_start: string;
}

// 6-month history row returned by /sales-targets/my-progress
export interface MyTargetProgress {
  period_start: string;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  achieved_amount: number;
  target_id: number | null;
}

// Embedded in DashboardData for team-member view
export interface TargetProgress {
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  achieved_amount: number;
  target_id: number;
}

// ── Proposals ─────────────────────────────────────────────────────────────────
export type ProposalTheme  = 'modern' | 'corporate' | 'minimal' | 'vibrant';
export type ProposalStatus = 'draft' | 'sent' | 'accepted' | 'rejected';

export interface ProposalPainPoint {
  title: string;
  points: string[];
}

export interface ProposalPlatformTech {
  label: string;
  value: string;
}

export interface ProposalMaintenanceSupport {
  period: string;
  includes: string[];
}

export interface ProposalScopeItem {
  item: string;
  description: string;
  key_deliverables?: string[];
}

export interface ProposalTimelineItem {
  phase: string;
  duration: string;
  deliverables: string;
  start_week?: number;
  end_week?: number;
}

export interface ProposedSolution {
  overview: string;
  approach: string;
  technology_stack: string[];
  key_features: { feature: string; benefit: string }[];
  differentiators: string;
  success_metrics: { metric: string; target: string }[];
  implementation_approach: string;
}

export interface ProposalResourceBreakdown {
  resource: string;
  rate: number;
  hours: number;
  amount: number;
}

export interface ProposalInvestment {
  resource_breakdown?: ProposalResourceBreakdown[];
  breakdown: { item: string; amount: number }[];
  total: number;
  currency: string;
  payment_terms: string;
}

export interface ProposalContent {
  title: string;
  executive_summary: string;
  about_us?: string;
  why_choose_us?: string[];
  understanding: string;
  client_pain_points?: ProposalPainPoint[];
  key_benefits?: { title: string; description: string }[];
  proposed_solution: ProposedSolution | string;   // string = legacy pre-v2 proposals
  scope_of_work: ProposalScopeItem[];
  timeline: ProposalTimelineItem[];
  team?: { role: string; responsibility: string }[];
  investment: ProposalInvestment;
  platform_tech?: ProposalPlatformTech[];
  risk_mitigation?: { risk: string; mitigation: string }[];
  assumptions?: string[];
  maintenance_support?: ProposalMaintenanceSupport;
  terms_and_conditions: string;
  next_steps: string;
  closing_note?: string;
  validity_days: number;
}

export interface Proposal {
  id: number;
  lead_id: number;
  title: string;
  theme: ProposalTheme;
  conversation_notes: string;
  content: ProposalContent;
  status: ProposalStatus;
  valid_until?: string;
  created_by?: { id: number; name: string };
  created_at: string;
  updated_at: string;
}

// ── Email Templates ───────────────────────────────────────────────────────────
export interface EmailTemplate {
  id: number;
  name: string;
  subject: string;
  body: string;
  stage_trigger?: string;
  created_at: string;
}

// ── API response shapes ───────────────────────────────────────────────────────
export interface ApiResponse<T> {
  data: T;
  message?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

// ── CRM Notification ──────────────────────────────────────────────────────────
export interface CrmNotification {
  id: number;
  type: 'lead_assigned' | 'deal_stage_changed' | 'activity_assigned';
  title: string;
  message: string;
  data?: Record<string, unknown>;
  link?: string;
  is_read: boolean;
  read_at: string | null;
  time_ago: string;
  created_at: string;
}

// ── Reports ───────────────────────────────────────────────────────────────────
export interface ReportsData {
  funnel:       { status: string; count: number }[];
  lead_sources: { source: string; count: number }[];
  leaderboard:  { user_id: number; name: string; deals_won: number; revenue: number }[];
  lost_reasons: { lost_reason: string; count: number }[];
  avg_cycle:    { status: string; avg_days: number; count: number }[];
  admissions?: {
    total: number;
    pending: number;
    admitted: number;
    this_month: number;
  };
  admissions_by_course?: { course: string; count: number }[];
}

export interface LeadReportSummary {
  total: number;
  new: number;
  contacted: number;
  ringing: number;
  qualified?: number; // legacy alias for ringing
  proposal_sent: number;
  won: number;
  lost: number;
}

export interface LeadReportRow {
  id: number;
  branch?: string | null;
  lead_name: string;
  student_name?: string | null;
  course?: string | null;
  enrollment_id?: string | null;
  admission_status?: string | null;
  batch?: string | null;
  contact_person: string;
  phone?: string | null;
  email?: string | null;
  assigned_member?: string | null;
  source?: string | null;
  status: string;
  created_at?: string | null;
  updated_at?: string | null;
  follow_up_date?: string | null;
}

export interface RevenueReportSummary {
  total_revenue: number;
  revenue_this_month: number;
  total_transactions: number;
  average_revenue_per_deal: number;
}

export interface RevenueReportRow {
  id: number;
  branch?: string | null;
  deal_name?: string | null;
  client_name?: string | null;
  student_name?: string | null;
  enrollment_id?: string | null;
  course?: string | null;
  phone?: string | null;
  assigned_member?: string | null;
  deal_value?: number | null;
  amount_received: number;
  remaining_amount?: number | null;
  transaction_amount: number;
  payment_date?: string | null;
  payment_method?: string | null;
  transaction_notes?: string | null;
  deal_status?: string | null;
}

export interface LeadReportFilters {
  search?: string;
  status?: string;
  course_id?: number;
  assigned_to?: number;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

export interface RevenueReportFilters {
  search?: string;
  assigned_to?: number;
  course_id?: number;
  deal_status?: string;
  payment_mode?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

export interface AdmissionReportSummary {
  total: number;
  pending: number;
  admitted: number;
  this_month: number;
}

export interface AdmissionReportRow {
  id: number;
  enrollment_id: string;
  branch?: string | null;
  student_name?: string | null;
  phone?: string | null;
  email?: string | null;
  city?: string | null;
  course?: string | null;
  course_code?: string | null;
  batch?: string | null;
  batch_code?: string | null;
  admission_date?: string | null;
  status: AdmissionStatus;
  assigned_member?: string | null;
}

export interface AdmissionReportFilters {
  search?: string;
  course_id?: number;
  batch_id?: number;
  status?: string;
  assigned_to?: number;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

// ── Dashboard ─────────────────────────────────────────────────────────────────
export interface DashboardStats {
  total_leads: number;
  new_leads: number;
  open_deals: number;
  won_deals: number;
  deal_value: number;
  due_today_activities: number;
  overdue_activities: number;
}

export interface DashboardCharts {
  leads_trend:    { date: string; count: number }[];
  deals_by_stage: { stage_id: number; count: number; total_value: number; stage?: { id: number; name: string; color: string } | null }[];
  revenue_trend:  { date: string; revenue: number }[];
}

export interface DashboardData {
  stats:               DashboardStats;
  recent_leads:        Lead[];
  recent_deals:        Deal[];
  reminders:           Activity[];
  charts:              DashboardCharts;
  target_progress?:    TargetProgress;
}
