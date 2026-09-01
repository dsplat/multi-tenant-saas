/**
 * Exam 模块 Console API（scrm 网关：/api/v1/scrm/exam-*）
 */
import { http } from '@/shared/http'

const BASE = '/scrm'

// ========== 类型 ==========

export interface ExamBank {
  bank_id: number
  name: string
  description: string | null
}

export interface ExamQuestion {
  question_id: number
  bank_id: number
  type: 'single' | 'multi' | 'judge' | 'essay'
  content: string
  options: string[] | null
  answer: unknown
  analysis: string | null
  score: number
  difficulty: string | null
}

export interface Exam {
  exam_id: number
  title: string
  compose_rule: { mode: 'fixed' | 'random'; question_ids?: number[]; rules?: Array<{ bank_id: number; type: string; count: number }> }
  total_score: number
  pass_score: number
  time_limit_minutes: number
  retry_limit: number
  status: 'draft' | 'published' | 'closed'
}

export interface ExamRecord {
  record_id: number
  exam_id: number
  user_id: number
  attempt: number
  objective_score: number
  subjective_score: number
  total_score: number
  passed: boolean
  status: string
  submitted_at: string | null
}

export interface GradingItem {
  question_id: number
  content: string | null
  max_score: number
  answer_text: string | null
  media: Array<{ type: string; url?: string }>
}

export interface GradingDetail {
  record_id: number
  exam_id: number
  user_id: number
  attempt: number
  objective_score: number
  status: string
  items: GradingItem[]
}

// ========== 题库/题目 ==========

export async function getBanks(): Promise<ExamBank[]> {
  const res = await http.get<ExamBank[]>(`${BASE}/exam-banks`)
  return res.data ?? []
}

export async function createBank(data: { name: string; description?: string }): Promise<ExamBank> {
  const res = await http.post<ExamBank>(`${BASE}/exam-banks`, data)
  return res.data
}

export async function deleteBank(id: number): Promise<void> {
  await http.delete(`${BASE}/exam-banks/${id}`)
}

export async function getQuestions(params: { bank_id?: number; type?: string } = {}): Promise<ExamQuestion[]> {
  const res = await http.get<ExamQuestion[]>(`${BASE}/exam-questions`, { params })
  return res.data ?? []
}

export async function createQuestion(data: Partial<ExamQuestion> & { bank_id: number; type: string; content: string }): Promise<ExamQuestion> {
  const res = await http.post<ExamQuestion>(`${BASE}/exam-questions`, data)
  return res.data
}

export async function importQuestions(bankId: number, questions: unknown[]): Promise<{ imported: number }> {
  const res = await http.post<{ imported: number }>(`${BASE}/exam-questions/import`, {
    bank_id: bankId,
    questions,
  })
  return res.data
}

// ========== 试卷 ==========

export async function getExams(params: { status?: string } = {}): Promise<Exam[]> {
  const res = await http.get<Exam[]>(`${BASE}/exams`, { params })
  return res.data ?? []
}

export async function createExam(data: Partial<Exam> & { title: string; compose_rule: Exam['compose_rule'] }): Promise<Exam> {
  const res = await http.post<Exam>(`${BASE}/exams`, data)
  return res.data
}

export async function updateExam(id: number, data: Partial<Exam>): Promise<Exam> {
  const res = await http.patch<Exam>(`${BASE}/exams/${id}`, data)
  return res.data
}

export async function publishExam(id: number): Promise<Exam> {
  const res = await http.post<Exam>(`${BASE}/exams/${id}/publish`)
  return res.data
}

export async function closeExam(id: number): Promise<Exam> {
  const res = await http.post<Exam>(`${BASE}/exams/${id}/close`)
  return res.data
}

// ========== 成绩/批改 ==========

export async function getRecords(params: { exam_id?: number; passed?: boolean } = {}): Promise<ExamRecord[]> {
  const res = await http.get<ExamRecord[]>(`${BASE}/exam-records`, { params })
  return res.data ?? []
}

export async function getPendingGrading(params: { exam_id?: number } = {}): Promise<ExamRecord[]> {
  const res = await http.get<ExamRecord[]>(`${BASE}/exam-records/pending-grading`, { params })
  return res.data ?? []
}

export async function getGradingDetail(recordId: number): Promise<GradingDetail> {
  const res = await http.get<GradingDetail>(`${BASE}/exam-records/${recordId}/grading-detail`)
  return res.data
}

export async function gradeRecord(recordId: number, scores: Array<{ question_id: number; score: number; comment?: string }>): Promise<ExamRecord> {
  const res = await http.post<ExamRecord>(`${BASE}/exam-records/${recordId}/grade`, { scores })
  return res.data
}
