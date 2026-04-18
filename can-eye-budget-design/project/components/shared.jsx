/* global React, Icon, fmt, fmt0, TXNS, NUMBERS, BUDGETS, CATS, CAT_BY_ID, TODAY_ISO, PAYDAY_ISO, groupByDate, weekStrip, dateISO, prettyDate, shortDate */

const { useState, useMemo, useEffect } = React;

// ─────────────────────────────────────────────────────────
// Shared hero block (used in both mobile + desktop)
// ─────────────────────────────────────────────────────────
function VerdictHero({ variant, numbers, paydayDays, onLog }) {
  const { owed, available, needed } = numbers;
  const buffer = available - needed;
  const clear = buffer >= 0;

  if (variant === 'buffer') {
    return (
      <div className={'bufhero ' + (clear ? 'clear' : 'short')}>
        <div className="blabel">{clear ? 'You can afford' : 'You are short by'}</div>
        <div className="bnum">{fmt(Math.abs(buffer))}</div>
        <div className="bsub">
          {clear
            ? <>After covering <b>{fmt(needed)}</b> of planned spend until payday</>
            : <>Cut spending or delay a planned expense before <b>payday in {paydayDays} days</b></>}
        </div>
        <div style={{display:'flex', gap: 8, justifyContent:'center', marginTop: 14}}>
          <button className="btn btn-pop"><Icon name="plus" size={16}/> Log spend</button>
          <button className="btn btn-ghost"><Icon name="list" size={16}/> See breakdown</button>
        </div>
      </div>
    );
  }

  if (variant === 'numbers') {
    return (
      <div>
        <div className="verdict-chip">
          <Icon name={clear ? 'check' : 'bolt'} size={14}/>
          {clear ? 'Yes — you\'re clear' : 'Careful — you\'re short'}
        </div>
        <div className="numshero">
          <div className="ntile owed">
            <div className="nlabel">Owed</div>
            <div className="nmoney">{fmt(owed)}</div>
          </div>
          <div className="ntile available">
            <div className="nlabel">Available</div>
            <div className="nmoney">{fmt(available)}</div>
          </div>
          <div className="ntile needed">
            <div className="nlabel">Needed</div>
            <div className="nmoney">{fmt(needed)}</div>
          </div>
        </div>
      </div>
    );
  }

  // verdict (default)
  return (
    <div className={'verdict ' + (clear ? 'clear' : 'short')}>
      <div className="pricetag">{paydayDays === 0 ? 'PAYDAY!' : `${paydayDays}D TO PAYDAY`}</div>
      <span className="verdict-tag">
        <Icon name={clear ? 'check' : 'bolt'} size={12}/>
        Can I Budget?
      </span>
      <h2>{clear ? 'Yes — you\'re clear.' : 'Careful — you\'re short.'}</h2>
      <div className="verdict-sub">
        {clear ? <>You have <b>{fmt(Math.abs(buffer))}</b> above what you need between now and payday.</>
               : <>You're <b>{fmt(Math.abs(buffer))}</b> below what you need before payday. Something has to give.</>}
      </div>
    </div>
  );
}

function MoneyTriad({ numbers, paydayDays, wide }) {
  const { owed, available, needed } = numbers;
  return (
    <div className={wide ? 'triad' : 'm-triad'}>
      {!wide && (
        <div className="mcard available wide">
          <div className="mhead">
            <div className="mlabel">Available now</div>
            <div className="mbadge"><Icon name="wallet" size={16}/></div>
          </div>
          <div className="mmoney">{fmt(available)}</div>
          <div className="mhint">Across 3 accounts · last synced 3m ago</div>
        </div>
      )}
      {wide && (
        <div className="mcard available">
          <div className="mhead">
            <div className="mlabel">Available</div>
            <div className="mbadge"><Icon name="wallet" size={16}/></div>
          </div>
          <div className="mmoney">{fmt(available)}</div>
          <div className="mhint">3 accounts · synced 3m ago</div>
        </div>
      )}
      <div className="mcard needed">
        <div className="mhead">
          <div className="mlabel">Needed</div>
          <div className="mbadge"><Icon name="cal" size={16}/></div>
        </div>
        <div className="mmoney">{fmt(needed)}</div>
        <div className="mhint">{paydayDays} days of planned spend</div>
      </div>
      <div className="mcard owed">
        <div className="mhead">
          <div className="mlabel">Owed</div>
          <div className="mbadge"><Icon name="card" size={16}/></div>
        </div>
        <div className="mmoney">{fmt(owed)}</div>
        <div className="mhint">Card · $240 · Loan · $3,000</div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────
// Transaction row
// ─────────────────────────────────────────────────────────
function TxRow({ t, onClick }) {
  const cat = CAT_BY_ID[t.cat];
  const toneClass = t.type === 'inc' ? 'inc' : (t.planned ? 'plan' : 'out');
  return (
    <div className={'tx-row ' + (t.planned ? 'planned' : '')} onClick={onClick} style={{cursor: onClick ? 'pointer' : 'default'}}>
      <div className={'tx-ico ' + toneClass}><Icon name={cat?.icon || 'tag'} size={16}/></div>
      <div>
        <div className="tx-name">{t.name}</div>
        <div className="tx-meta">
          {t.planned && <span className="pill plan">Planned</span>}
          {t.recurring && <span className="pill">{t.recurring}</span>}
          <span>{cat?.name}</span>
        </div>
      </div>
      <div className={'tx-amt ' + toneClass}>
        {t.type === 'xfr' ? fmt(t.amount) : fmt(t.amount, {sign: true})}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────
// Calendar — week strip + agenda list (shared)
// ─────────────────────────────────────────────────────────
function CalendarView({ compact }) {
  const [selected, setSelected] = useState(TODAY_ISO);

  const days = weekStrip(TODAY_ISO);
  const byDate = useMemo(() => {
    const m = {};
    TXNS.forEach(t => { (m[t.date] = m[t.date] || []).push(t); });
    return m;
  }, []);

  const selectedTxns = byDate[selected] || [];
  const grouped = useMemo(() => groupByDate(TXNS), []);

  // for agenda list: show from selected date forward (5 groups)
  const agenda = grouped.filter(([iso]) => iso >= selected).slice(0, 6);

  return (
    <div>
      {!compact && (
        <div className="cal-head">
          <div>
            <div className="mo">April 2026</div>
            <div className="mo-sub">Pay cycle · 13 Apr → 27 Apr</div>
          </div>
          <div className="cal-nav">
            <button><Icon name="chev_left" size={16}/></button>
            <button><Icon name="chev_right" size={16}/></button>
          </div>
        </div>
      )}

      <div className="weekstrip">
        {days.map(d => {
          const iso = dateISO(d);
          const isToday = iso === TODAY_ISO;
          const isPayday = iso === PAYDAY_ISO;
          const active = iso === selected;
          const txns = byDate[iso] || [];
          const kinds = new Set(txns.map(t => t.type === 'inc' ? 'i' : (t.planned ? 'p' : 'o')));
          return (
            <div
              key={iso}
              className={[
                'day',
                isToday ? 'today' : '',
                active ? 'active' : '',
                isPayday && !active ? 'payday' : '',
              ].join(' ')}
              onClick={() => setSelected(iso)}
            >
              <div className="dow">{d.toLocaleDateString('en-AU',{weekday:'short'}).slice(0,3)}</div>
              <div className="dnum">{d.getDate()}</div>
              <div className="ddots">
                {[...kinds].map(k => <span key={k} className={k === 'o' ? 'o' : (k === 'i' ? 'i' : 'p')}/>)}
              </div>
            </div>
          );
        })}
      </div>

      {agenda.map(([iso, txns]) => {
        const net = txns.reduce((s,t) => s + (t.type === 'xfr' ? 0 : t.amount), 0);
        const d = new Date(iso + 'T00:00');
        const isToday = iso === TODAY_ISO;
        const isPayday = iso === PAYDAY_ISO;
        return (
          <div key={iso} className="agenda-group">
            <div className="grp-head">
              <div className="date">
                <span className="dnum">{d.getDate()}</span>
                {d.toLocaleDateString('en-AU', { weekday: 'short', month: 'short' })}
                {isToday && <span style={{marginLeft:8, color: 'var(--cib-teal-500)', fontWeight: 900}}>· Today</span>}
                {isPayday && <span style={{marginLeft:8, background:'var(--cib-yellow-400)', padding:'2px 8px', borderRadius:999, color:'#111', fontSize:11, border:'1.5px solid #111'}}>PAYDAY</span>}
              </div>
              <div className={'net ' + (net > 0 ? 'pos' : net < 0 ? 'neg' : '')}>
                {net === 0 ? '—' : (net > 0 ? '+' : '') + fmt(Math.abs(net)).replace('$','$')}
              </div>
            </div>
            <div className="day-card">
              {txns.map(t => <TxRow key={t.id} t={t} />)}
            </div>
          </div>
        );
      })}
    </div>
  );
}

Object.assign(window, { VerdictHero, MoneyTriad, TxRow, CalendarView });
