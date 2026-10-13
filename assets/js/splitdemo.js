// The no-account bill-splitting calculator behind pages/try.php: the same split rules the server uses
// (includes/domain.php compute_native_shares() / settlement_plan(), mirrored in pages/assign-items.php), as plain
// functions on a plain state object, plus a sample receipt and tab-only storage. Nothing here talks to the server.
// Exposes window.SplitDemo (and module.exports for tests). Money is integer centavos inside calc(), pesos outside.
(function (global) {
  const COLORS = ['#0d9488', '#f43f5e', '#f59e0b', '#818cf8', '#0ea5e9', '#a855f7', '#10b981', '#ec4899'];
  const KEY = 'splitdemo:v1'; // not "setlo:": api.js wipes those on signed-out pages
  const cents = (v) => Math.round((Number(v) || 0) * 100);
  const pesos = (c) => Math.round(c) / 100;

  /** Split `total` cents across ids as evenly as possible; leftover centavos go to the lowest ids. */
  function splitEvenly(total, ids) {
    const sorted = [...ids].sort((a, b) => a - b), n = sorted.length, out = {};
    if (!n) return out;
    const base = Math.trunc(total / n), rem = total - base * n;
    sorted.forEach((id, i) => { out[id] = base + (i < rem ? 1 : 0); });
    return out;
  }

  /** Split `total` cents in proportion to weights {id: cents}; leftovers go one by one to the lowest ids with weight. */
  function splitProportional(total, weights) {
    const ids = Object.keys(weights).map(Number).sort((a, b) => a - b);
    const sum = ids.reduce((s, id) => s + weights[id], 0), out = {};
    if (total <= 0 || sum <= 0) {
      if (total > 0) return splitEvenly(total, ids);
      ids.forEach((id) => { out[id] = 0; });
      return out;
    }
    let given = 0;
    ids.forEach((id) => { out[id] = Math.trunc((total * weights[id]) / sum); given += out[id]; });
    for (const id of ids) {
      if (given >= total) break;
      if (weights[id] > 0) { out[id]++; given++; }
    }
    return out;
  }

  /**
   * state: { people: [{id, name, discount: 'none'|'senior'|'pwd'}], items: [{name, qty, price, who: [ids]}],
   *          tax, taxIncluded, service, discount, payer }
   * → { shares: {id: cents}, itemShares, discountBy, extrasBy, subtotal, extras, discount, total, unassigned, unassignedCount }
   */
  function calc(state) {
    const ids = state.people.map((p) => p.id);
    const shares = {}, itemShares = {};
    ids.forEach((id) => { shares[id] = 0; itemShares[id] = 0; });
    let subtotal = 0, unassigned = 0, unassignedCount = 0;
    for (const it of state.items) {
      const line = Math.max(1, Math.trunc(Number(it.qty) || 1)) * cents(it.price);
      subtotal += line;
      const who = (it.who || []).filter((id) => ids.includes(id));
      if (!who.length) { unassigned += line; unassignedCount++; continue; }
      const parts = splitEvenly(line, who);
      who.forEach((id) => { shares[id] += parts[id]; itemShares[id] += parts[id]; });
    }
    const extras = (state.taxIncluded ? 0 : cents(state.tax)) + cents(state.service);
    const extrasBy = splitEvenly(extras, ids);
    ids.forEach((id) => { shares[id] += extrasBy[id] || 0; });

    // Discount: first to Senior/PWD people in proportion to what they ordered, the rest across everyone
    const discount = cents(state.discount);
    const flagged = {};
    state.people.filter((p) => p.discount && p.discount !== 'none').forEach((p) => { flagged[p.id] = itemShares[p.id]; });
    const toFlagged = Math.min(discount, Object.values(flagged).reduce((s, v) => s + v, 0));
    const discountBy = {};
    ids.forEach((id) => { discountBy[id] = 0; });
    const f = splitProportional(toFlagged, flagged);
    Object.keys(f).forEach((id) => { discountBy[id] += f[id]; });
    const remaining = {};
    ids.forEach((id) => { remaining[id] = itemShares[id] - discountBy[id]; });
    const rest = splitProportional(discount - toFlagged, remaining);
    Object.keys(rest).forEach((id) => { discountBy[id] += rest[id]; });
    ids.forEach((id) => { shares[id] -= discountBy[id]; });
    return { shares, itemShares, discountBy, extrasBy, subtotal, extras, discount, total: subtotal + extras - discount, unassigned, unassignedCount };
  }

  /** Who pays whom with as few transfers as possible: the biggest debtor pays the biggest creditor. [{from, to, amount}] in cents. */
  function plan(state, c) {
    const bal = {};
    state.people.forEach((p) => { bal[p.id] = (p.id === state.payer ? c.total : 0) - (c.shares[p.id] || 0); });
    const ids = state.people.map((p) => p.id).sort((a, b) => a - b), out = [];
    if (c.unassignedCount) return out; // the plan only makes sense once every item has someone
    for (let guard = 0; guard < 200; guard++) {
      let debtor = null, creditor = null;
      for (const id of ids) {
        if (bal[id] < 0 && (debtor === null || bal[id] < bal[debtor])) debtor = id;
        if (bal[id] > 0 && (creditor === null || bal[id] > bal[creditor])) creditor = id;
      }
      if (debtor === null || creditor === null) break;
      const amount = Math.min(-bal[debtor], bal[creditor]);
      out.push({ from: debtor, to: creditor, amount });
      bal[debtor] += amount;
      bal[creditor] -= amount;
    }
    return out;
  }

  const initials = (name) => (String(name).trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('') || '?').toUpperCase();

  /** A made-up restaurant bill for four, with a shared dish, a split pitcher, VAT-inclusive prices and a Senior discount. */
  function sample() {
    const names = ['Ana', 'Ben', 'Carla', 'Dan'];
    const people = names.map((name, i) => ({ id: i + 1, name, discount: name === 'Carla' ? 'senior' : 'none' }));
    const all = [1, 2, 3, 4];
    const items = [
      { name: 'Grilled Chicken Set', qty: 1, price: 189, who: [1] },
      { name: 'Crispy Pork Sisig', qty: 1, price: 260, who: all },
      { name: 'Garlic Rice', qty: 4, price: 35, who: all },
      { name: 'Sinigang na Baboy', qty: 1, price: 340, who: [2, 3] },
      { name: 'Halo-halo', qty: 2, price: 120, who: [3, 4] },
      { name: 'Iced Tea Pitcher', qty: 1, price: 150, who: all },
      { name: 'Leche Flan', qty: 1, price: 95, who: [4] },
    ];
    return { sample: true, nextId: 5, people, items, tax: 91, taxIncluded: true, service: 142, discount: 60, payer: 1 };
  }

  const blank = () => ({ sample: false, nextId: 3, people: [{ id: 1, name: 'You', discount: 'none' }, { id: 2, name: 'Friend', discount: 'none' }], items: [], tax: 0, taxIncluded: false, service: 0, discount: 0, payer: 1 });

  function load() {
    try {
      const s = JSON.parse(sessionStorage.getItem(KEY));
      if (s && Array.isArray(s.people) && Array.isArray(s.items)) return s;
    } catch (e) { /* storage blocked or not there yet */ }
    return null;
  }
  function save(state) { try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* storage blocked: it just won't survive a reload */ } }

  const api = { COLORS, cents, pesos, splitEvenly, splitProportional, calc, plan, sample, blank, load, save, initials };
  global.SplitDemo = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
