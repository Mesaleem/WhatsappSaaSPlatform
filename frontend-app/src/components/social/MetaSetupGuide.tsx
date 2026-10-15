import { AlertCircle, Camera, CheckCircle2, ExternalLink, Globe, Info, Loader2, Megaphone, Plug, RefreshCw } from 'lucide-react';
import type { OAuthAssetSummary, OfferedAsset } from '../../types/social';

/**
 * Guided setup for clients who only have a personal Facebook profile (owner request, 2026-10-15).
 *
 * Meta's API cannot create a Facebook Page, switch Instagram to a professional account or open an ad account with
 * billing — the client has to do that on Facebook / Instagram themselves. This shows the plain-language steps (wording
 * from docs/WapHub-Social-Media-Connection-Guide.docx, Part 4) before connecting, and after a connect it says exactly
 * which of the three is missing instead of a generic "nothing found".
 */

export type MetaSetupItem = 'facebook_page' | 'instagram' | 'meta_ad_account';

interface SetupItemContent {
  title: string;
  /** Short headline used when a connect showed this item is missing. */
  missingTitle: string;
  why: string;
  steps: string[];
  link?: { label: string; href: string };
  optional?: boolean;
}

export const META_SETUP_ITEMS: Record<MetaSetupItem, SetupItemContent> = {
  facebook_page: {
    title: 'A Facebook Page for your business',
    missingTitle: 'No Facebook Page found',
    why: 'WapHub posts, replies and runs ads through a Facebook Page. A personal Facebook profile cannot be connected.',
    steps: [
      'Log in to Facebook with your own account — the one you will use to connect WapHub.',
      'Click “Create a Facebook Page” below, type your business name and pick a category, then click Create Page.',
      'Add a profile picture and contact details if you like. You can finish them later.',
      'Already have a Page? Check you are its admin with full control (Page → Settings → Page access).',
    ],
    link: { label: 'Create a Facebook Page', href: 'https://www.facebook.com/pages/create' },
  },
  instagram: {
    title: 'An Instagram Business or Creator account, linked to the Page',
    missingTitle: 'No Instagram Business account linked to your Page',
    why: 'Only a professional Instagram account that is linked to your Facebook Page can be connected. A personal Instagram account will not show up.',
    steps: [
      'Open the Instagram app on your phone and go to your profile.',
      'Tap the menu (☰) → Settings → Account type and tools → Switch to professional account, and choose Business (or Creator).',
      'On Facebook, open your Page → Settings → Linked accounts → Instagram → Connect account, and log in to Instagram.',
      'Not using Instagram for your business? You can skip this step.',
    ],
  },
  meta_ad_account: {
    title: 'A Meta ad account with a payment method',
    missingTitle: 'No ad account found',
    why: 'Needed only to run paid ads from WapHub. Meta charges your ad spend directly to this account.',
    steps: [
      'Click “Open Meta Business Suite” below and log in with the same Facebook account.',
      'Go to Settings → Accounts → Ad accounts → Add → Create a new ad account.',
      'Give it a name, choose your time zone and currency, and give yourself full control.',
      'Open Billing & payments and add a payment method.',
    ],
    link: { label: 'Open Meta Business Suite', href: 'https://business.facebook.com/' },
    optional: true,
  },
};

const ITEM_ICON: Record<MetaSetupItem, typeof Globe> = {
  facebook_page: Globe,
  instagram: Camera,
  meta_ad_account: Megaphone,
};

const ALL_ITEMS: MetaSetupItem[] = ['facebook_page', 'instagram', 'meta_ad_account'];

export interface ConnectDiagnosis {
  /** What the client still has to set up on Facebook / Instagram, in setup order. */
  missing: MetaSetupItem[];
  /** Nothing can be connected from this attempt (no asset to choose). */
  blocking: boolean;
  /** Facebook shared something, but none of it is a platform the Super Admin enabled for this account. */
  notEnabled: boolean;
}

/**
 * Which setup step a connect attempt shows is missing. Uses the backend summary (counts BEFORE the per-account
 * platform filter) when present; an older payload without it falls back to counting the offered assets.
 */
export function diagnoseConnect(assets: OfferedAsset[], summary: OAuthAssetSummary | null | undefined, planHasAdsFallback: boolean): ConnectDiagnosis {
  const count = (type: MetaSetupItem) => summary?.found?.[type] ?? assets.filter((a) => a.asset_type === type).length;
  const enabled = (type: MetaSetupItem) => (summary ? summary.enabled.includes(type) : true);
  const planHasAds = summary ? summary.plan_has_ads : planHasAdsFallback;

  const missing: MetaSetupItem[] = [];
  // Instagram and ads both hang off the Page, so a missing Page is always the first step.
  if (count('facebook_page') === 0) missing.push('facebook_page');
  if (enabled('instagram') && count('instagram') === 0) missing.push('instagram');
  if (planHasAds && enabled('meta_ad_account') && count('meta_ad_account') === 0) missing.push('meta_ad_account');

  const blocking = assets.length === 0;
  const foundAnything = ALL_ITEMS.some((type) => count(type) > 0);

  return { missing, blocking, notEnabled: blocking && foundAnything && missing.length === 0 };
}

