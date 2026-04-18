/* global React, Icon, fmt, VerdictHero, MoneyTriad, TxRow, CalendarView, TxnModal, TXNS, NUMBERS, BUDGETS, TODAY_ISO, PAYDAY_ISO */

const { useState } = React;

function MobileShell({ variant, paydayDays, route, setRoute, onOpenTx }) {
  const numbers = NUMBERS;
  return (
    <div className="m-app">
      <div className="m-header">
        <div className="brand-tiny">
          <img src={window.__resources.logo} alt=""/>
          <div>
            <div className="t">Can I Budget</div>
            <div className="s">Gday, Rob</div>
          </div>
        </div>
        <div className="m-avatar">R</div>
      </div>

      <div className="m-body">
        {route === 'home' && (
          <>
            <div className="m-greeting">
              <div className="g-label">Pay cycle · {paydayDays} days to payday</div>
            </div>
            <VerdictHero variant={variant} numbers={numbers} paydayDays={paydayDays} />
            <MoneyTriad numbers={numbers} paydayDays={paydayDays} wide={false} />

            <div className="sec">
              <div className="sec-head">
                <h3>Recent activity</h3>
                <span className="link" onClick={() => setRoute('calendar')}>See all →</span>
              </div>
              {TXNS.filter(t => t.date <= TODAY_ISO).slice().reverse().slice(0,4).map(t => (
                <TxRow key={t.id} t={t} onClick={() => onOpenTx(t)}/>
              ))}
            </div>
          </>
        )}

        {route === 'calendar' && (
          <>
            <div className="m-greeting">
              <div className="g-label">Calendar</div>
              <div className="g-title">Everything planned and posted between now and <b>payday</b>.</div>
            </div>
            <CalendarView compact />
          </>
        )}
      </div>

      <div className="m-tabbar">
        <div className={'tab ' + (route==='home'?'active':'')} onClick={() => setRoute('home')}>
          <Icon name="home" size={22}/>
          <div className="tl">Home</div>
        </div>
        <div className={'tab ' + (route==='calendar'?'active':'')} onClick={() => setRoute('calendar')}>
          <Icon name="calendar" size={22}/>
          <div className="tl">Calendar</div>
        </div>
        <div className="tab fab" onClick={() => onOpenTx(null)}>
          <Icon name="plus" size={22}/>
          <div className="tl">Add</div>
        </div>
        <div className="tab">
          <Icon name="pie" size={22}/>
          <div className="tl">Spend</div>
        </div>
        <div className="tab">
          <Icon name="gear" size={22}/>
          <div className="tl">More</div>
        </div>
      </div>
    </div>
  );
}

