/* global React */
const { useState, useMemo } = React;

// --- Icon helper (inline lucide-style SVG strings) ---
const PATHS = {
  wallet: '<path d="M3 7h15a3 3 0 013 3v8a3 3 0 01-3 3H5a2 2 0 01-2-2V7z"/><path d="M3 7a2 2 0 012-2h10"/><circle cx="17" cy="14" r="1.2" fill="currentColor"/>',
  home: '<path d="M3 11l9-7 9 7v9a2 2 0 01-2 2h-3v-6H8v6H5a2 2 0 01-2-2z"/>',
  card: '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M7 15h3"/>',
  pie: '<path d="M12 3v9h9a9 9 0 11-9-9z"/><path d="M21 12A9 9 0 0012 3"/>',
  cal: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4M16 3v4"/>',
  refresh: '<path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 01-4 0v-.1a1.7 1.7 0 00-1-1.5 1.7 1.7 0 00-1.9.3l-.1.1A2 2 0 113.3 17l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H2a2 2 0 010-4h.1a1.7 1.7 0 001.5-1 1.7 1.7 0 00-.3-1.8l-.1-.1A2 2 0 116 4.5l.1.1a1.7 1.7 0 001.8.3H8a1.7 1.7 0 001-1.5V3a2 2 0 014 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1h.1a2 2 0 010 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
  bell: '<path d="M6 8a6 6 0 0112 0c0 7 3 7 3 9H3c0-2 3-2 3-9z"/><path d="M10 21a2 2 0 004 0"/>',
  cart: '<circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/><path d="M3 4h2l2.7 12.6A2 2 0 009.7 18h7.3a2 2 0 002-1.6L21 7H6"/>',
  car: '<path d="M5 13l2-5a2 2 0 012-1h6a2 2 0 012 1l2 5"/><path d="M3 13h18v5H3z"/><circle cx="7" cy="18" r="1.3"/><circle cx="17" cy="18" r="1.3"/>',
  coffee: '<path d="M4 8h13v6a5 5 0 01-5 5H9a5 5 0 01-5-5z"/><path d="M17 10h2a3 3 0 010 6h-2"/><path d="M8 3v2M12 3v2"/>',
  music: '<path d="M9 18V5l10-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="16" cy="16" r="3"/>',
  tag: '<path d="M3 12V3h9l9 9-9 9z"/><circle cx="8" cy="8" r="1.4" fill="currentColor"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  check: '<path d="M5 12l4 4L19 6"/>',
  x: '<path d="M6 6l12 12M18 6L6 18"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
  link: '<path d="M10 14a4 4 0 005.6 0l3-3a4 4 0 00-5.6-5.6l-1 1"/><path d="M14 10a4 4 0 00-5.6 0l-3 3a4 4 0 005.6 5.6l1-1"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  trend: '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
  chevron: '<path d="M9 6l6 6-6 6"/>',
};
function Icon({ name, size = 18, color, className = '', ...rest }) {
  const d = PATHS[name] || PATHS.tag;
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none"
      stroke={color || 'currentColor'} strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"
      className={className} {...rest} dangerouslySetInnerHTML={{__html: d}} />
  );
}

// --- Money ---
function fmt(n, { sign = false } = {}) {
  const v = Math.abs(n).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const s = n < 0 ? '−' : sign ? '+' : '';
  return `${s}$${v}`;
}

// --- Button ---
function Button({ variant = 'primary', children, icon, onClick, className = '' }) {
  const base = 'btn btn-' + variant;
  return (
    <button className={base + ' ' + className} onClick={onClick}>
      {icon && <Icon name={icon} size={16} />}
      <span>{children}</span>
    </button>
  );
}

// --- Badge ---
function Badge({ tone = 'neutral', children, dot }) {
  return <span className={'badge badge-' + tone}>{dot && <i className="badge-dot" />}{children}</span>;
}

// --- Card ---
function Card({ children, padded = true, className = '' }) {
  return <div className={'card ' + (padded ? 'card-pad ' : '') + className}>{children}</div>;
}

// --- MoneyCard: the triad ---
function MoneyCard({ tone, label, value, hint, icon }) {
  return (
    <Card className={'money-card money-card-' + tone}>
      <div className="mc-head">
        <span className="label">{label}</span>
        <span className={'mc-badge mc-badge-' + tone}><Icon name={icon} size={14} /></span>
      </div>
      <div className={'money-hero money-' + tone}>{value}</div>
      <div className="mc-hint">{hint}</div>
    </Card>
  );
}

Object.assign(window, { Icon, fmt, Button, Badge, Card, MoneyCard });