function SetupItemCard({ item, index }: { item: MetaSetupItem; index: number }) {
  const content = META_SETUP_ITEMS[item];
  const Icon = ITEM_ICON[item];

  return (
    <li className="rounded-xl border border-slate-200 bg-white p-4" data-testid={`meta-setup-item-${item}`}>
      <div className="flex items-start gap-3">
        <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
          <Icon className="h-4 w-4" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-slate-900">
            {index}. {content.title}
            {content.optional && <span className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Only for ads</span>}
          </p>
          <p className="mt-1 text-sm text-slate-600">{content.why}</p>
          <ol className="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-700">
            {content.steps.map((step) => (
              <li key={step}>{step}</li>
            ))}
          </ol>
          {content.link && (
            <a
              href={content.link.href}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
            >
              {content.link.label}
              <ExternalLink className="h-3.5 w-3.5" />
            </a>
          )}
        </div>
      </div>
    </li>
  );
}

const SHARE_TIP = 'When Facebook asks which Pages, Instagram accounts and ad accounts to share with WapHub, tick them all (or choose “Opt in to all current and future”). Anything left unticked will not show up here.';

/** Before the first connect: what the client needs on Facebook, with a link to each Meta page. */
export function MetaSetupChecklist({ showAds, connecting, onConnect }: { showAds: boolean; connecting: boolean; onConnect: () => void }) {
  const items = ALL_ITEMS.filter((item) => item !== 'meta_ad_account' || showAds);

  return (
    <section className="mb-4 rounded-2xl border border-slate-200 bg-slate-50 p-5" data-testid="meta-setup-checklist">
      <h2 className="text-base font-semibold text-slate-900">Before you connect: what you need on Facebook</h2>
      <p className="mt-1 text-sm text-slate-600">
        WapHub can only connect things that already exist on Facebook and Instagram — they have to be set up by you, on their websites. It takes about 10 minutes. Already have them? Go straight to Connect.
      </p>
      <ul className="mt-4 space-y-3">
        {items.map((item, i) => (
          <SetupItemCard key={item} item={item} index={i + 1} />
        ))}
      </ul>
      <p className="mt-4 flex items-start gap-2 text-xs text-slate-600">
        <Info className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
        {SHARE_TIP}
      </p>
      <button
        type="button"
        onClick={onConnect}
        disabled={connecting}
        className="mt-4 inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
        data-testid="meta-setup-ready"
      >
        {connecting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plug className="h-4 w-4" />}
        I&apos;m ready — Connect Meta Account
      </button>
    </section>
  );
}

/**
 * After a connect: what is still missing. `blocking` = nothing could be connected (no Page, or nothing enabled);
 * otherwise a non-blocking hint shown after the chosen assets were connected.
 */
export function MetaSetupDiagnosis({ diagnosis, connecting, onCheckAgain, isSuperAdmin }: { diagnosis: ConnectDiagnosis; connecting: boolean; onCheckAgain: () => void; isSuperAdmin: boolean }) {
  const { missing, blocking, notEnabled } = diagnosis;

  let title: string;
  let intro: string;
  if (notEnabled) {
    title = 'Facebook shared accounts that are not switched on for you';
    intro = isSuperAdmin
      ? 'Your Facebook login worked, but Facebook / Instagram is not enabled for this client. Enable it in the client’s settings under Accounts, then check again.'
      : 'Your Facebook login worked, but Facebook / Instagram is not enabled for your account yet. Ask your Super Admin to enable it, then check again.';
  } else if (blocking) {
    title = META_SETUP_ITEMS[missing[0] ?? 'facebook_page'].missingTitle;
    intro =
      missing[0] === 'facebook_page' || missing.length === 0
        ? 'Your Facebook login worked, but your Facebook account has no Page that WapHub can use — a personal profile cannot be connected. Follow the steps below, then check again. Already have a Page? Check again and make sure you tick it when Facebook asks which Pages to share.'
        : 'Your Facebook login worked, but nothing WapHub can use was shared. Follow the steps below, then check again.';
  } else {
    title = 'Connected — a few things are still missing';
    intro = 'What you chose is connected. To also use the features below, finish these steps on Facebook / Instagram, then check again.';
  }

  const tone = blocking ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50';

  return (
    <section className={`mb-4 rounded-2xl border p-5 ${tone}`} data-testid={blocking ? 'meta-setup-diagnosis' : 'meta-setup-hint'} role={blocking ? 'alert' : 'status'}>
      <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900">
        {blocking ? <AlertCircle className="h-5 w-5 flex-shrink-0 text-amber-600" /> : <CheckCircle2 className="h-5 w-5 flex-shrink-0 text-emerald-700" />}
        {title}
      </h2>
      <p className="mt-1 text-sm text-slate-700">{intro}</p>
      {!notEnabled && missing.length > 0 && (
        <ul className="mt-4 space-y-3">
          {missing.map((item, i) => (
            <SetupItemCard key={item} item={item} index={i + 1} />
          ))}
        </ul>
      )}
      {!notEnabled && <p className="mt-4 flex items-start gap-2 text-xs text-slate-600"><Info className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />{SHARE_TIP}</p>}
      <button
        type="button"
        onClick={onCheckAgain}
        disabled={connecting}
        className="mt-4 inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
        data-testid="meta-setup-check-again"
      >
        {connecting ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
        I&apos;ve done it — check again
      </button>
    </section>
  );
}
