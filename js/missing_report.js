// ── Table state ──────────────────────────────────────────────────────────
const allRows = Array.from(document.querySelectorAll('#mdrTbody tr'));
let visibleRows  = [...allRows];
let currentPage  = 1;
let pageSize     = 50;
let sortCol      = -1;
let sortAsc      = true;
let activeForm   = 'all';
let searchTerm   = '';

// ── Filter by form card click ────────────────────────────────────────────
function filterByForm(form, el) {
  activeForm = form;
  document.querySelectorAll('.mdr-form-card').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  applyFilters();
}

// ── Search input ─────────────────────────────────────────────────────────
function applySearch() {
  searchTerm = document.getElementById('mdrSearch').value.toLowerCase();
  applyFilters();
}

// ── Apply all active filters ──────────────────────────────────────────────
function applyFilters() {
  visibleRows = allRows.filter(row => {
    const formMatch = activeForm === 'all' || row.dataset.form === activeForm;
    const text      = row.textContent.toLowerCase();
    const searchMatch = !searchTerm || text.includes(searchTerm);
    return formMatch && searchMatch;
  });
  currentPage = 1;
  render();
}

// ── Sort table by column ──────────────────────────────────────────────────
function sortTable(col) {
  if (sortCol === col) {
    sortAsc = !sortAsc;
  } else {
    sortCol = col;
    sortAsc = true;
  }
  document.querySelectorAll('table.mdr-table thead th').forEach((th, i) => {
    th.classList.toggle('sorted', i === col);
    const arrow = th.querySelector('.sort-arrow');
    if (arrow && i === col) arrow.innerHTML = sortAsc ? '&#x25B2;' : '&#x25BC;';
  });
  visibleRows.sort((a, b) => {
    const av = a.cells[col]?.textContent.trim() ?? '';
    const bv = b.cells[col]?.textContent.trim() ?? '';
    const num_a = parseFloat(av), num_b = parseFloat(bv);
    const cmp = (!isNaN(num_a) && !isNaN(num_b))
      ? num_a - num_b
      : av.localeCompare(bv);
    return sortAsc ? cmp : -cmp;
  });
  render();
}

// ── Pagination ────────────────────────────────────────────────────────────
function setPageSize(val) {
  pageSize    = parseInt(val);
  currentPage = 1;
  render();
}

function goToPage(p) {
  currentPage = p;
  render();
}

// ── Main render ───────────────────────────────────────────────────────────
function render() {
  const total = visibleRows.length;
  const pages = pageSize >= 9999 ? 1 : Math.ceil(total / pageSize);
  if (currentPage > pages) currentPage = Math.max(1, pages);

  const start = pageSize >= 9999 ? 0 : (currentPage - 1) * pageSize;
  const end   = pageSize >= 9999 ? total : Math.min(start + pageSize, total);

  // Hide all, show page slice
  allRows.forEach(r => r.style.display = 'none');
  visibleRows.forEach((r, i) => {
    r.style.display = (i >= start && i < end) ? '' : 'none';
  });

  // Row count label
  document.getElementById('mdrRowCount').textContent =
    total === allRows.length
      ? `${total} rows`
      : `${total} of ${allRows.length} rows`;

  // Page info
  document.getElementById('mdrPageInfo').textContent =
    `Showing ${Math.min(start+1,total)}–${end} of ${total}`;

  // Page buttons
  const btnContainer = document.getElementById('mdrPageBtns');
  btnContainer.innerHTML = '';
  if (pages <= 1) return;

  const makeBtn = (label, page, disabled, active) => {
    const b = document.createElement('button');
    b.className = 'mdr-page-btn' + (active ? ' active' : '');
    b.innerHTML = label;
    b.disabled  = disabled;
    if (!disabled) b.onclick = () => goToPage(page);
    return b;
  };

  btnContainer.appendChild(makeBtn('&#x276E;', currentPage-1, currentPage===1, false));
  // Show at most 7 page buttons with ellipsis
  const range = [];
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || (i >= currentPage-2 && i <= currentPage+2)) {
      range.push(i);
    } else if (range[range.length-1] !== '...') {
      range.push('...');
    }
  }
  range.forEach(p => {
    if (p === '...') {
      const s = document.createElement('span');
      s.textContent = '…';
      s.style.cssText = 'padding:0 4px;color:#9aa3b5;font-size:11px;';
      btnContainer.appendChild(s);
    } else {
      btnContainer.appendChild(makeBtn(p, p, false, p === currentPage));
    }
  });
  btnContainer.appendChild(makeBtn('&#x276F;', currentPage+1, currentPage===pages, false));
}

// ── Init ──────────────────────────────────────────────────────────────────
render();
</script>
