import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
  children: ReactNode;
  /**
   * Optional custom fallback. Used by TemplateMessageDetailModal so a
   * render error inside the modal stays inside the modal (with a Close
   * button) instead of replacing the whole Message Logs page.
   */
  renderFallback?: (error: Error, reset: () => void) => ReactNode;
}

interface State {
  error: Error | null;
}

/**
 * Wraps the authenticated page <Outlet/> in AppLayout. Before this existed
 * the app had NO error boundary, so any render-time exception in one page
 * (e.g. SOURCE_BADGE[log.source] being undefined for an unrecognised
 * message_dispatch_logs.source — the 2026-09-25 whatsapp-bulk incident)
 * unmounted the ENTIRE React root: sidebar, header and page all went
 * blank, and only a full browser reload recovered it.
 *
 * This does not swallow the error: it is logged to the console and shown
 * to the user, and the sidebar/header stay usable. AppLayout keys this
 * component by pathname, so navigating to another page resets it.
 */
export default class RouteErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('RouteErrorBoundary caught a render error:', error, info.componentStack);
  }

  private reset = () => {
    this.setState({ error: null });
  };

  render() {
    if (!this.state.error) return this.props.children;
    if (this.props.renderFallback) return this.props.renderFallback(this.state.error, this.reset);

    return (
      <div className="p-6">
        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          <p className="font-semibold">This page failed to render.</p>
          <p className="mt-1 break-words">{this.state.error.message}</p>
          <button
            type="button"
            onClick={this.reset}
            className="mt-3 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
          >
            Try again
          </button>
        </div>
      </div>
    );
  }
}
