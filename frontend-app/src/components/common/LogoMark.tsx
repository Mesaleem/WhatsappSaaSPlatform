import { useId } from 'react';

/**
 * The WapHub icon: a chat bubble holding a hub (one centre linked to three
 * points). Same drawing as public/brand/waphub-icon.svg and the favicon.
 */
export default function LogoMark({ className = 'h-9 w-9' }: { className?: string }) {
  const id = useId().replace(/:/g, '');
  return (
    <svg viewBox="0 0 64 64" fill="none" className={className} aria-hidden="true">
      <defs>
        <linearGradient id={`${id}-bg`} x1="6" y1="2" x2="58" y2="62" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="#34d399" />
          <stop offset="0.55" stopColor="#10b981" />
          <stop offset="1" stopColor="#0f766e" />
        </linearGradient>
        <linearGradient id={`${id}-shine`} x1="32" y1="0" x2="32" y2="34" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="#ffffff" stopOpacity="0.22" />
          <stop offset="1" stopColor="#ffffff" stopOpacity="0" />
        </linearGradient>
      </defs>
      <rect width="64" height="64" rx="15" fill={`url(#${id}-bg)`} />
      <rect width="64" height="64" rx="15" fill={`url(#${id}-shine)`} />
      <path
        d="M32 12.5c10.5 0 19 7.9 19 17.8S42.5 48 32 48c-2.6 0-5.1-.5-7.4-1.4L15.5 50l2.7-8.1A17.2 17.2 0 0 1 13 30.3c0-9.9 8.5-17.8 19-17.8z"
        fill="#ffffff"
      />
      <g stroke="#0f9f76" strokeWidth="2.4" strokeLinecap="round">
        <line x1="32" y1="30.5" x2="23.5" y2="25" />
        <line x1="32" y1="30.5" x2="40.5" y2="25" />
        <line x1="32" y1="30.5" x2="32" y2="39.5" />
      </g>
      <circle cx="23.5" cy="25" r="3.1" fill="#10b981" />
      <circle cx="40.5" cy="25" r="3.1" fill="#10b981" />
      <circle cx="32" cy="39.5" r="3.1" fill="#10b981" />
      <circle cx="32" cy="30.5" r="4.4" fill="#0f766e" />
    </svg>
  );
}
