import { useEffect, useRef, useState } from 'react';
import { AlertCircle, CalendarClock, CheckCircle2, Clock, Loader2, Trash2, UploadCloud, X, XCircle } from 'lucide-react';
import { inputClass } from '../common/Card';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import mediaService from '../../services/mediaService';
import organicPostService from '../../services/organicPostService';
import { extractErrorMessage } from '../../utils/apiError';
import { socialConnectionError, type SocialConnectionErrorInfo } from '../../utils/socialConnectionError';
import SocialReconnectNotice from './SocialReconnectNotice';
import { formatScheduledAt, LINKEDIN_UNAVAILABLE_NOTE, ORGANIC_PLATFORM_LABELS } from '../../types/organic';
import type { OrganicPlatform, OrganicPost } from '../../types/organic';
import type { MediaType } from '../../types/media';

/** P5-9 — an Instagram video is published in the background once Meta finishes processing it. */
const PENDING_PUBLISH_NOTE = 'Processing — Instagram is still preparing the video; it will be published automatically.';

const PLATFORMS: OrganicPlatform[] = ['facebook', 'instagram', 'linkedin'];

/** Phase 9 Task 3 — one key per submitted form content; a resubmission of the same post reuses it. */
const newSubmissionKey = () => `org-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

/** `YYYY-MM-DDTHH:mm` in local time, for <input type="datetime-local">. */
const toLocalInputValue = (date: Date) => {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

function resultText(post: OrganicPost): string {
  switch (post.status) {
    case 'published':
      return `Published (id: ${post.external_post_id ?? '—'})`;
    case 'pending':
      return PENDING_PUBLISH_NOTE;
    case 'scheduled':
      return `Scheduled for ${formatScheduledAt(post.scheduled_at)}`;
    case 'publishing':
      return 'Publishing…';
    default:
      return post.error_message ?? 'Publish failed.';
  }
}

/**
 * Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel Publishing
 * Engine). Mode Toggle's "Organic Post" side — a deliberately simpler,
 * single-step form than LaunchWizardModal's 3-step paid flow: an organic
 * post has no budget, targeting, or objective to configure, just a
 * caption, optional media, and which connected platform(s) to publish to.
 *
 * Publishing to more than one platform is done as one POST per platform
 * (the backend's OrganicPost row and OrganicPublishService::publish()
 * are both single-platform by design, one row per attempt — see that
 * migration's docblock) — results are shown per-platform below the form
 * rather than as a single pass/fail.
 */
export default function OrganicPostModal({
  onClose,
  onPublished,
  canManageSocialAccounts = false,
}: {
  onClose: () => void;
  onPublished: (posts: OrganicPost[]) => void;
  canManageSocialAccounts?: boolean;
}) {
  const [selectedPlatforms, setSelectedPlatforms] = useState<OrganicPlatform[]>(['facebook']);
  const [caption, setCaption] = useState('');
  const [mediaUrl, setMediaUrl] = useState<string | null>(null);
  const [mediaType, setMediaType] = useState<MediaType | null>(null);

  const fileInputRef = useRef<HTMLInputElement>(null);
  const [isUploadingMedia, setIsUploadingMedia] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [mediaError, setMediaError] = useState<string | null>(null);

  const [formError, setFormError] = useState<string | null>(null);
  // Phase 9 Task 2 — the Page/Instagram connection expired or was revoked.
  const [connectionError, setConnectionError] = useState<SocialConnectionErrorInfo | null>(null);
  const [isPublishing, setIsPublishing] = useState(false);
  const [results, setResults] = useState<OrganicPost[] | null>(null);

  // Phase 9 Task 3 — publish now, or schedule for later.
  const [when, setWhen] = useState<'now' | 'schedule'>('now');
  const [scheduledAt, setScheduledAt] = useState('');
  // Reused while the form content is unchanged, so a double click or a retry
  // after an error never creates a second post; any edit starts a new one.
  const submissionKey = useRef<string | null>(null);
  useEffect(() => {
    submissionKey.current = null;
  }, [caption, mediaUrl, mediaType, selectedPlatforms, when, scheduledAt]);

  const togglePlatform = (platform: OrganicPlatform) => {
    setSelectedPlatforms((prev) => (prev.includes(platform) ? prev.filter((p) => p !== platform) : [...prev, platform]));
  };

  const handleFileSelected = (file: File | undefined | null) => {
    if (!file) return;
    setMediaError(null);
    setIsUploadingMedia(true);
    setUploadProgress(0);
    mediaService
      .upload(file, setUploadProgress)
      .then((uploaded) => {
        setMediaUrl(uploaded.url);
        setMediaType(uploaded.type);
      })
      .catch((err: unknown) => setMediaError(extractErrorMessage(err, 'Failed to upload media.')))
      .finally(() => setIsUploadingMedia(false));
  };

  const handleRemoveMedia = () => {
    setMediaUrl(null);
    setMediaType(null);
    setMediaError(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const handlePublish = () => {
    if (selectedPlatforms.length === 0) {
      setFormError('Select at least one platform.');
      return;
    }
    if (!caption.trim()) {
      setFormError('Caption is required.');
      return;
    }
    // Instagram has no text-only post type (see OrganicPublishService::publishToInstagram()'s
    // own guard) — checked client-side too so the tenant sees a clear reason before submitting.
    if (selectedPlatforms.includes('instagram') && !mediaUrl) {
      setFormError('Instagram requires an image or video — add media above, or deselect Instagram.');
      return;
    }

    let scheduledIso: string | null = null;
    if (when === 'schedule') {
      const at = scheduledAt ? new Date(scheduledAt) : null;
      if (!at || Number.isNaN(at.getTime())) {
        setFormError('Choose the date and time to publish.');
        return;
      }
      if (at.getTime() <= Date.now() + 60_000) {
        setFormError('The scheduled time must be in the future.');
        return;
      }
      scheduledIso = at.toISOString();
    }

    setFormError(null);
    setConnectionError(null);
    setIsPublishing(true);
    setResults(null);

    submissionKey.current ??= newSubmissionKey();
    const key = submissionKey.current;

    // One request per platform; each settles on its own so a failure on one
    // platform never hides a post that was already created on another.
    Promise.allSettled(
      selectedPlatforms.map((platform) =>
        organicPostService.publish({
          platform,
          caption: caption.trim(),
          media_url: mediaUrl,
          media_type: mediaType,
          scheduled_at: scheduledIso,
          idempotency_key: `${key}-${platform}`,
        }),
      ),
    )
      .then((settled) => {
        const posts = settled.flatMap((r) => (r.status === 'fulfilled' ? [r.value] : []));
        const failures = settled.flatMap((r) => (r.status === 'rejected' ? [r.reason as unknown] : []));
        const connection = failures.map((err) => socialConnectionError(err)).find((c) => c !== null) ?? null;

        if (posts.length > 0) {
          setResults(posts);
          onPublished(posts);
        }
        if (connection) {
          setConnectionError(connection);
        } else if (failures.length > 0) {
          setFormError(extractErrorMessage(failures[0], 'Failed to publish the post.'));
        }
      })
      .finally(() => setIsPublishing(false));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            {when === 'schedule' ? 'Schedule Organic Post' : 'Publish Organic Post'}
          </h2>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4 space-y-4">
          {connectionError && <SocialReconnectNotice info={connectionError} canManageSocialAccounts={canManageSocialAccounts} />}

          {formError && (
            <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              {formError}
            </div>
          )}

          {results ? (
            <div className="space-y-2">
              {results.map((post) => (
                <div key={post.id} className="flex items-start gap-2 rounded-xl border border-slate-200 p-3">
                  {post.status === 'published' ? (
                    <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0 text-emerald-600" />
                  ) : post.status === 'scheduled' ? (
                    <CalendarClock className="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-600" />
                  ) : post.status === 'pending' || post.status === 'publishing' ? (
                    <Clock className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600" />
                  ) : (
                    <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0 text-red-600" />
                  )}
                  <div className="min-w-0 flex-1 text-sm">
                    <p className="font-medium text-slate-900">{ORGANIC_PLATFORM_LABELS[post.platform]}</p>
                    <p style={{ color: indigo.muted }}>{resultText(post)}</p>
                  </div>
                </div>
              ))}
              <button
                type="button"
                onClick={onClose}
                className="mt-2 w-full rounded-lg px-4 py-2 text-sm font-semibold text-white"
                style={{ background: activeGradient }}
              >
                Done
              </button>
            </div>
          ) : (
            <>
              <div>
                <label className="block text-sm font-medium text-slate-700">Publish To</label>
                <div className="mt-1.5 flex flex-wrap gap-2">
                  {PLATFORMS.map((platform) => (
                    <button
                      key={platform}
                      type="button"
                      onClick={() => togglePlatform(platform)}
                      className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${
                        selectedPlatforms.includes(platform)
                          ? 'border-transparent text-white'
                          : 'border-slate-200 text-slate-600 hover:border-indigo-300'
                      }`}
                      style={selectedPlatforms.includes(platform) ? { background: activeGradient } : undefined}
                    >
                      {ORGANIC_PLATFORM_LABELS[platform]}
                    </button>
                  ))}
                </div>
                {selectedPlatforms.includes('linkedin') && (
                  <p className="mt-1.5 text-[11px] italic" style={{ color: indigo.muted }}>
                    {LINKEDIN_UNAVAILABLE_NOTE}
                  </p>
                )}
              </div>

              <label className="block text-sm font-medium text-slate-700">
                Caption <span className="text-red-500">*</span>
                <textarea
                  className={inputClass}
                  rows={4}
                  value={caption}
                  onChange={(e) => setCaption(e.target.value)}
                  placeholder="Write your post…"
                />
              </label>

              <div>
                <label className="block text-sm font-medium text-slate-700">Media (optional)</label>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm"
                  className="hidden"
                  onChange={(e) => handleFileSelected(e.target.files?.[0])}
                />
                {mediaUrl ? (
                  <div className="mt-1 flex items-center gap-3 rounded-xl border border-slate-200 p-2">
                    {mediaType === 'video' ? (
                      // eslint-disable-next-line jsx-a11y/media-has-caption
                      <video src={mediaUrl} className="h-16 w-16 flex-shrink-0 rounded-lg bg-black object-cover" muted />
                    ) : (
                      <img src={mediaUrl} alt="Uploaded media" className="h-16 w-16 flex-shrink-0 rounded-lg object-cover" />
                    )}
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-xs font-medium text-slate-700">Media uploaded</p>
                      <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        className="text-xs font-semibold"
                        style={{ color: indigo.accentSolid }}
                      >
                        Replace
                      </button>
                    </div>
                    <button
                      type="button"
                      onClick={handleRemoveMedia}
                      className="flex-shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                      aria-label="Remove media"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={isUploadingMedia}
                    className="mt-1 flex w-full flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-slate-200 px-4 py-6 text-center hover:border-indigo-300 hover:bg-indigo-50/30 disabled:opacity-60"
                  >
                    {isUploadingMedia ? (
                      <>
                        <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.accentSolid }} />
                        <span className="text-xs font-medium text-slate-600">Uploading… {uploadProgress}%</span>
                      </>
                    ) : (
                      <>
                        <UploadCloud className="h-5 w-5" style={{ color: indigo.muted }} />
                        <span className="text-xs font-medium text-slate-600">Click to upload an image or video</span>
                        <span className="text-[11px] text-slate-400">JPG, PNG, WEBP, GIF, MP4, MOV, WEBM — up to 50MB</span>
                      </>
                    )}
                  </button>
                )}
                {mediaError && <p className="mt-1.5 text-xs font-medium text-red-600">{mediaError}</p>}
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">When</label>
                <div className="mt-1.5 flex gap-2" role="radiogroup" aria-label="When to publish">
                  {(['now', 'schedule'] as const).map((option) => (
                    <button
                      key={option}
                      type="button"
                      role="radio"
                      aria-checked={when === option}
                      onClick={() => {
                        setWhen(option);
                        if (option === 'schedule' && !scheduledAt) setScheduledAt(toLocalInputValue(new Date(Date.now() + 60 * 60 * 1000)));
                      }}
                      className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${
                        when === option ? 'border-transparent text-white' : 'border-slate-200 text-slate-600 hover:border-indigo-300'
                      }`}
                      style={when === option ? { background: activeGradient } : undefined}
                    >
                      {option === 'now' ? 'Publish now' : 'Schedule'}
                    </button>
                  ))}
                </div>
                {when === 'schedule' && (
                  <label className="mt-2 block text-xs font-medium text-slate-600">
                    Publish at (your local time)
                    <input
                      type="datetime-local"
                      className={inputClass}
                      value={scheduledAt}
                      min={toLocalInputValue(new Date())}
                      onChange={(e) => setScheduledAt(e.target.value)}
                      data-testid="organic-scheduled-at"
                    />
                  </label>
                )}
              </div>

              <button
                type="button"
                onClick={handlePublish}
                disabled={isPublishing || isUploadingMedia}
                className="flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                style={{ background: activeGradient }}
              >
                {isPublishing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                {isPublishing ? (when === 'schedule' ? 'Scheduling…' : 'Publishing…') : when === 'schedule' ? 'Schedule Post' : 'Publish Now'}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
