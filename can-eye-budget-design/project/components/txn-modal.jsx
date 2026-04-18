/* global React, Icon, fmt, CATS, CAT_BY_ID, TXNS */
const { useState } = React;

function TxnModal({ initial, onClose, onSave }) {
  const [tx, setTx] = useState(initial || {
    name: 'Coles Supermarkets',
    amount: 68.55,
    type: 'out',
    cat: 'groc',
    date: '2026-04-19',
    planned: false,
    frequency: 'one-off',
    endDate: '',
  });

  const update = (k, v) => setTx(s => ({...s, [k]: v}));

  return (
    <div className="modal-shade" onClick={onClose}>
      <div className="modal-sheet" onClick={e => e.stopPropagation()}>
        <div className="modal-grabber"/>
        <div className="modal-header">
          <h3>Edit transaction</h3>
          <button className="mclose" onClick={onClose} aria-label="Close"><Icon name="x" size={16}/></button>
        </div>
        <div className="modal-body">

          <div className="type-toggle">
            <button className={tx.type==='out' ? 'active out' : ''} onClick={() => update('type','out')}>Expense</button>
            <button className={tx.type==='inc' ? 'active inc' : ''} onClick={() => update('type','inc')}>Income</button>
            <button className={tx.type==='xfr' ? 'active xfr' : ''} onClick={() => update('type','xfr')}>Transfer</button>
          </div>

          <div className="field">
            <label>Description</label>
            <input value={tx.name} onChange={e => update('name', e.target.value)} />
          </div>

          <div className="field row2">
            <div>
              <label>Amount</label>
              <div className="amount-input">
                <span>$</span>
                <input type="text" value={Math.abs(tx.amount).toFixed(2)} onChange={e => update('amount', parseFloat(e.target.value)||0)} />
              </div>
            </div>
            <div>
              <label>Date</label>
              <input type="text" value="19 Apr 2026" onChange={()=>{}} />
            </div>
          </div>

          <div className="field">
            <label>Category</label>
          </div>
          <div className="category-grid">
            {CATS.filter(c => c.id !== 'xfr' && c.id !== 'inc').slice(0,8).map(c => (
              <div
                key={c.id}
                className={'cat-chip ' + (tx.cat === c.id ? 'active' : '')}
                onClick={() => update('cat', c.id)}
              >
                <span className="cat-color"><Icon name={c.icon} size={20}/></span>
                {c.name}
              </div>
            ))}
          </div>

          <div className="plan-toggle">
            <div className="left">
              <div className="t"><Icon name="repeat" size={13} style={{verticalAlign:-2, marginRight:5}}/>Make it a plan</div>
              <div className="s">Repeats it automatically so "Needed" stays accurate.</div>
            </div>
            <div className={'switch ' + (tx.planned ? 'on' : '')} onClick={() => update('planned', !tx.planned)}>
              <div className="knob"/>
            </div>
          </div>

          <div className={'plan-fields ' + (tx.planned ? 'open' : '')}>
            <div className="field row2">
              <div>
                <label>Frequency</label>
                <select value={tx.frequency} onChange={e => update('frequency', e.target.value)}>
                  <option>Weekly</option>
                  <option>Fortnightly</option>
                  <option>Monthly</option>
                  <option>Quarterly</option>
                  <option>Yearly</option>
                </select>
              </div>
              <div>
                <label>Ends</label>
                <select value={tx.endDate} onChange={e => update('endDate', e.target.value)}>
                  <option value="">Never</option>
                  <option>After 6 months</option>
                  <option>After 1 year</option>
                  <option>On specific date</option>
                </select>
              </div>
            </div>
          </div>

          <div className="rule-suggest">
            <Icon name="sparkles" size={18}/>
            <div>
              <div className="t">Make this a rule?</div>
              <div className="s">Auto-categorise any future "COLES" transaction as Groceries.</div>
              <div className="link">Set up rule →</div>
            </div>
          </div>

        </div>
        <div className="modal-foot">
          <button className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button className="btn btn-pop" onClick={() => onSave && onSave(tx)}><Icon name="check" size={16}/> Save</button>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { TxnModal });
