/* global React, Icon, Badge, Button, Card */
const { useState } = React;

function Sidebar({ current, onNav, collapsed, onToggle }) {
  const items = [
    { key: 'dashboard', label: 'Can I spend?', icon: 'wallet' },
    { key: 'transactions', label: 'Transactions', icon: 'card' },
    { key: 'categories', label: 'Categories', icon: 'pie' },
    { key: 'calendar', label: 'Calendar', icon: 'cal' },
    { key: 'trends', label: 'Trends', icon: 'trend' },
  ];
  const bottom = [
    { key: 'settings', label: 'Settings', icon: 'settings' },
  ];
  return (
    <aside className={'sb ' + (collapsed ? 'sb-collapsed' : '')}>
      <div className="sb-brand">
        <img src="../../assets/logo.png" alt="" className="sb-logo" />
        {!collapsed && <div className="sb-wm">Can I<br/>Budget</div>}
      </div>
      <nav className="sb-nav">
        {items.map(i => (
          <button key={i.key} className={'sb-item ' + (current === i.key ? 'is-active' : '')} onClick={() => onNav(i.key)}>
            <Icon name={i.icon} size={18} />
            {!collapsed && <span>{i.label}</span>}
          </button>
        ))}
      </nav>
      <div className="sb-foot">
        {bottom.map(i => (
          <button key={i.key} className={'sb-item ' + (current === i.key ? 'is-active' : '')} onClick={() => onNav(i.key)}>
            <Icon name={i.icon} size={18} />
            {!collapsed && <span>{i.label}</span>}
          </button>
        ))}
        {!collapsed && (
          <div className="sb-acct">
            <div className="sb-avatar">R</div>
            <div>
              <div className="sb-acct-name">Rob W.</div>
              <div className="sb-acct-sub">CBA · Everyday</div>
            </div>
          </div>
        )}
      </div>
    </aside>
  );
}

function Topbar({ onMenu, lastSynced, refreshesLeft, onRefresh, onToggleTheme, dark }) {
  return (
    <header className="tb">
      <button className="tb-menu" onClick={onMenu} aria-label="Menu"><Icon name="menu" /></button>
      <div className="tb-search">
        <Icon name="search" size={16} />
        <input placeholder="Search transactions, categories…" />
      </div>
      <div className="tb-right">
        <button className="tb-btn" onClick={onRefresh} title={`${refreshesLeft} of 20 refreshes left today`}>
          <Icon name="refresh" size={16} /><span>Refresh</span>
        </button>
        <span className="tb-synced">Last synced {lastSynced}</span>
        <button className="tb-icon" onClick={onToggleTheme} title="Toggle theme">
          <Icon name={dark ? 'eye' : 'eye'} size={16} />
        </button>
        <button className="tb-icon" aria-label="Notifications"><Icon name="bell" size={16} /></button>
      </div>
    </header>
  );
}

Object.assign(window, { Sidebar, Topbar });
