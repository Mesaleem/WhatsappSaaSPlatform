import { useState } from 'react';
import { Bookmark, Globe2, Heart, ImageOff, MessageCircle, MoreHorizontal, Send, Share2, ThumbsUp } from 'lucide-react';
import { indigo } from '../../theme/signalIndigo';
import type { MediaType } from '../../types/media';

type PreviewTab = 'facebook' | 'instagram_post' | 'instagram_story';

const TABS: { key: PreviewTab; label: string }[] = [
  { key: 'facebook', label: 'FB Feed' },
  { key: 'instagram_post', label: 'IG Post' },
  { key: 'instagram_story', label: 'IG Story' },
];

interface AdPreviewProps {
  /** Rendered as the Page/account name in every layout. Falls back to a placeholder when the tenant hasn't typed one yet. */
  businessName: string;
  headline: string;
  primaryText: string;
  /** Meta's own CTA button vocabulary is a fixed set of short labels (Sign Up, Learn More, Send Message, ...) — the caller derives this from the selected campaign objective, not free text. */
  ctaLabel: string;
  mediaUrl: string | null;
  mediaType: MediaType | null;
  /** True when this preview represents a real budget-spending campaign (shows "Sponsored") vs a free organic post (shows a relative timestamp instead). */
  isSponsored: boolean;
}

function InitialsAvatar({ name }: { name: string }) {
  const initials = name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('') || '•';

  return (
    <div
      className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
      style={{ background: `linear-gradient(135deg, ${indigo.accentFrom}, ${indigo.accentTo})` }}
      aria-hidden
    >
      {initials}
    </div>
  );
}

function MediaFrame({
  mediaUrl,
  mediaType,
  aspectClass,
}: {
  mediaUrl: string | null;
  mediaType: MediaType | null;
  aspectClass: string;
}) {
  if (!mediaUrl) {
    return (
      <div className={`flex w-full flex-col items-center justify-center gap-1.5 bg-slate-100 text-slate-400 ${aspectClass}`}>
        <ImageOff className="h-6 w-6" />
        <span className="text-[11px] font-medium">Upload media to preview</span>
      </div>
    );
  }

  if (mediaType === 'video') {
    return (
      // eslint-disable-next-line jsx-a11y/media-has-caption
      <video src={mediaUrl} controls className={`w-full bg-black object-cover ${aspectClass}`} />
    );
  }

  return <img src={mediaUrl} alt="Ad creative preview" className={`w-full object-cover ${aspectClass}`} />;
}

/**
 * Social/Ads Launcher Overhaul — Step 3 (Live Mockup Preview UI).
 * Renders a real-time, non-interactive mock of how the wizard's current
 * form state (media + headline + primary text + CTA) will look as a
 * Facebook Feed link ad, an Instagram feed post, or an Instagram Story —
 * so the tenant can inspect their copy in context BEFORE launching,
 * without leaving the wizard. Purely presentational: no network calls,
 * no fabricated engagement numbers (a "1.2K likes" style placeholder
 * would misrepresent real ad performance data this platform actually
 * tracks elsewhere — see AnalyticsPage/SocialReportsPage).
 */
