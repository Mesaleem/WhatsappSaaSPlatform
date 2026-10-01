import { useEffect, useRef, useState } from 'react';
import { Loader2, MapPin, X } from 'lucide-react';
import adsService from '../../services/adsService';
import { inputClass } from '../common/Card';
import { extractErrorMessage } from '../../utils/apiError';
import type { AdLocation } from '../../types/ads';

const TYPE_LABEL: Record<AdLocation['type'], string> = { country: 'Country', region: 'State / region', city: 'City' };

function describe(location: AdLocation): string {
  if (location.type === 'country') return location.name;
  return [location.name, location.type === 'city' ? location.region : null, location.country_name].filter(Boolean).join(', ');
}

/**
 * Owner request (2026-09-30) — ad locations picked through Meta's own location
 * search (GET /social/ads/locations → Meta adgeolocation), so every country,
 * state or city carries the key Meta targets by. Typing debounces 300 ms and
 * needs at least 2 characters; a late answer for an older query is ignored.
 */
export default function AdLocationPicker({
  value,
  onChange,
  invalid = false,
}: {
  value: AdLocation[];
  onChange: (next: AdLocation[]) => void;
  invalid?: boolean;
}) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<AdLocation[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const seq = useRef(0);

  const changeQuery = (next: string) => {
    setQuery(next);
    const searching = next.trim().length >= 2;
    setIsSearching(searching);
    if (!searching) setResults([]);
  };

  useEffect(() => {
    const term = query.trim();
    const mine = ++seq.current;
    if (term.length < 2) return undefined;
    const timer = setTimeout(() => {
      Promise.resolve()
        .then(() => adsService.locations(term))
        .then((rows) => {
          if (mine === seq.current) {
            setResults(rows);
            setError(null);
          }
        })
        .catch((err: unknown) => {
          if (mine === seq.current) setError(extractErrorMessage(err, 'Could not search locations.'));
        })
        .finally(() => {
          if (mine === seq.current) setIsSearching(false);
        });
    }, 300);
    return () => clearTimeout(timer);
  }, [query]);

  const add = (location: AdLocation) => {
    if (!value.some((v) => v.key === location.key && v.type === location.type)) onChange([...value, location]);
    setQuery('');
    setResults([]);
  };

  return (
    <div data-testid="ad-location-picker">
      {value.length > 0 && (
        <div className="mt-1 flex flex-wrap gap-2" data-testid="ad-locations-selected">
          {value.map((location) => (
            <span key={`${location.type}:${location.key}`} className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800">
              <MapPin className="h-3 w-3" />
              {describe(location)}
              <span className="text-indigo-500">· {TYPE_LABEL[location.type]}</span>
              <button
                type="button"
                aria-label={`Remove ${location.name}`}
                onClick={() => onChange(value.filter((v) => !(v.key === location.key && v.type === location.type)))}
                className="ml-0.5 rounded-full p-0.5 hover:bg-indigo-100"
              >
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
        </div>
      )}
      <div className="relative">
        <input
          type="text"
          aria-label="Search locations"
          aria-invalid={invalid || undefined}
          className={`${inputClass} ${invalid ? 'border-red-400' : ''}`}
          value={query}
          onChange={(e) => changeQuery(e.target.value)}
          placeholder="Search a country, state or city — e.g. Mumbai, Maharashtra, India"
        />
        {isSearching && <Loader2 className="absolute right-3 top-1/2 mt-0.5 h-4 w-4 -translate-y-1/2 animate-spin text-slate-400" />}
      </div>
      {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
      {results.length > 0 && (
        <ul className="mt-1 max-h-56 overflow-auto rounded-lg border border-slate-200 bg-white shadow-sm" data-testid="ad-location-results">
          {results.map((location) => (
            <li key={`${location.type}:${location.key}`}>
              <button type="button" onClick={() => add(location)} className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-indigo-50">
                <span className="text-slate-800">{describe(location)}</span>
                <span className="text-xs text-slate-500">{TYPE_LABEL[location.type]}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {!isSearching && !error && query.trim().length >= 2 && results.length === 0 && <p className="mt-1 text-xs text-slate-500">No matching location.</p>}
    </div>
  );
}
