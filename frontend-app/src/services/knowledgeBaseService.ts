import axiosInstance from '../core/api/axiosInstance';

/**
 * Phase 8 Task 10 — the minimal Knowledge Base read the Journey builder's
 * `rag` node needs: the knowledge bases of the account being edited (the
 * axios interceptor adds a Super Admin's selected ?account_id=). The list is
 * a convenience only — the backend re-checks ownership on save and again at
 * run time, so an id typed or crafted by hand is never trusted.
 */
export interface KnowledgeBaseSummary {
  id: number;
  name: string;
  embedding_dimensions: number | null;
  documents_count?: number;
}

const knowledgeBaseService = {
  list() {
    return axiosInstance.get<{ data: KnowledgeBaseSummary[] }>('/knowledge-bases').then((res) => res.data.data);
  },
};

export default knowledgeBaseService;
