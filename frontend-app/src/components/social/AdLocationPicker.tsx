import { useEffect, useRef, useState } from 'react';
import { Crosshair, Loader2, MapPin, X } from 'lucide-react';
import adsService from '../../services/adsService';
import { inputClass } from '../common/Card';
import { extractErrorMessage } from '../../utils/apiError';
import { CITY_RADIUS_KM, PIN_RADIUS_KM, type AdCustomLocation, type AdLocation } from '../../types/ads';

const TYPE_LABEL: Record<AdLocation['type'], string> = { country: 'Country', region: 'State / region', city: 'City' };

const CITY_RADIUS_OPTIONS = [17, 25, 40, 60, 80];
const DEFAULT_PIN_RADIUS_KM = 10;

function describe(location: AdLocation): string {
  if (location.type === 'country') return location.name;
  return [location.name, location.type === 'city' ? location.region : null, location.country_name].filter(Boolean).join(', ');
}

function clampPinRadius(value: number): number {
  return Math.min(PIN_RADIUS_KM.max, Math.max(PIN_RADIUS_KM.min, Math.round(value) || PIN_RADIUS_KM.min));
}

/**
 * Owner request (2026-09-30) — ad locations picked through Meta's own location
 * search (GET /social/ads/locations → Meta adgeolocation), so every country,
 * state or city carries the key Meta targets by. Typing debounces 300 ms and
 * needs at least 2 characters; a late answer for an older query is ignored.
 * Phase A (2026-10-15): a city can widen to a radius (Meta 17-80 km), and
 * "Use my current location" pins the browser's position with a 1-80 km radius.
 */
export default function AdLocationPicker({
  value,
  onChange,
  customLocations = [],
  onCustomChange,
  invalid = false,
}: {
  value: AdLocation[];
  onChange: (next: AdLocation[]) => void;
  customLocations?: AdCustomLocation[];
  onCustomChange?: (next: AdCustomLocation[]) => void;
  invalid?: boolean;
}) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<AdLocation[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isLocating, setIsLocating] = useState(false);
  const [locateError, setLocateError] = useState<string | null>(null);
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

  const setCityRadius = (location: AdLocation, radius: number | null) =>
    onChange(value.map((v) => (v.key === location.key && v.type === location.type ? { ...v, radius_km: radius } : v)));

  const useCurrentLocation = () => {
    if (!onCustomChange) return;
    if (!('geolocation' in navigator)) {
      setLocateError('This browser cannot share its location. Search a city instead.');
      return;
    }
    setIsLocating(true);
    setLocateError(null);
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        setIsLocating(false);
        onCustomChange([
          ...customLocations,
          { latitude: Number(coords.latitude.toFixed(6)), longitude: Number(coords.longitude.toFixed(6)), radius_km: DEFAULT_PIN_RADIUS_KM, name: 'My current location' },
        ]);
      },
      () => {
        setIsLocating(false);
        setLocateError('Location access was refused or is unavailable. Allow it in the browser, or search a city instead.');
      },
      { enableHighAccuracy: true, timeout: 15000 },
    );
  };

  // Typed freely (an empty field reads 0 and pauses the estimate); snapped into 1-80 km on blur.
  const setPinRadius = (index: number, radius: number) =>
    onCustomChange?.(customLocations.map((pin, i) => (i === index ? { ...pin, radius_km: radius } : pin)));

  return (
    <div data-testid="ad-location-picker">
      {value.length > 0 && (
        <div className="mt-1 flex flex-wrap gap-2" data-testid="ad-locations-selected">
          {value.map((location) => (
            <span key={`${location.type}:${location.key}`} className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-900">
              <MapPin className="h-3 w-3" />
              {describe(location)}
              <span className="text-emerald-600">· {TYPE_LABEL[location.type]}</span>
              {location.type === 'city' && (
                <select
                  aria-label={`Radius around ${location.name}`}
                  value={location.radius_km ?? ''}
                  onChange={(e) => setCityRadius(location, e.target.value ? Number(e.target.value) : null)}
                  className="ml-1 rounded border border-emerald-200 bg-white px-1 py-0.5 text-xs text-emerald-900"
                >
                  <option value="">City only</option>
                  {CITY_RADIUS_OPTIONS.map((km) => (
                    <option key={km} value={km}>
                      + {km} km
                    </option>
                  ))}
                </select>
              )}
              <button
                type="button"
                aria-label={`Remove ${location.name}`}
                onClick={() => onChange(value.filter((v) => !(v.key === location.key && v.type === location.type)))}
                className="ml-0.5 rounded-full p-0.5 hover:bg-emerald-100"
              >
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
        </div>
      )}
      {customLocations.length > 0 && (
        <div className="mt-1 flex flex-wrap gap-2" data-testid="ad-custom-locations">
          {customLocations.map((pin, index) => (
            <span key={`${pin.latitude},${pin.longitude},${index}`} className="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-900">
              <Crosshair className="h-3 w-3" />
              {pin.name || `${pin.latitude}, ${pin.longitude}`}
              <span className="text-teal-700">· within</span>
              <input
                type="number"
                aria-label={`Radius in km around ${pin.name || 'pinned location'}`}
                min={PIN_RADIUS_KM.min}
                max={PIN_RADIUS_KM.max}
                value={pin.radius_km || ''}
                onChange={(e) => setPinRadius(index, Number(e.target.value))}
                onBlur={() => setPinRadius(index, clampPinRadius(pin.radius_km))}
                className="w-14 rounded border border-teal-200 bg-white px-1 py-0.5 text-xs text-teal-900"
              />
              <span className="text-teal-700">km</span>
              <button
                type="button"
                aria-label={`Remove ${pin.name || 'pinned location'}`}
                onClick={() => onCustomChange?.(customLocations.filter((_, i) => i !== index))}
                className="ml-0.5 rounded-full p-0.5 hover:bg-teal-100"
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
      {onCustomChange && (
        <button
          type="button"
          onClick={useCurrentLocation}
          disabled={isLocating}
          className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-60"
        >
          {isLocating ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Crosshair className="h-3.5 w-3.5" />}
          {isLocating ? 'Finding your location…' : 'Use my current location'}
        </button>
      )}
      {locateError && <p className="mt-1 text-xs text-red-600">{locateError}</p>}
      {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
      {results.length > 0 && (
        <ul className="mt-1 max-h-56 overflow-auto rounded-lg border border-slate-200 bg-white shadow-sm" data-testid="ad-location-results">
          {results.map((location) => (
            <li key={`${location.type}:${location.key}`}>
              <button type="button" onClick={() => add(location)} className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-emerald-50">
                <span className="text-slate-800">{describe(location)}</span>
                <span className="text-xs text-slate-500">{TYPE_LABEL[location.type]}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {!isSearching && !error && query.trim().length >= 2 && results.length === 0 && <p className="mt-1 text-xs text-slate-500">No matching location.</p>}
      <p className="mt-1 text-xs text-slate-500">
        A city can widen to {CITY_RADIUS_KM.min}–{CITY_RADIUS_KM.max} km around it; a pinned location reaches {PIN_RADIUS_KM.min}–{PIN_RADIUS_KM.max} km.
      </p>
    </div>
  );
}
