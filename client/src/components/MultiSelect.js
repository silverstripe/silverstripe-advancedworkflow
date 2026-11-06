import React, { useState, useRef, useEffect } from 'react';
import Icon from './Icon';

/**
 * Compact, searchable multi-select. Collapsed it shows the chosen items as
 * removable chips plus a search box; focusing/typing reveals a filtered,
 * scrollable option list beneath it.
 *
 * Two modes:
 *  - Static: pass `options` (id => label). Filtering happens client-side. Use
 *    for small, bounded lists (e.g. groups).
 *  - Async: pass `onSearch(term) => Promise<[{id, name}]>`. Options are fetched
 *    on demand (debounced) so large lists (e.g. users on a big site) never load
 *    up front. Pass `labels` (id => name) so already-selected items still show
 *    their names as chips.
 *
 * Fully React-controlled (unlike a native <select multiple>, which the CMS
 * auto-enhances and desyncs from React state).
 */
export default function MultiSelect({ options, value, onChange, placeholder = 'Search…', onSearch, labels }) {
  const isAsync = typeof onSearch === 'function';
  const selected = (value || []).map(String);
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  // Async label cache: seeded from `labels`, augmented by search results, so
  // chips always show a name even for ids not in the current results.
  const [labelCache, setLabelCache] = useState({});
  const ref = useRef(null);
  const timer = useRef(null);

  useEffect(() => {
    const onDocMouseDown = (e) => {
      if (ref.current && !ref.current.contains(e.target)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', onDocMouseDown);
    return () => document.removeEventListener('mousedown', onDocMouseDown);
  }, []);

  // Seed the label cache from the labels prop (keeping any learned labels).
  useEffect(() => {
    if (labels) {
      setLabelCache((cache) => ({ ...labels, ...cache }));
    }
  }, [labels]);

  // Debounced async search whenever the dropdown is open and the query changes.
  useEffect(() => {
    if (!isAsync || !open) {
      return undefined;
    }
    clearTimeout(timer.current);
    setLoading(true);
    timer.current = setTimeout(() => {
      Promise.resolve(onSearch(query.trim()))
        .then((res) => {
          const list = Array.isArray(res) ? res : [];
          setResults(list);
          setLabelCache((cache) => {
            const next = { ...cache };
            list.forEach((o) => { next[String(o.id)] = o.name; });
            return next;
          });
        })
        .catch(() => setResults([]))
        .finally(() => setLoading(false));
    }, 250);
    return () => clearTimeout(timer.current);
  }, [query, open, isAsync, onSearch]);

  if (!isAsync && Object.keys(options || {}).length === 0) {
    return <p className="wf-builder__field-help">No options available.</p>;
  }

  const labelFor = (id) => {
    if (isAsync) {
      return labelCache[String(id)] !== undefined ? labelCache[String(id)] : id;
    }
    return options[id] !== undefined ? options[id] : id;
  };

  const emit = (ids) => onChange(ids.map((v) => parseInt(v, 10)));
  const toggle = (id) => {
    const set = new Set(selected);
    if (set.has(String(id))) { set.delete(String(id)); } else { set.add(String(id)); }
    emit(Array.from(set));
  };
  const remove = (id) => emit(selected.filter((s) => s !== String(id)));

  const q = query.trim().toLowerCase();
  const menuOptions = isAsync
    ? results.map((o) => [String(o.id), o.name])
    : Object.entries(options).filter(([, name]) => !q || String(name).toLowerCase().includes(q));

  return (
    <div className={`wf-ms ${open ? 'wf-ms--open' : ''}`} ref={ref}>
      <div className="wf-ms__control" onClick={() => setOpen(true)} role="presentation">
        {selected.length === 0 && !query && <span className="wf-ms__placeholder">None selected</span>}
        {selected.map((id) => (
          <span className="wf-ms__chip" key={id}>
            {labelFor(id)}
            <button
              type="button"
              className="wf-ms__chip-remove"
              onClick={(e) => { e.stopPropagation(); remove(id); }}
              aria-label={`Remove ${labelFor(id)}`}
            >
              <Icon name="cross" />
            </button>
          </span>
        ))}
        <input
          className="wf-ms__input"
          type="text"
          value={query}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)}
          placeholder={selected.length === 0 ? placeholder : 'Add more…'}
        />
      </div>

      {open && (
        <div className="wf-ms__menu">
          {loading && <div className="wf-ms__empty">Searching…</div>}
          {!loading && menuOptions.length === 0 && (
            <div className="wf-ms__empty">
              {isAsync && q === '' ? 'Type to search' : 'No matches'}
            </div>
          )}
          {!loading && menuOptions.map(([id, name]) => (
            <label className="wf-ms__option" key={id}>
              <input
                type="checkbox"
                checked={selected.includes(String(id))}
                onChange={() => toggle(id)}
              />
              <span>{name}</span>
            </label>
          ))}
        </div>
      )}
    </div>
  );
}