export default function AdPreview({
  businessName,
  headline,
  primaryText,
  ctaLabel,
  mediaUrl,
  mediaType,
  isSponsored,
}: AdPreviewProps) {
  const [tab, setTab] = useState<PreviewTab>('facebook');
  const displayName = businessName.trim() || 'Your Business';
  const displayHeadline = headline.trim() || 'Your headline will appear here';
  const displayText = primaryText.trim() || 'Your primary text will appear here — write it in the fields on the left, or generate it with AI.';

  return (
    <div className="flex flex-col">
      <div className="mb-3 flex gap-1 rounded-lg bg-slate-100 p-1">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`flex-1 rounded-md px-2 py-1.5 text-xs font-semibold transition ${
              tab === t.key ? 'bg-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
            }`}
            style={tab === t.key ? { color: indigo.accentSolid } : undefined}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'facebook' && (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-2 px-3 py-2.5">
            <InitialsAvatar name={displayName} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-[13px] font-semibold text-slate-900">{displayName}</p>
              <p className="text-[11px] text-slate-500">{isSponsored ? 'Sponsored · ' : '2h · '}🌐</p>
            </div>
            <MoreHorizontal className="h-4 w-4 flex-shrink-0 text-slate-400" />
          </div>

          <p className="whitespace-pre-line px-3 pb-2.5 text-[13px] leading-snug text-slate-800">{displayText}</p>

          <MediaFrame mediaUrl={mediaUrl} mediaType={mediaType} aspectClass="aspect-[4/3]" />

          <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-3 py-2">
            <div className="min-w-0">
              <p className="truncate text-[10px] uppercase tracking-wide text-slate-400">{displayName.toLowerCase().replace(/\s+/g, '')}.com</p>
              <p className="truncate text-[13px] font-semibold text-slate-900">{displayHeadline}</p>
            </div>
            <span className="flex-shrink-0 rounded-md bg-slate-200 px-3 py-1.5 text-[12px] font-semibold text-slate-700">{ctaLabel}</span>
          </div>

          <div className="flex items-center justify-around px-2 py-1.5 text-[12px] font-medium text-slate-500">
            <span className="flex items-center gap-1.5 px-2 py-1">
              <ThumbsUp className="h-4 w-4" /> Like
            </span>
            <span className="flex items-center gap-1.5 px-2 py-1">
              <MessageCircle className="h-4 w-4" /> Comment
            </span>
            <span className="flex items-center gap-1.5 px-2 py-1">
              <Share2 className="h-4 w-4" /> Share
            </span>
          </div>
        </div>
      )}

      {tab === 'instagram_post' && (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-2 px-3 py-2.5">
            <InitialsAvatar name={displayName} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-[13px] font-semibold text-slate-900">{displayName.toLowerCase().replace(/\s+/g, '')}</p>
              {isSponsored && <p className="text-[11px] text-slate-500">Sponsored</p>}
            </div>
            <MoreHorizontal className="h-4 w-4 flex-shrink-0 text-slate-400" />
          </div>

          <MediaFrame mediaUrl={mediaUrl} mediaType={mediaType} aspectClass="aspect-square" />

          <div className="flex items-center justify-between px-3 pt-2.5">
            <div className="flex items-center gap-3">
              <Heart className="h-5 w-5 text-slate-700" />
              <MessageCircle className="h-5 w-5 text-slate-700" />
              <Send className="h-5 w-5 text-slate-700" />
            </div>
            <Bookmark className="h-5 w-5 text-slate-700" />
          </div>

          <div className="px-3 pb-3 pt-1.5 text-[13px] leading-snug text-slate-800">
            <span className="font-semibold text-slate-900">{displayName.toLowerCase().replace(/\s+/g, '')}</span>{' '}
            <span className="whitespace-pre-line">{displayText}</span>
          </div>

          {isSponsored && (
            <div className="flex items-center justify-between border-t border-slate-100 px-3 py-2.5">
              <p className="truncate text-[12px] font-medium text-slate-500">{displayHeadline}</p>
              <span className="flex-shrink-0 text-[12px] font-semibold" style={{ color: indigo.accentSolid }}>
                {ctaLabel}
              </span>
            </div>
          )}
        </div>
      )}

      {tab === 'instagram_story' && (
        <div className="relative mx-auto aspect-[9/16] w-full max-w-[220px] overflow-hidden rounded-2xl border border-slate-200 bg-black shadow-sm">
          <div className="absolute inset-x-0 top-0 z-10 flex items-center gap-2 bg-gradient-to-b from-black/60 to-transparent px-3 pb-6 pt-3">
            <InitialsAvatar name={displayName} />
            <p className="truncate text-[12px] font-semibold text-white">{displayName.toLowerCase().replace(/\s+/g, '')}</p>
            {isSponsored && <span className="flex-shrink-0 text-[10px] font-medium text-white/70">Sponsored</span>}
          </div>

          <div className="absolute inset-0">
            <MediaFrame mediaUrl={mediaUrl} mediaType={mediaType} aspectClass="h-full" />
          </div>

          <div className="absolute inset-x-0 bottom-0 z-10 flex flex-col items-center gap-2 bg-gradient-to-t from-black/70 to-transparent px-4 pb-4 pt-8">
            <p className="line-clamp-2 text-center text-[12px] font-medium text-white">{displayHeadline}</p>
            <span className="flex items-center gap-1.5 rounded-full bg-white px-4 py-1.5 text-[11px] font-semibold text-slate-900">
              <Globe2 className="h-3.5 w-3.5" /> {ctaLabel}
            </span>
          </div>
        </div>
      )}
    </div>
  );
}
