import { useState, type HTMLAttributes, type ReactNode } from 'react';
import { X } from 'lucide-react';

/**
 * An error / warning banner the user can close with a cross button.
 *
 * Renders exactly the element the page used before (`as`, default `div`, same
 * className and attributes) plus a close button on the right. Closing is purely
 * a view concern: the banner comes back automatically when the message changes
 * (a new error is never hidden by dismissing an older one), and it is not
 * persisted anywhere. Nothing is authorized or validated here.
 */
function textOf(node: ReactNode): string {
  if (node === null || node === undefined || typeof node === 'boolean') return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(textOf).join('');
  if (typeof node === 'object' && 'props' in node) return textOf((node as { props?: { children?: ReactNode } }).props?.children);
  return '';
}

type Props = HTMLAttributes<HTMLElement> & {
  as?: 'div' | 'p';
  children?: ReactNode;
  /** Optional hook for callers that also want to clear their own error state. */
  onDismiss?: () => void;
};

export default function DismissibleAlert({ as: Tag = 'div', children, className = '', onDismiss, ...rest }: Props) {
  const signature = textOf(children);
  const [dismissedSignature, setDismissedSignature] = useState<string | null>(null);

  if (dismissedSignature === signature) return null;

  return (
    <Tag className={className} {...rest}>
      {children}
      <button
        type="button"
        onClick={() => {
          setDismissedSignature(signature);
          onDismiss?.();
        }}
        className="ml-auto flex-shrink-0 self-start rounded p-0.5 opacity-60 hover:opacity-100 focus:opacity-100 focus:outline-none focus:ring-2 focus:ring-current"
        aria-label="Close message"
        title="Close"
        data-testid="alert-dismiss"
      >
        <X className="h-4 w-4" />
      </button>
    </Tag>
  );
}
