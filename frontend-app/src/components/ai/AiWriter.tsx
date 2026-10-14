import { useState, type ReactNode } from 'react';
import { Check, Copy, Loader2, Sparkles, X } from 'lucide-react';
import type { AiWritingLanguage } from '../../services/aiService';
import { describeApiError } from '../../utils/apiError';

const inputClass =
  'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

const LANGUAGES: { value: AiWritingLanguage; label: string }[] = [
  { value: 'english', label: 'English' },
  { value: 'hinglish', label: 'Hinglish' },
  { value: 'hindi', label: 'हिन्दी' },
];

/** A friendly line for the AI errors people actually hit. */
export function aiErrorMessage(err: unknown): string {
  const d = describeApiError(err, 'The AI could not write this right now. Please try again.');
  const code = (err as { response?: { data?: { error_code?: string } } })?.response?.data?.error_code;
  if (code === 'INSUFFICIENT_CREDITS') return 'You have no AI credits left for today. Free credits refill tomorrow, or ask your admin to add credits.';
  if (code === 'AI_PROVIDER_NOT_CONFIGURED' || code === 'AI_PROVIDER_UNAVAILABLE') return 'AI is not available right now. Please try again later.';
  return d.message;
}

/**
 * "✨ Write with AI": a small panel that asks what the message is for, lets the user pick the
 * language, and shows the AI's draft with "Use this" / "Copy". `generate` returns the text to
 * preview, or nothing when it already filled the form itself (template drafts). Uses the
 * signed-in client's AI credits.
 */
export default function AiWriter({
  label = 'Write with AI',
  placeholder,
  promptLabel = 'What is the message for?',
  defaultPrompt = '',
  promptRequired = true,
  showLanguage = true,
  generate,
  onUse,
  actions,
  testId = 'ai-writer',
}: {
  label?: string;
  placeholder?: string;
  promptLabel?: string;
  defaultPrompt?: string;
  /** False when the AI can work from something else (an existing draft, a lead's details). */
  promptRequired?: boolean;
  showLanguage?: boolean;
  generate: (input: { prompt: string; language: AiWritingLanguage }) => Promise<string | void>;
  onUse?: (text: string) => void;
  actions?: (text: string) => ReactNode;
  testId?: string;
}) {
  const [open, setOpen] = useState(false);
  const [prompt, setPrompt] = useState(defaultPrompt);
  const [language, setLanguage] = useState<AiWritingLanguage>('english');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const run = async () => {
    if (promptRequired && prompt.trim().length < 3) {
      setError('Write a few words about what the message is for.');
      return;
    }
    setBusy(true);
    setError(null);
    setResult(null);
    try {
      const text = await generate({ prompt: prompt.trim(), language });
      if (typeof text === 'string') setResult(text);
      else setOpen(false);
    } catch (err) {
      setError(aiErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const copy = async () => {
    if (!result) return;
    try {
      await navigator.clipboard.writeText(result);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      // clipboard blocked: the text is on screen to copy by hand
    }
  };

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100"
        data-testid={`${testId}-open`}
      >
        <Sparkles className="h-3.5 w-3.5" /> {label}
      </button>
    );
  }

  return (
    <div className="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3" data-testid={testId}>
      <div className="flex items-center justify-between">
        <p className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
          <Sparkles className="h-4 w-4 text-emerald-700" /> {label}
        </p>
        <button type="button" onClick={() => setOpen(false)} aria-label="Close AI writer" className="text-slate-400 hover:text-slate-600">
          <X className="h-4 w-4" />
        </button>
      </div>
      <label className="mt-2 block text-xs font-medium text-slate-600">
        {promptLabel}
        <textarea
          rows={2}
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          maxLength={1000}
          placeholder={placeholder}
          className={`${inputClass} mt-1`}
        />
      </label>
      <div className="mt-2 flex flex-wrap items-center gap-2">
        {showLanguage && (
          <select value={language} onChange={(e) => setLanguage(e.target.value as AiWritingLanguage)} aria-label="Language" className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700">
            {LANGUAGES.map((l) => (
              <option key={l.value} value={l.value}>
                {l.label}
              </option>
            ))}
          </select>
        )}
        <button
          type="button"
          onClick={() => void run()}
          disabled={busy}
          className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
        >
          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
          {result ? 'Write again' : 'Write'}
        </button>
        <span className="text-xs text-slate-500">Uses your AI credits.</span>
      </div>
      {error && (
        <p role="alert" className="mt-2 text-sm text-red-600">
          {error}
        </p>
      )}
      {result && (
        <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3" data-testid={`${testId}-result`}>
          <p className="whitespace-pre-wrap text-sm text-slate-800">{result}</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {onUse && (
              <button
                type="button"
                onClick={() => {
                  onUse(result);
                  setOpen(false);
                  setResult(null);
                }}
                className="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
              >
                <Check className="h-3.5 w-3.5" /> Use this
              </button>
            )}
            <button type="button" onClick={() => void copy()} className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
              <Copy className="h-3.5 w-3.5" /> {copied ? 'Copied' : 'Copy'}
            </button>
            {actions?.(result)}
          </div>
        </div>
      )}
    </div>
  );
}