function DesktopShell({ variant, paydayDays, route, setRoute, onOpenTx }) {
  const numbers = NUMBERS;
  return (
    <div className="app">
      <aside className="sidebar">
        <div className="brand">
          <img src={window.__resources.logo} alt=""/>
          <div>
            <div className="name">Can I Budget</div>
            <div className="sub">AU · Personal</div>
          </div>
        </div>
        <div className={'nav-item ' + (route==='home'?'active':'')} onClick={() => setRoute('home')}><Icon name="home" size={16}/> Dashboard</div>
        <div className={'nav-item ' + (route==='calendar'?'active':'')} onClick={() => setRoute('calendar')}><Icon name="calendar" size={16}/> Calendar</div>
        <div className="nav-item"><Icon name="list" size={16}/> Transactions</div>
        <div className="nav-item"><Icon name="pie" size={16}/> Spending</div>
        <div className="nav-item"><Icon name="repeat" size={16}/> Rules</div>
        <div className="nav-item"><Icon name="bank" size={16}/> Accounts</div>
        <div className="sidebar-foot">
          <div className="av">RW</div>
          <div>
            Rob Wilde
            <div className="meta">3 accounts · synced 3m ago</div>
          </div>
        </div>
      </aside>

      <main className="app-main">
        <div className="app-topbar">
          <div>
            <div className="crumbs">{route === 'home' ? 'Dashboard' : 'Calendar'}</div>
            <div className="title">
              {route === 'home' ? 'Can I Budget?' : 'April 2026'}
            </div>
          </div>
          <div className="topbar-actions">
            <span className="payday-ring">
              <span className="pr">{paydayDays}d</span>
              {paydayDays === 0 ? 'It\'s payday' : 'until next payday'}
            </span>
            <span className="sync-chip"><span className="dot"/>Synced 3m ago · Basiq</span>
            <button className="btn btn-ghost"><Icon name="refresh" size={14}/>Refresh</button>
            <button className="btn btn-pop" onClick={() => onOpenTx(null)}><Icon name="plus" size={14}/>Log spend</button>
          </div>
        </div>

        {route === 'home' && (
          <>
            <div className="dash-grid">
              <div>
                <VerdictHero variant={variant} numbers={numbers} paydayDays={paydayDays} />
                <MoneyTriad numbers={numbers} paydayDays={paydayDays} wide />

                <div className="sec">
                  <div className="sec-head">
                    <h3>Recent activity</h3>
                    <span className="link" onClick={() => setRoute('calendar')}>Open calendar →</span>
                  </div>
                  {TXNS.filter(t => t.date <= TODAY_ISO).slice().reverse().slice(0,5).map(t => (
                    <TxRow key={t.id} t={t} onClick={() => onOpenTx(t)}/>
                  ))}
                </div>
              </div>

              <div className="side">
                <div className="minicard">
                  <h4>Budgets this cycle</h4>
                  {BUDGETS.map(b => {
                    const pct = Math.min(100, Math.round(b.spent/b.budget*100));
                    const over = b.spent > b.budget;
                    return (
                      <div key={b.name} style={{marginBottom: 10}}>
                        <div className="budget-row">
                          <span><Icon name={b.icon} size={13} style={{verticalAlign:-2, marginRight:4}}/>{b.name}</span>
                          <span className="amt"><b>${b.spent}</b> / ${b.budget}</span>
                        </div>
                        <div className="track"><div className={'fill ' + (over?'over':'')} style={{width:pct+'%'}}/></div>
                      </div>
                    );
                  })}
                </div>

                <div className="minicard">
                  <h4>Next 3 planned</h4>
                  {TXNS.filter(t => t.planned && t.type !== 'inc').slice(0,3).map(t => (
                    <div key={t.id} style={{display:'flex', justifyContent:'space-between', padding:'7px 0', borderBottom:'1px solid var(--border-1)', fontSize:13, fontWeight:700}}>
                      <span>{shortDate(t.date)} · {t.name.length > 18 ? t.name.slice(0,18) + '…' : t.name}</span>
                      <span style={{color:'var(--money-planned)', fontFamily:'var(--font-display)'}}>{fmt(t.amount,{sign:true})}</span>
                    </div>
                  ))}
                </div>

                <div className="minicard" style={{background:'var(--cib-teal-400)', color:'#fff', border:'2px solid #111', boxShadow:'3px 3px 0 0 #111'}}>
                  <h4 style={{color:'var(--cib-yellow-400)'}}>Spend last 7 days</h4>
                  <div style={{font:'900 28px/1 var(--font-display)'}}>$346.41</div>
                  <div style={{fontSize:11, opacity:0.8, marginTop:4, fontWeight:700}}>−12% vs previous week</div>
                  <div className="spark">
                    {[12,28,18,42,22,34,14,38,24,30,18,26,22,46].map((h,i) => (
                      <div key={i} className={'bar ' + (i>10?'big':'')} style={{height: h+'%'}}/>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          </>
        )}

        {route === 'calendar' && (
          <>
            <div className="quickline">
              <div className="ql-item"><span className="sw" style={{background:'var(--cib-green-500)'}}/>Income <b>$2,140</b></div>
              <div className="ql-item"><span className="sw" style={{background:'var(--money-owed)'}}/>Posted <b>−$1,182</b></div>
              <div className="ql-item"><span className="sw" style={{background:'var(--money-planned)'}}/>Planned <b>−$917</b></div>
              <div className="ql-item">Buffer at payday <b style={{color:'var(--cib-green-600)'}}>+$41</b></div>
            </div>
            <div style={{marginTop: 18}}>
              <CalendarView />
            </div>
          </>
        )}
      </main>
    </div>
  );
}

Object.assign(window, { MobileShell, DesktopShell });
