/* global React */
// Heroicons-style outline icon set (24x24, 1.5 stroke)

function Icon({ name, size = 20, stroke = 1.5, style = {}, className = '' }) {
  const paths = ICONS[name] || null;
  if (!paths) return null;
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={stroke}
      strokeLinecap="round"
      strokeLinejoin="round"
      style={style}
      className={className}
    >
      {paths}
    </svg>
  );
}

const ICONS = {
  home: (<><path d="M3 12l9-9 9 9"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/></>),
  calendar: (<><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></>),
  list: (<><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></>),
  pie: (<><path d="M21 12a9 9 0 1 1-9-9v9h9z"/></>),
  gear: (<><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3 1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8 1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/></>),
  plus: (<><path d="M12 5v14M5 12h14"/></>),
  cart: (<><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></>),
  car: (<><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11"/><path d="M5 11h14v6H5z"/><circle cx="8" cy="17" r="1.5"/><circle cx="16" cy="17" r="1.5"/></>),
  coffee: (<><path d="M3 8h14v7a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8z"/><path d="M17 11h2a3 3 0 0 1 0 6h-2"/><path d="M6 2v3M10 2v3M14 2v3"/></>),
  music: (<><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></>),
  trend: (<><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></>),
  card: (<><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></>),
  wallet: (<><path d="M3 6a2 2 0 0 1 2-2h14v4"/><path d="M3 6v12a2 2 0 0 0 2 2h16v-8H5a2 2 0 0 1-2-2z"/><circle cx="17" cy="14" r="1"/></>),
  cal: (<><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></>),
  bank: (<><path d="M3 10l9-6 9 6"/><path d="M5 10v9M19 10v9M9 10v9M15 10v9M3 21h18"/></>),
  check: (<path d="M5 12l4 4L19 7"/>),
  x: (<path d="M6 6l12 12M6 18L18 6"/>),
  chev_left: (<path d="M15 18l-6-6 6-6"/>),
  chev_right: (<path d="M9 18l6-6-6-6"/>),
  chev_down: (<path d="M6 9l6 6 6-6"/>),
  arrow_right: (<path d="M5 12h14M13 5l7 7-7 7"/>),
  bell: (<><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a2 2 0 0 0 3.4 0"/></>),
  refresh: (<><path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v5h-5"/></>),
  filter: (<path d="M3 5h18l-7 9v6l-4-2v-4z"/>),
  search: (<><circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/></>),
  dots: (<><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></>),
  bolt: (<path d="M13 2L3 14h7v8l10-12h-7z"/>),
  tag: (<><path d="M20 13l-7 7a2 2 0 0 1-3 0L3 13a1 1 0 0 1 0-1.4V3h8.6a1 1 0 0 1 .7.3l7.7 7.7a2 2 0 0 1 0 3z"/><circle cx="8" cy="8" r="1.5"/></>),
  repeat: (<><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></>),
  sparkles: (<><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M18 6l-2.5 2.5M8.5 15.5L6 18"/></>),
  home_heart: (<><path d="M3 12l9-9 9 9v9h-6v-6h-6v6H3v-9z"/></>),
  gas: (<><path d="M3 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16M3 21h11"/><path d="M14 8h3a2 2 0 0 1 2 2v6a1 1 0 0 0 2 0V9l-3-3"/></>),
  utility: (<><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></>),
  film: (<><rect x="2" y="3" width="20" height="18" rx="2"/><path d="M2 8h20M2 16h20M7 3v18M17 3v18"/></>),
  dumbbell: (<><path d="M6 5v14M18 5v14M3 8v8M21 8v8M6 12h12"/></>),
  shopping_bag: (<><path d="M5 8h14l-1 13H6z"/><path d="M9 8V5a3 3 0 0 1 6 0v3"/></>),
};

Object.assign(window, { Icon });
