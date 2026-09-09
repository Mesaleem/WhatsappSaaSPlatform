import { useEffect, useRef } from 'react';
import { Bold, Italic, Underline, Link as LinkIcon, Code, type LucideIcon } from 'lucide-react';
import type { NotificationTemplateType } from '../../types/notifications';

/**
 * Mail Template Manager — dual-mode HTML content editor, zero new
 * dependencies (no rich-text-editor package is installed in this
 * environment, and per the standing dependency-minimization instruction
 * one shouldn't be added just for this). 'rich_text' mode is a
 * contentEditable div driven by the browser's built-in
 * document.execCommand — deprecated by spec but still implemented by
 * every major browser and the only zero-dependency way to get
 * bold/italic/underline/link formatting; 'raw_html' mode is a plain
 * textarea of hand-written HTML. Both modes ultimately produce the same
 * thing: an HTML string in `value`, which is exactly what
 * TemplateRenderer::render() (backend) and the client-side preview below
 * both consume identically regardless of which mode authored it.
 */
export default function RichTextEditor({
  mode,
  value,
  onChange,
  onModeChange,
}: {
  mode: NotificationTemplateType;
  value: string;
  onChange: (html: string) => void;
  onModeChange: (mode: NotificationTemplateType) => void;
}) {
  const editableRef = useRef<HTMLDivElement>(null);

  // Sync an externally-set value (e.g. loading a saved template, or
  // switching back from raw_html mode) into the contentEditable div —
  // but only when it actually differs from the live DOM, so this never
  // fights the user's own typing/cursor position.
  useEffect(() => {
    if (mode === 'rich_text' && editableRef.current && editableRef.current.innerHTML !== value) {
      editableRef.current.innerHTML = value;
    }
  }, [mode, value]);

  const exec = (command: string, arg?: string) => {
    editableRef.current?.focus();
    document.execCommand(command, false, arg);
    if (editableRef.current) onChange(editableRef.current.innerHTML);
  };

  return (
    <div className="overflow-hidden rounded-lg border border-slate-300">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-2 py-1.5">
        <div className="flex items-center gap-1">
          {mode === 'rich_text' ? (
            <>
              <ToolbarButton icon={Bold} label="Bold" onClick={() => exec('bold')} />
              <ToolbarButton icon={Italic} label="Italic" onClick={() => exec('italic')} />
              <ToolbarButton icon={Underline} label="Underline" onClick={() => exec('underline')} />
              <ToolbarButton
                icon={LinkIcon}
                label="Insert link"
                onClick={() => {
                  const url = window.prompt('Link URL (https://…)');
                  if (url) exec('createLink', url);
                }}
              />
            </>
          ) : (
            <span className="flex items-center gap-1.5 px-1 text-xs text-slate-500">
              <Code className="h-3.5 w-3.5" /> Raw HTML source
            </span>
          )}
        </div>
        <div className="flex overflow-hidden rounded-md border border-slate-300 text-xs font-medium">
          <button
            type="button"
            onClick={() => onModeChange('rich_text')}
            className={`px-2.5 py-1 ${mode === 'rich_text' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'}`}
          >
            Rich Text
          </button>
          <button
            type="button"
            onClick={() => onModeChange('raw_html')}
            className={`px-2.5 py-1 ${mode === 'raw_html' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'}`}
          >
            Raw HTML
          </button>
        </div>
      </div>

      {mode === 'rich_text' ? (
        <div
          ref={editableRef}
          contentEditable
          suppressContentEditableWarning
          onInput={(e) => onChange((e.target as HTMLDivElement).innerHTML)}
          className="min-h-[160px] px-3 py-2.5 text-sm text-slate-900 outline-none"
        />
      ) : (
        <textarea
          value={value}
          onChange={(e) => onChange(e.target.value)}
          rows={8}
          spellCheck={false}
          className="w-full resize-y px-3 py-2.5 font-mono text-xs text-slate-900 outline-none"
        />
      )}
    </div>
  );
}

function ToolbarButton({ icon: Icon, onClick, label }: { icon: LucideIcon; onClick: () => void; label: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      className="rounded p-1.5 text-slate-600 hover:bg-slate-200"
    >
      <Icon className="h-3.5 w-3.5" />
    </button>
  );
}
