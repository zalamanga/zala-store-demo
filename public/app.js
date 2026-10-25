const state = {
  products: [], cart: new Map(), orders: [], user: null, csrf: null, summary: null,
  invoiceId: null, invoice: null, invoiceBusy: false, invoiceVersion: 0,
  loading: false, authBusy: false, checkoutBusy: false, register: false,
  attempt: null, loadVersion: 0, startupNotice: true,
};
const ids = ["productGrid", "searchInput", "categoryFilter", "sortFilter", "cartItems", "cartCount",
  "subtotal", "shipping", "tax", "grandTotal", "checkoutButton", "checkoutMessage", "orderList",
  "clearCart", "invoiceTitle", "invoiceBody", "adminPanel", "adminBody", "themeToggle", "refreshButton",
  "catalogMessage", "accountHeading", "accountDescription", "authForm", "username", "password",
  "authSubmit", "authMode", "accountMessage", "logoutButton", "cartHeading"];
const el = Object.fromEntries(ids.map((id) => [id, document.getElementById(id)]));
const money = new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 });
const categories = new Set(["fashion", "tech", "beauty", "home"]);

class ApiFailure extends Error {
  constructor(message, status = null) { super(message); this.status = status; }
}
function node(tag, className = "", text = null) {
  const result = document.createElement(tag);
  if (className) result.className = className;
  if (text !== null) result.textContent = text;
  return result;
}
function action(text, kind, id, className = "ghost-button") {
  const result = node("button", className, text);
  result.type = "button";
  result.dataset.action = kind;
  result.dataset.id = id;
  return result;
}
function status(target, text, error = false) {
  target.textContent = text;
  target.classList.toggle("error", error);
}
function integer(value, max) { return Number.isSafeInteger(value) && value >= 0 && value <= max; }
function products(value) {
  if (!Array.isArray(value) || value.length !== 8 || new Set(value.map((item) => item?.id)).size !== 8
      || value.some((item) => !item || !/^prd-\d{3}$/.test(item.id) || typeof item.name !== "string"
        || !categories.has(item.category) || !integer(item.price, 10_000_000) || !integer(item.stock, 100_000)
        || typeof item.mark !== "string" || !integer(item.featured, 100))) {
    throw new ApiFailure("Data katalog belum dapat dibaca. Coba muat ulang.");
  }
  return value;
}
function order(value) {
  if (!value || !/^INV-[A-F0-9]{24}$/.test(value.id) || value.status !== "simulation_complete"
      || !Array.isArray(value.items) || value.items.length < 1 || value.items.length > 8
      || ![value.subtotal, value.shipping, value.tax, value.total].every((number) => integer(number, 10_000_000_000))
      || value.items.some((item) => !item || typeof item.name !== "string" || !/^prd-\d{3}$/.test(item.id)
        || !integer(item.qty, 99) || item.qty < 1 || !integer(item.price, 10_000_000))) {
    throw new ApiFailure("Rincian pesanan belum dapat dibaca. Periksa riwayat sebelum membuat pesanan lain.");
  }
  return value;
}
function session(value) {
  if (!value || !/^[a-f0-9]{64}$/.test(value.csrf) || (value.user !== null &&
      (!/^[a-f0-9]{32}$/.test(value.user?.id) || typeof value.user.username !== "string"
       || !["customer", "admin"].includes(value.user.role)))) {
    throw new ApiFailure("Sesi belum dapat dibaca. Coba muat ulang.");
  }
  return value;
}
async function request(path, { method = "GET", payload, key } = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15_000);
  try {
    const headers = { Accept: "application/json" };
    if (method === "POST") {
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = state.csrf || "";
    }
    if (key) headers["Idempotency-Key"] = key;
    const response = await fetch(path, { method, headers, credentials: "same-origin", cache: "no-store",
      signal: controller.signal, ...(payload !== undefined ? { body: JSON.stringify(payload) } : {}) });
    let body;
    try { body = await response.json(); }
    catch { throw new ApiFailure("Respons belum dapat dibaca. Coba muat ulang.", response.ok ? null : response.status); }
    if (!response.ok) throw new ApiFailure(typeof body.message === "string" ? body.message : "Permintaan gagal.", response.status);
    return body.data;
  } catch (error) {
    if (error instanceof ApiFailure) throw error;
    throw new ApiFailure("Koneksi terputus atau terlalu lama. Coba lagi setelah koneksi pulih.");
  } finally { clearTimeout(timeout); }
}
function cartLocked() { return state.checkoutBusy || state.authBusy || state.loading || Boolean(state.attempt); }
function total() {
  const subtotal = [...state.cart].reduce((sum, [id, qty]) => {
    const product = state.products.find((item) => item.id === id);
    return sum + (product ? product.price * qty : 0);
  }, 0);
  const shipping = subtotal > 0 ? 19000 : 0;
  const tax = Math.floor((subtotal * 11 + 50) / 100);
  return { subtotal, shipping, tax, total: subtotal + shipping + tax };
}
function visibleProducts() {
  const query = el.searchInput.value.trim().toLowerCase();
  return state.products.filter((item) => (!query || `${item.name} ${item.category}`.toLowerCase().includes(query))
    && (el.categoryFilter.value === "all" || item.category === el.categoryFilter.value))
    .sort((a, b) => {
      if (el.sortFilter.value === "priceAsc") return a.price - b.price;
      if (el.sortFilter.value === "priceDesc") return b.price - a.price;
      if (el.sortFilter.value === "stock") return b.stock - a.stock;
      return a.featured - b.featured;
    });
}
function renderProducts() {
  const list = visibleProducts();
  el.productGrid.setAttribute("aria-busy", String(state.loading));
  const cards = list.map((product) => {
    const remaining = Math.max(0, product.stock - (state.cart.get(product.id) || 0));
    const card = node("article", "product-card");
    const media = node("div", `product-media tone-${product.category}`, product.mark);
    media.setAttribute("aria-hidden", "true");
    const content = node("div", "product-content");
    const top = node("div", "product-top");
    top.append(node("h3", "product-title", product.name), node("span", "price", money.format(product.price)));
    const bottom = node("div", "product-top");
    const add = action("Tambah", "add", product.id, "primary-button");
    add.setAttribute("aria-label", `Tambah ${product.name} ke keranjang`);
    add.disabled = cartLocked() || remaining <= 0;
    bottom.append(node("span", `stock-chip${remaining <= 3 ? " low" : ""}`, `Tersedia ${remaining}`), add);
    content.append(top, node("p", "product-meta", `${product.category} · ${product.id}`), bottom);
    card.append(media, content);
    return card;
  });
  el.productGrid.replaceChildren(...(cards.length ? cards : [node("p", "empty", state.loading ? "Memuat produk..." : "Produk tidak ditemukan.")]));
}
function renderCart() {
  const entries = [...state.cart];
  el.cartCount.textContent = String(entries.reduce((sum, [, qty]) => sum + qty, 0));
  const amount = total();
  for (const key of ["subtotal", "shipping", "tax"]) el[key].textContent = money.format(amount[key]);
  el.grandTotal.textContent = money.format(amount.total);
  const lines = entries.map(([id, qty]) => {
    const product = state.products.find((item) => item.id === id);
    if (!product) return node("p", "empty", "Produk perlu dimuat ulang.");
    const line = node("div", "cart-line");
    const copy = node("div", "cart-copy");
    copy.append(node("strong", "", product.name), node("span", "", money.format(product.price)));
    const controls = node("div", "qty-controls");
    const decrement = action("−", "decrement", id, "qty-button");
    decrement.setAttribute("aria-label", `Kurangi ${product.name}`);
    decrement.disabled = cartLocked();
    const increment = action("+", "increment", id, "qty-button");
    increment.setAttribute("aria-label", `Tambah jumlah ${product.name}`);
    increment.disabled = cartLocked() || qty >= Math.min(product.stock, 99);
    controls.append(decrement, node("strong", "", String(qty)), increment);
    line.append(copy, controls);
    return line;
  });
  el.cartItems.replaceChildren(...(lines.length ? lines : [node("p", "empty", "Keranjang masih kosong.")]));
  el.clearCart.disabled = entries.length === 0 || cartLocked();
  el.checkoutButton.disabled = entries.length === 0 || state.loading || state.checkoutBusy || state.authBusy || !state.csrf;
  el.checkoutButton.textContent = state.checkoutBusy ? "Memeriksa pesanan..." : state.attempt ? "Periksa pesanan ini" : "Buat pesanan simulasi";
}
function renderOrders() {
  if (!state.user) {
    el.orderList.replaceChildren(node("p", "empty", "Masuk untuk membuka riwayat pesanan Anda."));
    return;
  }
  const rows = state.orders.map((item) => {
    const row = node("div", "order-row");
    const copy = node("div");
    copy.append(node("strong", "order-id", item.id), node("p", "muted", `${item.items.length} jenis produk · Simulasi selesai`));
    row.append(copy, action(`Rincian ${money.format(item.total)}`, "invoice", item.id, "invoice-button"));
    return row;
  });
  el.orderList.replaceChildren(...(rows.length ? rows : [node("p", "empty", "Belum ada pesanan simulasi.")]));
}
function renderAuth() {
  el.authForm.hidden = Boolean(state.user);
  el.logoutButton.hidden = !state.user;
  el.logoutButton.disabled = state.checkoutBusy || state.authBusy || state.loading || Boolean(state.attempt);
  el.authSubmit.disabled = state.authBusy || state.loading || state.checkoutBusy || !state.csrf;
  el.authMode.disabled = state.authBusy || state.loading || state.checkoutBusy || !state.csrf;
  el.username.disabled = state.authBusy || state.loading || !state.csrf;
  el.password.disabled = state.authBusy || state.loading || !state.csrf;
  el.accountHeading.textContent = state.user ? `Masuk sebagai ${state.user.username}` : state.register ? "Buat akun pelanggan" : "Masuk untuk menyimpan pesanan";
  el.accountDescription.textContent = state.user ? "Pesanan tersimpan untuk akun ini. Keluar akan mengosongkan keranjang di halaman." : state.register ? "Nama akun 3 sampai 32 karakter. Password minimal 12 karakter, maksimal 72 byte." : "Katalog dan keranjang dapat dicoba sebelum masuk.";
  el.authSubmit.textContent = state.authBusy ? "Memproses..." : state.register ? "Buat akun" : "Masuk";
  el.authMode.textContent = state.register ? "Sudah punya akun" : "Buat akun baru";
  el.password.autocomplete = state.register ? "new-password" : "current-password";
  el.password.minLength = state.register ? 12 : 1;
}
function renderAdmin() {
  el.adminPanel.hidden = state.user?.role !== "admin";
  if (el.adminPanel.hidden) { el.adminBody.replaceChildren(); return; }
  if (!state.summary) { el.adminBody.replaceChildren(node("p", "empty", "Ringkasan belum termuat. Gunakan Muat ulang.")); return; }
  const labels = { total_sku: "Jenis produk", available_stock: "Stok tersisa", total_orders: "Pesanan simulasi" };
  el.adminBody.replaceChildren(...Object.entries(labels).map(([key, label]) => {
    const row = node("div", "admin-row");
    row.append(node("span", "", label), node("strong", "", String(state.summary[key])));
    return row;
  }));
}
function renderInvoice(message = "Pilih pesanan untuk melihat rinciannya.") {
  el.invoiceTitle.textContent = "Rincian pesanan";
  if (!state.invoice) { el.invoiceBody.replaceChildren(node("p", "empty", state.invoiceBusy ? "Memuat rincian..." : message)); return; }
  const item = state.invoice;
  const list = node("ul", "invoice-items");
  for (const line of item.items) {
    const row = node("li");
    row.append(node("strong", "", line.name), node("div", "muted", `${line.qty} × ${money.format(line.price)} = ${money.format(line.qty * line.price)}`));
    list.append(row);
  }
  el.invoiceBody.replaceChildren(node("p", "order-id", item.id), list,
    node("p", "muted", `Ongkir contoh ${money.format(item.shipping)} · Biaya simulasi ${money.format(item.tax)}`),
    node("strong", "invoice-total", `Total simulasi ${money.format(item.total)}`),
    node("p", "muted", "Simulasi selesai. Tidak ada pembayaran yang diproses."));
}
function renderAll() {
  const focused = document.activeElement;
  const savedFocus = focused?.dataset.action ? { kind: focused.dataset.action, id: focused.dataset.id } : null;
  renderProducts(); renderCart(); renderOrders(); renderAuth(); renderAdmin();
  el.refreshButton.disabled = state.loading || state.checkoutBusy || state.authBusy;
  if (savedFocus && !document.contains(focused)) {
    const replacement = [...document.querySelectorAll("button[data-action]")].find((button) =>
      !button.disabled && button.dataset.action === savedFocus.kind && button.dataset.id === savedFocus.id);
    if (replacement) replacement.focus();
    else { el.cartHeading.tabIndex = -1; el.cartHeading.focus(); }
  }
}
function clearPrivate() {
  state.orders = []; state.summary = null; state.invoiceId = null; state.invoice = null;
  state.invoiceVersion++; state.invoiceBusy = false; state.cart.clear(); state.attempt = null;
  renderInvoice();
}
async function refresh() {
  const version = ++state.loadVersion;
  state.loading = true;
  status(el.catalogMessage, "Memuat katalog dan pesanan...");
  renderAll();
  try {
    const profile = session(await request("/api/session"));
    if (version !== state.loadVersion) return;
    if (state.user && state.user.id !== profile.user?.id) clearPrivate();
    state.user = profile.user; state.csrf = profile.csrf;
    if (state.startupNotice) { status(el.accountMessage, ""); state.startupNotice = false; }
    const catalog = products(await request("/api/products"));
    const receipts = state.user ? await request("/api/orders") : [];
    if (!Array.isArray(receipts) || receipts.length > 1000) throw new ApiFailure("Daftar pesanan tidak valid.");
    const checked = receipts.map(order);
    const summary = state.user?.role === "admin" ? await request("/api/admin/summary") : null;
    if (version !== state.loadVersion) return;
    state.products = catalog; state.orders = checked; state.summary = summary;
    if (!state.attempt) {
      let changed = false;
      for (const [id, qty] of state.cart) {
        const available = catalog.find((item) => item.id === id)?.stock || 0;
        if (available === 0) { state.cart.delete(id); changed = true; }
        else if (qty > available) { state.cart.set(id, available); changed = true; }
      }
      if (changed) status(el.checkoutMessage, "Stok berubah. Jumlah di keranjang sudah disesuaikan.");
    }
    status(el.catalogMessage, `${catalog.length} produk tersedia di katalog.`);
  } catch (error) {
    if (version === state.loadVersion) status(el.catalogMessage, `${error.message} Gunakan Muat ulang.`, true);
    throw error;
  } finally {
    if (version === state.loadVersion) { state.loading = false; renderAll(); }
  }
}
async function invoice(id) {
  const version = ++state.invoiceVersion;
  state.invoiceId = id; state.invoice = null; state.invoiceBusy = true; renderInvoice();
  try {
    const result = order(await request(`/api/invoices/${encodeURIComponent(id)}`));
    if (version !== state.invoiceVersion) return;
    state.invoice = result;
  } catch (error) {
    if (version === state.invoiceVersion) { state.invoiceBusy = false; renderInvoice(error.message); }
    return;
  } finally {
    if (version === state.invoiceVersion) state.invoiceBusy = false;
  }
  renderInvoice();
}
function changeCart(id, delta) {
  if (cartLocked()) return;
  const product = state.products.find((item) => item.id === id);
  const next = (state.cart.get(id) || 0) + delta;
  if (!product || next > Math.min(product.stock, 99)) { status(el.checkoutMessage, "Stok tidak mencukupi.", true); return; }
  if (next <= 0) state.cart.delete(id); else state.cart.set(id, next);
  status(el.checkoutMessage, ""); renderAll();
}
async function checkout() {
  if (state.checkoutBusy || state.loading || state.authBusy || state.cart.size === 0) return;
  if (!state.user) {
    status(el.checkoutMessage, "Masuk terlebih dahulu untuk menyimpan pesanan.");
    el.username.focus(); return;
  }
  if (!state.attempt) {
    if (!globalThis.crypto?.getRandomValues) { status(el.checkoutMessage, "Browser belum mendukung checkout demo ini.", true); return; }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    state.attempt = { key: [...bytes].map((value) => value.toString(16).padStart(2, "0")).join(""),
      items: [...state.cart].map(([id, qty]) => ({ id, qty })) };
  }
  state.checkoutBusy = true; status(el.checkoutMessage, "Memeriksa pesanan simulasi..."); renderAll();
  try {
    const receipt = order(await request("/api/checkout", { method: "POST", key: state.attempt.key,
      payload: { items: state.attempt.items } }));
    state.attempt = null; state.cart.clear();
    state.orders = [receipt, ...state.orders.filter((item) => item.id !== receipt.id)];
    state.invoiceVersion++; state.invoiceBusy = false; state.invoiceId = receipt.id; state.invoice = receipt;
    renderInvoice(); status(el.checkoutMessage, "Pesanan simulasi tersimpan. Tidak ada uang yang ditagih.");
    try { await refresh(); }
    catch { status(el.checkoutMessage, "Pesanan sudah tersimpan. Katalog belum diperbarui; gunakan Muat ulang."); }
  } catch (error) {
    if (error.status !== null && error.status >= 400 && error.status < 500) {
      state.attempt = null;
      if ([401, 403].includes(error.status)) {
        try {
          const profile = session(await request("/api/session"));
          if (state.user?.id !== profile.user?.id) clearPrivate();
          state.user = profile.user; state.csrf = profile.csrf;
        } catch { state.csrf = null; }
      }
      status(el.checkoutMessage, error.message, true);
    } else {
      status(el.checkoutMessage, "Hasil belum terkonfirmasi. Pilih Periksa pesanan ini sebelum mengubah keranjang.", true);
    }
  } finally { state.checkoutBusy = false; renderAll(); }
}
async function authenticate(event) {
  event.preventDefault();
  if (state.authBusy || state.loading || state.checkoutBusy || !state.csrf) return;
  state.authBusy = true; status(el.accountMessage, "Memproses akun..."); renderAll();
  try {
    const profile = session(await request(state.register ? "/api/register" : "/api/login", {
      method: "POST", payload: { username: el.username.value, password: el.password.value },
    }));
    state.user = profile.user; state.csrf = profile.csrf;
    status(el.accountMessage, "Berhasil masuk. Keranjang dapat dilanjutkan.");
    try { await refresh(); } catch { status(el.accountMessage, "Berhasil masuk. Daftar belum diperbarui; gunakan Muat ulang."); }
  } catch (error) {
    status(el.accountMessage, error.message, true);
  } finally {
    el.password.value = ""; state.authBusy = false; renderAll();
    if (!state.user) el.password.focus();
  }
}
el.authForm.addEventListener("submit", authenticate);
el.authMode.addEventListener("click", () => { state.register = !state.register; el.password.value = ""; status(el.accountMessage, ""); renderAuth(); el.username.focus(); });
el.logoutButton.addEventListener("click", async () => {
  if (state.authBusy || cartLocked()) return;
  state.authBusy = true; renderAll();
  try {
    const profile = session(await request("/api/logout", { method: "POST", payload: {} }));
    state.user = null; state.csrf = profile.csrf; clearPrivate(); status(el.accountMessage, "Anda sudah keluar.");
  } catch (error) { status(el.accountMessage, `${error.message} Muat ulang untuk memeriksa sesi.`, true); }
  finally { state.authBusy = false; renderAll(); }
});
document.addEventListener("click", (event) => {
  const target = event.target.closest("button[data-action]");
  if (!target || target.disabled) return;
  if (target.dataset.action === "invoice") { invoice(target.dataset.id); return; }
  if (["add", "increment", "decrement"].includes(target.dataset.action)) changeCart(target.dataset.id, target.dataset.action === "decrement" ? -1 : 1);
});
el.searchInput.addEventListener("input", renderProducts);
el.categoryFilter.addEventListener("change", renderProducts);
el.sortFilter.addEventListener("change", renderProducts);
el.clearCart.addEventListener("click", () => { if (!cartLocked()) { state.cart.clear(); renderAll(); status(el.checkoutMessage, "Keranjang dikosongkan."); } });
el.checkoutButton.addEventListener("click", checkout);
el.refreshButton.addEventListener("click", () => {
  if (state.loading || state.checkoutBusy || state.authBusy) return;
  if (!state.attempt) status(el.checkoutMessage, "");
  refresh().catch(() => {});
});
el.themeToggle.addEventListener("click", () => {
  const dark = document.body.classList.toggle("dark");
  el.themeToggle.setAttribute("aria-pressed", String(dark));
  el.themeToggle.textContent = dark ? "Tema terang" : "Tema gelap";
});
renderAll(); renderInvoice(); refresh().catch(() => {});
