/* global React */
// Shared data + formatter

const fmt = (n, { sign = false, cents = true } = {}) => {
  const abs = Math.abs(n);
  const body = cents ? abs.toFixed(2) : Math.round(abs).toString();
  const withCommas = body.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const s = n < 0 ? '−' : sign ? '+' : '';
  return `${s}$${withCommas}`;
};
const fmt0 = (n) => fmt(n, { cents: false });

const CATS = [
  { id: 'groc',  name: 'Groceries', icon: 'cart' },
  { id: 'coffee',name: 'Coffee',    icon: 'coffee' },
  { id: 'trans', name: 'Transport', icon: 'car' },
  { id: 'fuel',  name: 'Fuel',      icon: 'gas' },
  { id: 'rent',  name: 'Rent',      icon: 'home_heart' },
  { id: 'bills', name: 'Bills',     icon: 'utility' },
  { id: 'subs',  name: 'Subscriptions', icon: 'music' },
  { id: 'fun',   name: 'Fun',       icon: 'film' },
  { id: 'gym',   name: 'Health',    icon: 'dumbbell' },
  { id: 'shop',  name: 'Shopping',  icon: 'shopping_bag' },
  { id: 'inc',   name: 'Income',    icon: 'trend' },
  { id: 'xfr',   name: 'Transfer',  icon: 'refresh' },
];

// Transactions across the pay cycle (Apr 13 – Apr 27 payday)
// dateISO: YYYY-MM-DD (2026)
const TXNS = [
  // past
  { id: 101, date: '2026-04-13', name: 'Salary — ACME Pty Ltd', cat: 'inc',   type: 'inc',  amount: 2140.00, planned: false },
  { id: 102, date: '2026-04-13', name: 'Rent — 24 Wattle St',    cat: 'rent',  type: 'out',  amount: -620.00, planned: false, recurring: 'fortnightly' },
  { id: 103, date: '2026-04-14', name: 'Woolworths Metro',       cat: 'groc',  type: 'out',  amount: -28.10, planned: false },
  { id: 104, date: '2026-04-15', name: 'Pablo & Rusty\'s',       cat: 'coffee',type: 'out',  amount: -5.80,  planned: false },
  { id: 105, date: '2026-04-15', name: 'Spotify AU',             cat: 'subs',  type: 'out',  amount: -13.99, planned: false, recurring: 'monthly' },
  { id: 106, date: '2026-04-16', name: 'BP Service Station',     cat: 'fuel',  type: 'out',  amount: -64.12, planned: false },
  { id: 107, date: '2026-04-17', name: 'Coles Supermarkets',     cat: 'groc',  type: 'out',  amount: -82.40, planned: false },
  { id: 108, date: '2026-04-17', name: 'Transfer → Savings',     cat: 'xfr',   type: 'xfr',  amount: -200.00,planned: false },
  { id: 109, date: '2026-04-18', name: 'Toby\'s Estate',          cat: 'coffee',type: 'out',  amount: -5.20,  planned: false },
  { id: 110, date: '2026-04-18', name: 'Kmart Marrickville',     cat: 'shop',  type: 'out',  amount: -42.00, planned: false },

  // today
  { id: 201, date: '2026-04-19', name: 'Coles Supermarkets',     cat: 'groc',  type: 'out',  amount: -68.55, planned: false },

  // upcoming / planned
  { id: 301, date: '2026-04-20', name: 'Gym — Anytime Fitness',  cat: 'gym',   type: 'out',  amount: -22.00, planned: true, recurring: 'weekly' },
  { id: 302, date: '2026-04-21', name: 'AGL — Electricity',      cat: 'bills', type: 'out',  amount: -142.00,planned: true, recurring: 'quarterly' },
  { id: 303, date: '2026-04-22', name: 'Netflix',                cat: 'subs',  type: 'out',  amount: -22.99, planned: true, recurring: 'monthly' },
  { id: 304, date: '2026-04-24', name: 'Rent — 24 Wattle St',    cat: 'rent',  type: 'out',  amount: -620.00,planned: true, recurring: 'fortnightly' },
  { id: 305, date: '2026-04-25', name: 'Groceries (planned)',    cat: 'groc',  type: 'out',  amount: -110.00,planned: true, recurring: 'weekly' },
  { id: 306, date: '2026-04-27', name: 'Salary — ACME Pty Ltd',  cat: 'inc',   type: 'inc',  amount: 2140.00,planned: true, recurring: 'fortnightly' },
];

const NUMBERS = {
  owed: 3240.16,
  available: 3420.50,
  needed: 1980.00,
};

const BUDGETS = [
  { name: 'Groceries',     icon: 'cart',   spent: 289,  budget: 400 },
  { name: 'Transport',     icon: 'car',    spent: 168,  budget: 200 },
  { name: 'Coffee',        icon: 'coffee', spent: 94,   budget: 80 },
  { name: 'Subscriptions', icon: 'music',  spent: 62,   budget: 70 },
];

const TODAY_ISO = '2026-04-19';
const PAYDAY_ISO = '2026-04-27';

// build lookup
const CAT_BY_ID = Object.fromEntries(CATS.map(c => [c.id, c]));

// group transactions by date
function groupByDate(txns) {
  const map = {};
  txns.forEach(t => { (map[t.date] = map[t.date] || []).push(t); });
  return Object.entries(map).sort(([a], [b]) => a.localeCompare(b));
}

// week days centered on today
function weekStrip(todayISO) {
  const today = new Date(todayISO + 'T00:00');
  const out = [];
  for (let i = -2; i <= 5; i++) {
    const d = new Date(today);
    d.setDate(today.getDate() + i);
    out.push(d);
  }
  return out;
}

function dateISO(d) {
  const y = d.getFullYear(), m = (d.getMonth()+1).toString().padStart(2,'0'), dd = d.getDate().toString().padStart(2,'0');
  return `${y}-${m}-${dd}`;
}
function prettyDate(iso) {
  const d = new Date(iso + 'T00:00');
  return d.toLocaleDateString('en-AU', { weekday: 'long', day: 'numeric', month: 'long' });
}
function shortDate(iso) {
  const d = new Date(iso + 'T00:00');
  return d.toLocaleDateString('en-AU', { day: 'numeric', month: 'short' });
}

Object.assign(window, { fmt, fmt0, CATS, TXNS, NUMBERS, BUDGETS, TODAY_ISO, PAYDAY_ISO, CAT_BY_ID, groupByDate, weekStrip, dateISO, prettyDate, shortDate });
