# PR 2a + 2b: Frontend component, hook and context tests — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unit tests for every frontend component, hook and context the POS page actually uses,
shipped as two PRs under 1,000 counted lines each.

**Architecture:** React Testing Library + `user-event` against the real components, with mocks only
at the boundaries (`axios`, `@inertiajs/react` `router`/`usePage`, `react-hot-toast`, global `fetch`,
`window.confirm`, `driver.js`). Tests sit next to the file under test. No application code changes.

**Tech Stack:** Vitest 3.2, jsdom 26, `@testing-library/react` 16, `@testing-library/user-event` 14
(all installed by PR 1, #11).

**Spec:** `docs/superpowers/specs/2026-11-20-test-suite-design.md` §6. Roadmap:
`docs/superpowers/plans/2026-11-20-test-suite-roadmap.md` (row 2).

## Scope decision (for the maintainer's review)

Spec §6 lists `CartPanel` and `PaymentPanel` among the POS components to test. Reading the code
shows they are **not used by any page**: `Pages/Dashboard/Transactions/Index.jsx` renders its own
cart and payment UI. `SearchBar`, `BarcodeScanner`, `Checkbox` and the `Components/POS/index.jsx`
barrel have no importer either. Testing them would protect code no user runs, and `PaymentPanel`'s
total (`subtotal - discount`, ignoring voucher, points, shipping and tax) disagrees with the server's
`grand_total`, so its tests would lock in numbers that are wrong for the real data.

**This plan does not test those five files.** Task 0 opens one issue asking the maintainer to wire
or delete them; tests follow whichever is chosen.

## Global Constraints

- No application code changes. A test that shows the application is wrong is not weakened and the
  app is not fixed here: the implementer reports it, the controller opens a `fix/` issue, and the
  test is kept as `it.fails("…", …) // #<issue>` so it flips to a failure when fixed (spec §7.9).
- Mock only at boundaries: `vi.mock("axios")`, `vi.mock("@inertiajs/react", …)` (`router`,
  `usePage`), `vi.mock("react-hot-toast", …)`, `vi.stubGlobal("fetch", …)`,
  `vi.spyOn(window, "confirm")`, `vi.mock("driver.js", …)`. Never mock the unit under test or its
  sibling components.
- Money appears as `Rp 15.000` with a non-breaking space; RTL's default normalizer makes
  `getByText("Rp 15.000")` (plain space) match. Never compare raw `textContent` for money.
- The global `route` stub (`resources/js/test/setup.js`) returns `` `/${name}` `` and ignores
  params. When a test must assert params, spy: `vi.spyOn(globalThis, "route")` and assert its
  call arguments.
- Prefer `getByRole`/`getByLabelText`/`getByPlaceholderText`/`getByText`; icon-only buttons are
  found by their `title` (`getByTitle`).
- 4-space indent, relative import of the unit under test, `describe`/`it`/`expect`/`vi` imported
  from `vitest` (globals are off).
- Each PR stays under 1,000 counted lines. Commits: Conventional Commits, body ends
  `Refs #<issue>`, no AI attribution.

## Review Focus

1. **Out-of-stock and low-stock products on the POS grid:** `stock: 0` must not reach the cart;
   `stock: 3` shows "Sisa 3" → Task 2.
2. **Customer create form submitted with blank or whitespace-only fields:** no request, three
   messages → Task 4.
3. **Barcode scanner vs. normal typing:** slow keys, short codes and focused text inputs must not
   trigger a scan → Task 7.
4. **Numpad limits:** a digit that would exceed `maxValue` is ignored; Konfirmasi disabled below
   `minValue` → Task 1.
5. **Deleting a held transaction needs confirmation:** cancel sends nothing → Task 6.

---

## PR 2a — POS components (issue A)

### Task 0: Issues and branch

- [ ] Controller opens issue A (#18) "Frontend tests for POS components" and issue B (#19) "Frontend tests for
  hooks, contexts and base components" (`module:platform`, `needs-triage`, `Backlog: none — test
  infrastructure`), and issue "Unused POS components: wire or delete" (#20) (`module:sales`) listing
  `CartPanel`, `PaymentPanel` (with its total formula vs. server `grand_total`), `SearchBar`,
  `BarcodeScanner`, `Checkbox`, `Components/POS/index.jsx`. Branch `test/<A>-pos-component-tests`.

### Task 1: NumpadModal

**Files:** Test: `resources/js/Components/POS/NumpadModal.test.jsx`

Props: `isOpen, onClose, onConfirm, title="Masukkan Angka", initialValue=0, minValue=0,
maxValue=999999999, isCurrency=false`.

- [ ] Write tests:
  - `renders nothing when closed`
  - `typing digits builds the value`: `isCurrency`, click 1,5,0,0,0 → "Rp 15.000"; without
    `isCurrency` → "15.000"
  - `a leading zero is replaced`: click 0 then 7 → shows 7
  - `backspace and clear`: from "15" backspace → "1", backspace → "0"; "C" → "0"
  - `a digit beyond maxValue is ignored`: `maxValue=100`, click 1,0,0,5 → stays 100
  - `quick amounts add to the value` (only when `isCurrency`): "+10rb" then "+50rb" from empty →
    "Rp 60.000"; with `maxValue=50000` "+100rb" is ignored; no quick buttons without `isCurrency`
  - `Konfirmasi sends a number then closes`: value 15000 → `onConfirm(15000)` then `onClose()`,
    each once, in that order
  - `Konfirmasi disabled below minValue`: `minValue=1`, empty → disabled
  - `keyboard`: `keyDown(window, {key:"4"})` then `"2"` → 42; `Enter` confirms with 42; `Escape`
    calls `onClose`; `c` clears
  - `resets when reopened`: rerender closed then open with `initialValue=500` → shows 500
- [ ] Run `npx vitest run resources/js/Components/POS/NumpadModal.test.jsx` → PASS.

### Task 2: ProductGrid

**Files:** Test: `resources/js/Components/POS/ProductGrid.test.jsx`

Fixtures: `kopi {id:1, title:"Kopi Susu", barcode:"KS-01", category_id:1, sell_price:15000,
stock:3}`, `teh {id:2, title:"Teh Manis", barcode:null, category_id:2, sell_price:8000, stock:20}`,
`roti {id:3, title:"Roti", category_id:2, sell_price:12000, stock:0}`; categories
`[{id:1,name:"Minuman"},{id:2,name:"Makanan"}]`.

- [ ] Write tests:
  - `shows all products for the "Semua" tab` (`selectedCategory: null`)
  - `filters by category`: `selectedCategory: "2"` (string) → Teh Manis and Roti only
  - `search matches title and barcode, case-insensitively`: `"kopi"` → Kopi Susu; `"ks-01"` →
    Kopi Susu; `"xyz"` → "Produk tidak ditemukan"; no products and no query → "Tidak ada produk"
  - `category tabs call onCategoryChange`: "Semua" → `null`; "Makanan" → `2` (number)
  - `stock states`: Kopi Susu shows "Sisa 3"; Roti shows "Habis" and its add button is disabled;
    clicking it does not call `onAddToCart`
  - `clicking an in-stock product adds it with the base unit`: → `onAddToCart(kopi, null)`
  - `promo price`: `pricing_badge {label:"Promo", promo_price:12000, base_price:15000}` → shows
    "Rp 12.000" and the struck "Rp 15.000"; `promo_price` ≥ base → no promo price
  - `unit chips`: `units:[{id:9, code:"BOX", sell_price:140000, is_base:false}]` → chips "Dasar",
    "BOX"; select BOX → price "Rp 140.000" with "/BOX"; add → `onAddToCart(product, unit)`;
    "Dasar" → back to base
  - `search input`: typing calls `onSearchChange`; Enter calls `onSearch`; disabled while
    `isSearching`
- [ ] Run the file → PASS.

### Task 3: CustomerSelect

**Files:** Test: `resources/js/Components/POS/CustomerSelect.test.jsx`

Mocks: `axios` (`post`), `@inertiajs/react` (`router.reload`). Children are real
(`AddCustomerModal`, `CustomerHistoryButton`). Fixtures: `ani {id:1, name:"Ani", no_telp:"0811",
member_code:"M-001", is_loyalty_member:true, loyalty_tier:"gold", loyalty_points:120}`,
`budi {id:2, name:"Budi", no_telp:null, is_loyalty_member:false}`.

- [ ] Write tests:
  - `shows the placeholder, then the selected customer`: no selection → "Pilih pelanggan...";
    `selected: ani` → "Ani", "0811", "M-001", "gold • 120 poin"; `selected: budi` → "Non-member"
  - `filters by name, phone and member code`: open, type "m-001" → only Ani; "zzz" → "Pelanggan
    tidak ditemukan"
  - `selecting a customer calls onSelect and closes`
  - `clicking outside closes the list` (`mousedown` on `document.body`)
  - `upgrade member`: `selected: budi`, `tierOptions:[{value:"silver",label:"Silver"}]`,
    `axios.post` resolves `{data:{success:true, customer:{...budi, is_loyalty_member:true}}}` →
    `route` called with `("customers.upgrade-member", 2)`, payload `{loyalty_tier:"silver"}`,
    `onSelect` with the upgraded customer, `router.reload({only:["customers"]})`; no tier options →
    payload `{loyalty_tier:"regular"}`; button absent for a member
  - `adding a customer selects it`: open "Tambah pelanggan", fill and save (axios resolves
    success) → `onCustomerAdded` and `onSelect` with the new customer, `router.reload` called
- [ ] Run the file → PASS.

### Task 4: AddCustomerModal

**Files:** Test: `resources/js/Components/POS/AddCustomerModal.test.jsx`

Mocks: `axios`, `react-hot-toast` (`toast.success`, `toast.error`).

- [ ] Write tests:
  - `renders nothing when closed`
  - `blank fields show three messages and send nothing`: Simpan with fields "   " → "Nama wajib
    diisi", "No. telepon wajib diisi", "Alamat wajib diisi"; `axios.post` not called
  - `typing clears that field's error`
  - `member tier select appears only for members`: check "Member" → "Tier Awal" select with
    `tierOptions`
  - `success`: fill Nama "Citra", Telepon "0812", Alamat "Jl. Mawar" → `axios.post` to
    `/customers.storeAjax` with `{name:"Citra", no_telp:"0812", address:"Jl. Mawar",
    is_loyalty_member:false, loyalty_tier:"regular"}`; `toast.success("Pelanggan berhasil
    ditambahkan")`; `onSuccess(customer)` then `onClose()`
  - `server says no`: resolves `{data:{success:false, message:"Telepon sudah dipakai",
    errors:{no_telp:"Telepon sudah dipakai"}}}` → toast.error with that message, field error shown
  - `request rejected`: rejects `{response:{data:{message:"Validasi gagal"}}}` → toast.error
    "Validasi gagal"; button back to "Simpan"
  - `Batal resets and closes`
- [ ] Run the file → PASS.

### Task 5: CustomerHistoryPanel and CustomerHistoryButton

**Files:** Test: `resources/js/Components/POS/CustomerHistoryPanel.test.jsx`

Mock: `vi.stubGlobal("fetch", vi.fn())`.

- [ ] Write tests:
  - `fetches the customer's history`: `customerId: 5` → `fetch` called once with
    `"/customers.history"` and headers `{Accept:"application/json", "X-Requested-With":
    "XMLHttpRequest"}`; `route` spied with `("customers.history", 5)`
  - `renders stats and sections`: payload `{success:true, stats:{total_transactions:3,
    total_spent:150000, last_visit:"2026-11-20"}, loyalty:{is_member:true, tier:"gold", points:40},
    eligible_vouchers:[{id:1, code:"V1", name:"Hemat", minimum_order:50000}],
    frequent_products:[{id:7, title:"Kopi Susu", total_qty:4}],
    recent_transactions:[{id:3, invoice:"TRX-1", date:"2026-11-20", total:50000}],
    loyalty_history:[{id:1, reference:"TRX-1", type:"earn", points_delta:50,
    created_at:"2026-11-20"}, {id:2, reference:null, type:"redeem", points_delta:-20,
    created_at:"2026-11-21"}]}` → "Rp 150.000", "gold", "40", "V1 - Hemat", "Min belanja
    Rp 50.000", "Kopi Susu", "×4", "TRX-1", "+50 poin", "redeem", "-20 poin"
    (keys verified in `CustomerHistoryPanel.jsx:95-102` and the `.map` blocks)
  - `empty history`: `total_transactions: 0` → "Belum ada transaksi"
  - `errors`: `ok:false` → "Gagal memuat data pelanggan"; `{success:false, message:"Tidak
    ditemukan"}` → "Tidak ditemukan"; rejected fetch → "Gagal memuat data pelanggan"
  - `CustomerHistoryButton`: no `customerId` → renders nothing; click "Lihat riwayat" opens the
    panel; its close button closes it
- [ ] Run the file → PASS.

### Task 6: HeldTransactions and HoldButton

**Files:** Test: `resources/js/Components/POS/HeldTransactions.test.jsx`

Mocks: `@inertiajs/react` (`router.post`, `router.delete`), `react-hot-toast`,
`vi.spyOn(window, "confirm")`. Fixture: `[{hold_id:"h1", label:"Meja 3", items_count:2,
total:30000}, {hold_id:"h2", label:"Take away", items_count:1, total:15000}]`.

- [ ] Write tests:
  - `renders nothing without held carts`
  - `collapsed badge shows count and total`: "2", "Transaksi Ditahan", "• Rp 45.000"
  - `expanded rows`: click badge → "Meja 3", "2 item • Rp 30.000"
  - `resume`: click "Lanjutkan" on Meja 3 → `router.post("/transactions.resume", {},
    {preserveScroll:true, onSuccess, onError})` (route spied with `("transactions.resume","h1")`);
    calling the captured `onSuccess` → `toast.success("Transaksi dilanjutkan")`; `onError({message:
    "Shift tutup"})` → `toast.error("Shift tutup")`
  - `resume is disabled while a cart is active`: `hasActiveCart` → button titled "Kosongkan
    keranjang dulu", disabled
  - `delete asks first`: `confirm` → false → `router.delete` not called; → true →
    `router.delete("/transactions.clearHold", …)`; `onSuccess` → `toast.success("Transaksi
    dihapus")`; `onError` → `toast.error("Gagal menghapus transaksi")`
  - `HoldButton`: hidden without items; "Tahan" → input "Label (opsional)"; type "Meja 5" + Enter →
    `onHold("Meja 5")`; empty label + "OK" → `onHold(null)`; Escape closes without calling
- [ ] Run the file → PASS.

### Task A-final: CI, docs, PR

- [ ] `npm test` → all pass, no warnings. `COVERAGE`-style note: none (e2e not yet).
- [ ] PR (`Closes #A`), Docs: n/a — tests only. Request the maintainer's review.

---

## PR 2b — Hooks, contexts and base components (issue B)

Branch `test/<B>-hooks-contexts-tests`, from `main` after 2a merges (or in parallel; files don't
overlap).

### Task 7: useBarcodeScanner

**Files:** Test: `resources/js/Hooks/useBarcodeScanner.test.js`

Use `renderHook`, `vi.useFakeTimers()` (fakes `Date.now`), `fireEvent.keyDown(document.body,
{key})`, advance with `vi.advanceTimersByTime(ms)`.

- [ ] Write tests:
  - `fast keys then Enter scan`: "1","2","3" 10 ms apart, Enter → `onScan("123")`,
    `result.current.lastBarcode === "123"`
  - `too short is ignored`: "1","2", Enter → not called
  - `a slow gap restarts the buffer`: "1", +60 ms, "2","3","4", Enter → `onScan("234")`
  - `buffer expires after 150 ms`: "1","2","3", +151 ms, Enter → not called
  - `focused text input is ignored`: focus `<input type="text">`, keys + Enter → not called; same
    for `textarea`; a focused `<input type="checkbox">` is not ignored
  - `disabled`: `{enabled:false}` → not called
  - `only barcode characters`: "A","-","_","."," ", "#" → `" "` and `"#"` not appended
  - `reset()` clears the scanning state; `lastBarcode` kept
  - `cleanup`: unmount, then keys + Enter → not called
- [ ] Run the file → PASS.

### Task 8: useTour

**Files:** Test: `resources/js/Hooks/useTour.test.jsx`

Mocks: `@inertiajs/react` (`usePage` → `{props:{auth:{completedTours:[...]}}}`), `axios`,
`driver.js` (`driver: vi.fn(cfg => ({drive: vi.fn(), destroy: vi.fn(() => cfg.onDestroyed?.())}))`).
Render a container with `data-tour="pos-search"` etc. matching `posTour` steps before the hook.

- [ ] Write tests:
  - `auto-starts an unseen tour after 600 ms`: `completedTours: []`, tour "pos" → after 599 ms no
    driver; after 600 ms `driver` called once, `drive()` called, `isActive` true
  - `never auto-starts a completed tour`
  - `skips when no step target is on the page`: no `data-tour` elements → `driver` not called
  - `unknown tour name does nothing`
  - `finishing the tour records completion`: call the captured `onDestroyed` → `axios.post`
    to `/tours.complete` with `{}` and `{headers:{Accept:"application/json"}}` (route spied with
    `("tours.complete", {tour:"pos"})`), `isActive` false
  - `start() is idempotent while running`
  - Do **not** test "navigating away mid-tour"; that behavior is under issue triage (Task 0b).
- [ ] Run the file → PASS.

### Task 9: OnlineStatusContext and ThemeSwitcherContext

**Files:** Tests: `resources/js/Context/OnlineStatusContext.test.jsx`,
`resources/js/Context/ThemeSwitcherContext.test.jsx`

- [ ] OnlineStatus tests: `starts from navigator.onLine` (spy getter → false → hook false);
  `follows offline/online events` (`act` + `window.dispatchEvent(new Event("offline"))`);
  `defaults to online outside the provider`.
- [ ] ThemeSwitcher tests (`beforeEach`: `localStorage.clear()`, `document.body.className = ""`,
  `document.documentElement.className = ""`): `reads darkMode from storage` ("true" → `darkMode`
  true, body has `dark`); `defaults to light and stores "false"`; `themeSwitcher toggles class and
  storage`; `no-transition is removed after a tick` (fake timers).
- [ ] Run both files → PASS.

### Task 10: Base components (smoke)

**Files:** Test: `resources/js/Components/base-components.test.jsx`

- [ ] Tests: `InputError` renders the message, nothing when empty; `InputLabel` prefers `value`
  over children; `TextInput` forwards a ref and focuses when `isFocused`; `Modal` shows children
  when `show`, Escape calls `onClose` only when `closeable` (use `findBy*`/`waitFor` for the
  headlessui transition); `Dropdown` opens on trigger click and closes on a content click (mock
  `@inertiajs/react` `Link` as an `<a>`).
- [ ] Run → PASS.

### Task B-final: issue for useTour, CI, PR

- [ ] Task 0b (controller, before Task 8): open issue (#21) "Leaving a page mid-tour marks the tour as
  completed" (`module:platform`, `needs-triage`): driver.js 1.x `destroy()` fires `onDestroyed`,
  and `useTour`'s unmount cleanup calls `destroy()`, so `tours.complete` is posted on navigation;
  ask whether a dismissed or abandoned tour should count as completed.
- [ ] `npm test` → all pass, no warnings. PR (`Closes #B`).
