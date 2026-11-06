import React from 'react';

// ============================================================
// Icon
// Single source of truth for all UI glyphs. Renders an inline
// SVG by semantic name using `currentColor`, so size and colour
// are driven entirely by the surrounding CSS (font-size + color).
// Replaces the emoji/Unicode glyphs previously used as icons.
// ============================================================

// `filled` icons paint with fill; the rest are stroked line icons.
const ICONS = Object.freeze({
  // Step / action types
  approval: {
    paths: (
      <>
        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
        <rect x="8" y="2" width="8" height="4" rx="1" />
        <path d="M9 14l2 2 4-4" />
      </>
    ),
  },
  notification: {
    paths: (
      <>
        <rect x="2" y="4" width="20" height="16" rx="2" />
        <path d="M22 6l-10 7L2 6" />
      </>
    ),
  },
  publish: {
    paths: (
      <>
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
        <path d="M17 8l-5-5-5 5" />
        <path d="M12 3v12" />
      </>
    ),
  },
  unpublish: {
    paths: (
      <>
        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
        <path d="M1 1l22 22" />
      </>
    ),
  },
  action: {
    paths: (
      <>
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
      </>
    ),
  },
  assignment: {
    paths: (
      <>
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
        <circle cx="12" cy="7" r="4" />
      </>
    ),
  },
  cancel: {
    paths: (
      <>
        <circle cx="12" cy="12" r="10" />
        <path d="M4.93 4.93l14.14 14.14" />
      </>
    ),
  },
  'request-changes': {
    paths: (
      <>
        <path d="M23 4v6h-6" />
        <path d="M1 20v-6h6" />
        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
      </>
    ),
  },
  comment: {
    paths: <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />,
  },

  // Marks
  check: {
    paths: <path d="M20 6L9 17l-5-5" />,
  },
  cross: {
    paths: <path d="M18 6L6 18M6 6l12 12" />,
  },
  'arrow-left': {
    paths: <path d="M19 12H5M12 19l-7-7 7-7" />,
  },
  'arrow-up': {
    paths: <path d="M12 19V5M5 12l7-7 7 7" />,
  },
  'arrow-down': {
    paths: <path d="M12 5v14M19 12l-7 7-7-7" />,
  },
  'chevron-down': {
    paths: <path d="M6 9l6 6 6-6" />,
  },
  plus: {
    paths: <path d="M12 5v14M5 12h14" />,
  },
  trash: {
    paths: (
      <>
        <path d="M3 6h18" />
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
        <path d="M10 11v6M14 11v6" />
        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
      </>
    ),
  },
  'chevron-right': {
    paths: <path d="M9 6l6 6-6 6" />,
  },
  dot: {
    filled: true,
    paths: <circle cx="12" cy="12" r="6" />,
  },
  circle: {
    paths: <circle cx="12" cy="12" r="8" />,
  },
  play: {
    filled: true,
    paths: <path d="M7 4v16l13-8z" />,
  },
  pause: {
    filled: true,
    paths: (
      <>
        <rect x="6" y="4" width="4" height="16" rx="1" />
        <rect x="14" y="4" width="4" height="16" rx="1" />
      </>
    ),
  },
});

/**
 * @param {string} name   Icon name from the set above.
 * @param {string} [className]  Extra class names appended to `wf-icon`.
 * @param {string} [title]  Accessible label; when omitted the icon is aria-hidden.
 */
export default function Icon({ name, className = '', title }) {
  const icon = ICONS[name] || ICONS.dot;
  const isFilled = icon.filled === true;
  const classes = className ? `wf-icon ${className}` : 'wf-icon';

  return (
    <svg
      className={classes}
      viewBox="0 0 24 24"
      fill={isFilled ? 'currentColor' : 'none'}
      stroke={isFilled ? 'none' : 'currentColor'}
      strokeWidth={isFilled ? undefined : 2}
      strokeLinecap="round"
      strokeLinejoin="round"
      focusable="false"
      role={title ? 'img' : undefined}
      aria-label={title || undefined}
      aria-hidden={title ? undefined : true}
    >
      {title && <title>{title}</title>}
      {icon.paths}
    </svg>
  );
}
