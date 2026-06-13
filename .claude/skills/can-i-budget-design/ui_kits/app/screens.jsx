/* global React, Icon, Badge, Button, Card, MoneyCard, fmt */
const { useState, useMemo } = React;

function Dashboard({ state, onConnect }) {
  const { owed, available, needed, paydayDays, transactions, categories } = state;
  const buffer = available - needed;
  const bufferCopy = buffer >= 0
    ? `+${fmt(buffer).replace('−','')} above what you need`
    : `${fmt(Math.abs(buffer)).replace('−','')} below what you need`;

  return (
    <div className="dash">
      <div className="dash-head">
        <div>
          <div className="label">Can I spend?</div>
          <h1 style={{margin:'4px 0 0'}}>{buffer >= 0 ? 'Yes — you\'re clear.' : 'Careful — you\'re short.'}</h1>
          <div className="dash-sub">{bufferCopy} · {paydayDays} days until payday</div>
        </div>
        <Button variant="pop" icon="plus">Log spend</Button>
      </div>

      <div className="triad">
        <MoneyCard tone="owed" label="Owed" value={fmt(owed)} hint="Across 2 cards" icon="card" />
        <MoneyCard tone="available" label="Available" value={fmt(available)} hint={bufferCopy} icon="wallet" />
        <MoneyCard tone="needed" label="Needed" value={fmt(needed)} hint={`${paydayDays} days until payday`} icon="cal" />
      </div>

      <div className="grid-2">
        <Card>
          <div className="card-head">
            <h3>Budgets this cycle</h3>
            <a className="link">View all</a>
          </div>
          <div className="budgets">
            {categories.map(c => {
              const pct = Math.min(100, Math.round(c.spent / c.budget * 100));
              const over = c.spent > c.budget;
              return (
                <div key={c.name} className="budget">
                  <div className="budget-row">
                    <div className="budget-name"><Icon name={c.icon} size={14} /> {c.name}</div>
                    <div className="budget-amt tabular"><b>{fmt(c.spent)}</b> <span className="muted">/ {fmt(c.budget)}</span></div>
                  </div>
                  <div className="track"><div className={'fill ' + (over ? 'over' : '')} style={{width: pct + '%'}}/></div>
                </div>
              );
            })}
          </div>
        </Card>

        <Card>
          <div className="card-head">
            <h3>Recent activity</h3>
            <a className="link">See all</a>
          </div>
          <ul className="tx">
            {transactions.slice(0,5).map(t => (
              <li key={t.id} className="tx-row">
                <span className={'tx-ico tx-ico-' + (t.amount<0?'out':'in')}><Icon name={t.icon} size={14}/></span>
                <div className="tx-main"><div className="tx-name">{t.name}</div><div className="tx-meta">{t.date} · <span className="tx-cat">{t.cat}</span></div></div>
                <div className={'tx-amt tabular ' + (t.amount<0?'out':'in')}>{fmt(t.amount, {sign:true})}</div>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  );
}

function Transactions({ state, onAdd }) {
  const [filter, setFilter] = useState('all');
  const rows = state.transactions.filter(t =>
    filter === 'all' ? true : filter === 'out' ? t.amount < 0 : t.amount > 0);
  return (
    <div className="dash">
      <div className="dash-head">
        <div>
          <div className="label">Activity</div>
          <h1 style={{margin:'4px 0 0'}}>Transactions</h1>
          <div className="dash-sub">{rows.length} in this pay cycle</div>
        </div>
        <Button icon="plus" onClick={onAdd}>Log spend</Button>
      </div>
      <div className="chips">
        {[['all','All'],['out','Outgoing'],['in','Incoming']].map(([k,l]) => (
          <button key={k} className={'chip ' + (filter===k?'active':'')} onClick={() => setFilter(k)}>{l}</button>
        ))}
      </div>
      <Card padded={false}>
        <table className="tx-table">
          <thead><tr><th>Date</th><th>Description</th><th>Category</th><th style={{textAlign:'right'}}>Amount</th></tr></thead>
          <tbody>
            {rows.map(t => (
              <tr key={t.id}>
                <td className="muted">{t.date}</td>
                <td><span className="tx-name">{t.name}</span></td>
                <td><span className="cat-chip">{t.cat}</span></td>
                <td className={'tabular ' + (t.amount<0?'out':'in')} style={{textAlign:'right'}}>{fmt(t.amount,{sign:true})}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

function ConnectBank({ onConnect }) {
  return (
    <div className="onboard">
      <Card className="onboard-card">
        <img src="../../assets/logo.png" alt="" className="onboard-logo"/>
        <h1>Let's see where you're at.</h1>
        <p>Connect your bank and we'll show you — in three numbers — what you owe, what you have, and what you need between now and payday.</p>
        <ul className="onboard-list">
          <li><Icon name="check" size={16} /> Read-only via Basiq (CDR-accredited)</li>
          <li><Icon name="check" size={16} /> Works with all major AU banks</li>
          <li><Icon name="check" size={16} /> Disconnect any time</li>
        </ul>
        <Button variant="pop" icon="link" onClick={onConnect}>Connect Bank</Button>
        <a className="link" style={{marginLeft:12}}>I'll add accounts manually →</a>
      </Card>
    </div>
  );
}

function EmptyCategories() {
  return (
    <div className="dash">
      <div className="dash-head"><div><div className="label">Spending</div><h1 style={{margin:'4px 0 0'}}>Categories</h1></div></div>
      <Card className="empty">
        <Icon name="pie" size={44} />
        <h3>No categories yet</h3>
        <p>Spending trends will appear here once you have transactions.</p>
      </Card>
    </div>
  );
}

Object.assign(window, { Dashboard, Transactions, ConnectBank, EmptyCategories });
