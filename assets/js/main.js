/* =========================================================
   Main JS — TB5 Monitoring System
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    // ---------------------------------------------------
    // 1. Sidebar collapse toggle
    // ---------------------------------------------------
    const sidebar     = document.getElementById('tb5Sidebar');
    const toggleBtn   = document.getElementById('sidebarToggle');
    const mainContent = document.querySelector('.main-content');

    if (sidebar && toggleBtn) {
        if (localStorage.getItem('sidebarCollapsed') === '1') {
            sidebar.classList.add('collapsed');
            mainContent && mainContent.classList.add('sidebar-collapsed');
        }
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            const collapsed = sidebar.classList.contains('collapsed');
            mainContent && mainContent.classList.toggle('sidebar-collapsed', collapsed);
            localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
        });
    }

    // ---------------------------------------------------
    // 2. Auto-dismiss floating alerts
    // ---------------------------------------------------
    document.querySelectorAll('.alert-float').forEach(function (el) {
        setTimeout(() => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity    = '0';
            setTimeout(() => el.remove(), 500);
        }, 4000);
    });

    // ---------------------------------------------------
    // 3. DataTables — slide-table with custom controls
    // ---------------------------------------------------
    const docTableEl = document.getElementById('documentsTable');
    let dtInstance   = null;

    if (docTableEl && typeof $ !== 'undefined' && typeof $.fn.DataTable !== 'undefined') {
        dtInstance = $(docTableEl).DataTable({
            paging:     true,
            pageLength: 25,
            ordering:   false,
            // 'r' = processing, 't' = table, 'i' = info, 'p' = pagination
            // We deliberately omit 'l' (length) and 'f' (filter) — we use our own controls
            dom:        'rtip',
            language: {
                emptyTable:  'No documents found.',
                zeroRecords: 'No matching records found.',
                info:        'Showing _START_–_END_ of _TOTAL_ records',
                infoEmpty:   'Showing 0 records',
            },
            columnDefs: [
                { orderable: false, targets: '_all' },
            ],
        });

        // Wire custom length select
        const lenSel = document.getElementById('dtLengthSelect');
        if (lenSel) {
            lenSel.addEventListener('change', function () {
                dtInstance.page.len(parseInt(this.value)).draw();
                updateDtInfo();
            });
        }

        // Wire custom search input
        const searchIn = document.getElementById('dtSearchInput');
        if (searchIn) {
            searchIn.addEventListener('input', function () {
                dtInstance.search(this.value).draw();
                updateDtInfo();
            });
        }

        // Update info footer
        function updateDtInfo() {
            const info = document.getElementById('dtInfoRow');
            if (!info || !dtInstance) return;
            const api = dtInstance.page.info();
            if (api.recordsTotal === 0) {
                info.textContent = 'No records';
            } else if (api.recordsDisplay === api.recordsTotal) {
                info.textContent = `Showing all ${api.recordsTotal} record(s)`;
            } else {
                info.textContent =
                    `Showing ${api.start + 1}–${api.end} of ${api.recordsDisplay} ` +
                    `(filtered from ${api.recordsTotal} total)`;
            }
        }

        $(docTableEl).on('draw.dt', updateDtInfo);
    }

    // ---------------------------------------------------
    // 4. Select-all checkbox
    // ---------------------------------------------------
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.row-check').forEach(cb => {
                cb.checked = selectAll.checked;
            });
        });
        // Uncheck "selectAll" if any individual box unchecked
        document.querySelectorAll('.row-check').forEach(cb => {
            cb.addEventListener('change', function () {
                if (!this.checked) selectAll.checked = false;
            });
        });
    }

    // ---------------------------------------------------
    // 5. Bulk archive button guard
    // ---------------------------------------------------
    const bulkArchiveBtn = document.getElementById('bulkArchiveBtn');
    if (bulkArchiveBtn) {
        bulkArchiveBtn.addEventListener('click', function () {
            const checked = document.querySelectorAll('.row-check:checked').length;
            if (checked === 0) {
                showToast('Select at least one row first.', 'warning'); return;
            }
            if (confirm(`Archive ${checked} selected document(s)?`)) {
                document.getElementById('bulkForm')?.submit();
            }
        });
    }

    // ---------------------------------------------------
    // 6. Print button
    // ---------------------------------------------------
    document.getElementById('printBtn')?.addEventListener('click', function () {
        const table = document.getElementById('documentsTable');
        if (!table) {
            window.print();
            return;
        }

        const clone = table.cloneNode(false);
        const head = table.querySelector('thead');
        if (head) clone.appendChild(head.cloneNode(true));

        const body = document.createElement('tbody');
        let sourceRows = Array.from(table.querySelectorAll('tbody tr'));
        if (dtInstance && typeof dtInstance.rows === 'function') {
            sourceRows = Array.from(dtInstance.rows({ search: 'applied' }).nodes());
        }
        sourceRows.forEach(function (tr) {
            body.appendChild(tr.cloneNode(true));
        });
        clone.appendChild(body);

        // Remove no-print columns and checkbox selector column.
        clone.querySelectorAll('tr').forEach(function (row) {
            row.querySelectorAll('.no-print').forEach(function (el) { el.remove(); });
            const firstCell = row.children[0];
            if (firstCell && firstCell.querySelector('input[type="checkbox"]')) {
                firstCell.remove();
            }
        });

        clone.querySelectorAll('img').forEach(function (img) {
            const src = img.getAttribute('src') || img.src;
            img.src = new URL(src, window.location.href).href;
            img.style.width = '52px';
            img.style.height = '52px';
            img.style.objectFit = 'cover';
            img.style.borderRadius = '6px';
        });

        const pageTitle = document.querySelector('.page-title')?.textContent?.trim() || 'Documents Tracking';
        const printHtml = `<!doctype html>
<html>
<head>
<meta charset="utf-8">
    <title></title>
<style>
    @page{margin:0}
    html,body{margin:0;padding:0;background:#fff}
    body{font-family:Segoe UI,Arial,sans-serif;color:#111;padding:18px}
h1{font-size:20px;margin:0 0 4px}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{border:1px solid #cfd6df;padding:6px 8px;vertical-align:middle;text-align:left}
th{background:#eef3fb;font-weight:700;white-space:nowrap}
.empty-cell{color:#888}
</style>
</head>
<body>
<h1>${escHtml(pageTitle)}</h1>
${clone.outerHTML}
</body>
</html>`;

        let printFrame = document.getElementById('tablePrintFrame');
        if (!printFrame) {
            printFrame = document.createElement('iframe');
            printFrame.id = 'tablePrintFrame';
            printFrame.style.position = 'fixed';
            printFrame.style.right = '0';
            printFrame.style.bottom = '0';
            printFrame.style.width = '0';
            printFrame.style.height = '0';
            printFrame.style.border = '0';
            document.body.appendChild(printFrame);
        }

        const printBlob = new Blob([printHtml], { type: 'text/html' });
        const printBlobUrl = URL.createObjectURL(printBlob);

        printFrame.onload = function () {
            try {
                printFrame.contentWindow.focus();
                printFrame.contentWindow.print();
            } catch (err) {
                showToast('Unable to print. Please try again.', 'danger');
            } finally {
                setTimeout(function () { URL.revokeObjectURL(printBlobUrl); }, 1200);
            }
        };
        printFrame.src = printBlobUrl;
    });

    // ---------------------------------------------------
    // 7. Image thumbnails → preview modal
    // ---------------------------------------------------
    const previewPrintBtn = document.getElementById('previewPrint');
    if (previewPrintBtn) {
        previewPrintBtn.addEventListener('click', function () {
            const modal = document.getElementById('imagePreviewModal');
            if (!modal) return;

            const openLink = modal.querySelector('#previewOpen');
            const previewIframe = modal.querySelector('#previewIframe');
            const previewImg = modal.querySelector('#previewImg');

            let targetUrl = '';
            let isPdf = false;
            if (previewIframe && previewIframe.src && previewIframe.style.display !== 'none') {
                targetUrl = previewIframe.src;
                isPdf = true;
            } else if (previewImg && previewImg.src && previewImg.style.display !== 'none') {
                targetUrl = previewImg.currentSrc || previewImg.src;
            } else if (openLink && openLink.getAttribute('href') && !openLink.classList.contains('disabled')) {
                targetUrl = openLink.getAttribute('href');
                isPdf = /\.pdf(?:$|[?#])/i.test(targetUrl);
            }

            if (!targetUrl) {
                showToast('No preview file to print.', 'warning');
                return;
            }

            if (isPdf) {
                const cacheBustedUrl = targetUrl + (targetUrl.includes('?') ? '&' : '?') + 'print=' + Date.now();
                const pdfWin = window.open(cacheBustedUrl, '_blank');
                if (!pdfWin) {
                    showToast('Allow pop-ups to print PDF preview.', 'warning');
                    return;
                }

                const tryPdfPrint = function () {
                    try {
                        pdfWin.focus();
                        pdfWin.print();
                    } catch (err) {
                        // Ignore and retry once for slow PDF viewers.
                    }
                };

                setTimeout(tryPdfPrint, 500);
                setTimeout(tryPdfPrint, 1400);
                return;
            }

            const safeImgUrl = String(targetUrl)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            const printWin = window.open('', '_blank');
            if (!printWin) {
                showToast('Allow pop-ups to print image preview.', 'warning');
                return;
            }

            const imagePrintHtml = `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title></title>
<style>
@page{margin:0}
html,body{margin:0;padding:0;background:#fff}
body{display:flex;align-items:center;justify-content:center;min-height:100vh}
img{max-width:100%;max-height:100vh;object-fit:contain}
</style>
</head>
<body>
<img id="printImage" src="${safeImgUrl}" alt="Preview">
<script>
  (function(){
        try { document.title = ''; } catch (e) {}
    var img = document.getElementById('printImage');
    var printed = false;
    function doPrint(){
      if (printed) return;
      printed = true;
      try { window.focus(); window.print(); } catch (e) {}
    }
    if (img.complete) {
      setTimeout(doPrint, 100);
    } else {
      img.onload = function(){ setTimeout(doPrint, 80); };
      img.onerror = function(){ setTimeout(doPrint, 80); };
    }
    window.onafterprint = function(){ setTimeout(function(){ window.close(); }, 120); };
  })();
</script>
</body>
</html>`;

            printWin.document.open();
            printWin.document.write(imagePrintHtml);
            printWin.document.close();
        });
    }

    document.querySelectorAll('.doc-thumb').forEach(function (img) {
        img.addEventListener('click', function () {
            const modal = document.getElementById('imagePreviewModal');
            if (!modal) return;

            // Build absolute URL from the image src attribute to avoid resolution issues
            const rawSrc = img.getAttribute('src') || img.src;
            const url = new URL(rawSrc, window.location.href).href;

            const previewImg = modal.querySelector('#previewImg');
            const previewIframe = modal.querySelector('#previewIframe');
            const previewMsg = modal.querySelector('#previewMsg');
            const openLink = document.getElementById('previewOpen');
            const downloadLink = document.getElementById('previewDownload');

            // Reset UI and disable links until proven reachable
            previewMsg.style.display = 'none';
            previewImg.style.display = 'none';
            previewImg.src = '';
            previewIframe.style.display = 'none';
            previewIframe.src = '';
            if (openLink) { openLink.removeAttribute('href'); openLink.classList.add('disabled'); }
            if (downloadLink) { downloadLink.removeAttribute('href'); downloadLink.classList.add('disabled'); }

            // Quick check that the resource exists — fetch HEAD
            fetch(url, { method: 'HEAD' })
                .then(function (res) {
                    if (!res.ok) throw res;
                    // Resource reachable — proceed to preview
                    const lower = url.split('?')[0].toLowerCase();
                    const isPdf = lower.endsWith('.pdf');

                    if (isPdf) {
                        previewIframe.style.display = '';
                        previewIframe.src = url;
                    } else {
                        previewImg.style.display = '';
                        previewImg.onload = function () { previewMsg.style.display = 'none'; };
                        previewImg.onerror = function () {
                            previewImg.style.display = 'none';
                            previewMsg.style.display = '';
                            previewMsg.textContent = 'Image failed to load after successful fetch.';
                        };
                        previewImg.src = url;
                    }

                    // Enable open/download
                    if (openLink) { openLink.href = url; openLink.classList.remove('disabled'); openLink.textContent = isPdf ? 'Open PDF' : 'Open'; }
                    if (downloadLink) {
                        downloadLink.href = url; downloadLink.classList.remove('disabled'); downloadLink.textContent = isPdf ? 'Download PDF' : 'Download';
                        try { const parts = url.split('/'); const fname = parts[parts.length - 1] || 'document'; downloadLink.setAttribute('download', decodeURIComponent(fname.split('?')[0])); } catch (e) { downloadLink.setAttribute('download', 'document'); }
                    }

                    new bootstrap.Modal(modal).show();
                })
                .catch(function (err) {
                    // err may be a Response or a network error
                    let msg = 'Unable to fetch file.';
                    if (err && err.status) msg += ` Server returned ${err.status} ${err.statusText || ''}`;
                    else if (err && err.message) msg += ` ${err.message}`;
                    previewMsg.style.display = '';
                    previewMsg.textContent = msg + ' Try checking the file path or server permissions.';

                    // Still set Open link to allow attempt in new tab
                    if (openLink) { openLink.href = url; openLink.classList.remove('disabled'); openLink.textContent = 'Open'; }
                    if (downloadLink) { downloadLink.href = url; downloadLink.classList.remove('disabled'); downloadLink.textContent = 'Download'; }

                    new bootstrap.Modal(modal).show();
                });
        });
    });

    // ---------------------------------------------------
    // 8. Upload-image buttons → imageUploadModal
    // ---------------------------------------------------
    document.querySelectorAll('.btn-upload-img').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const docId  = btn.dataset.docId;
            const modal  = document.getElementById('imageUploadModal');
            if (modal && docId) {
                document.getElementById('imgUpload_docId').value = docId;
                new bootstrap.Modal(modal).show();
            }
        });
    });

    // ---------------------------------------------------
    // Dynamic Category -> Document Type filtering and Document Sub population
    const addDocType = document.getElementById('add_document_type');
    const editDocType = document.getElementById('editCore_dtId');
    const addDocTypeOptions = addDocType
        ? Array.from(addDocType.options)
            .filter(o => String(o.value || '').trim() !== '')
            .map(o => ({ v: o.value, t: o.text, cat: o.getAttribute('data-cat') || '' }))
        : [];
    const editDocTypeOptions = editDocType
        ? Array.from(editDocType.options)
            .filter(o => String(o.value || '').trim() !== '')
            .map(o => ({ v: o.value, t: o.text, cat: o.getAttribute('data-cat') || '' }))
        : [];

    function filterDocTypeOptionsByCategory(catId, selectEl, originalOptions) {
        selectEl.innerHTML = '';
        const placeholder = document.createElement('option'); placeholder.value=''; placeholder.textContent='—';
        selectEl.appendChild(placeholder);
        originalOptions.forEach(opt => {
            // show option if no category assigned or matches selected category
            if (!opt.cat || String(opt.cat) === String(catId)) {
                const o = document.createElement('option'); o.value = opt.v; o.textContent = opt.t; o.setAttribute('data-cat', opt.cat || '');
                selectEl.appendChild(o);
            }
        });
    }

    function populateSubOptionsByDocType(docTypeId, subSelect) {
        subSelect.innerHTML = '';
        const ph = document.createElement('option'); ph.value=''; ph.textContent='—'; subSelect.appendChild(ph);
        try {
            const list = (typeof DOC_SUBS !== 'undefined' && DOC_SUBS[docTypeId]) ? DOC_SUBS[docTypeId] : [];
            list
                .filter(s => String(s || '').trim() !== '')
                .forEach(s => {
                    const o = document.createElement('option');
                    o.value = s;
                    o.textContent = s;
                    subSelect.appendChild(o);
                });
        } catch (e) { /* ignore */ }
    }

    // Wire add modal: category -> filter doc types; doc type -> populate subs
    (function wireAddModal(){
        const modal = document.getElementById('addDocModal');
        if (!modal) return;
        const catSel = modal.querySelector('select[name="category_id"]');
        const typeSel = document.getElementById('add_document_type');
        const subSel = document.getElementById('add_document_sub');
        const qualSel = modal.querySelector('select[name="qualification_id"]');
        if (!typeSel || !catSel || !subSel) return;
        catSel.addEventListener('change', function(){
            const catVal = catSel.value || '';
            filterDocTypeOptionsByCategory(catVal, typeSel, addDocTypeOptions);
            populateSubOptionsByDocType('', subSel);
            // Toggle qualification availability: only for categories with 'billing' in the name
            try {
                const catText = catSel.options[catSel.selectedIndex]?.text || '';
                const isBilling = /billing/i.test(catText);
                if (qualSel) {
                    qualSel.disabled = !isBilling;
                    if (!isBilling) qualSel.value = '';
                }
            } catch(e) {}
        });
        typeSel.addEventListener('change', function(){
            populateSubOptionsByDocType(typeSel.value, subSel);
        });
        // initialize qualification state on modal open
        modal.addEventListener('show.bs.modal', function(){
            try { const ev = new Event('change'); catSel.dispatchEvent(ev); } catch(e) {}
        });
    })();

    // Wire edit modal: category -> filter doc types; doc type -> populate subs
    (function wireEditModal(){
        const catSel = document.getElementById('editCore_catId');
        const typeSel = document.getElementById('editCore_dtId');
        const subSel = document.getElementById('editCore_documentSub');
        const qualSel = document.getElementById('editCore_qualId');
        if (!typeSel || !catSel || !subSel) return;

        function syncEditQualificationState() {
            const catText = catSel.options[catSel.selectedIndex]?.text || '';
            const isBilling = /billing/i.test(catText);
            if (qualSel) {
                qualSel.disabled = !isBilling;
                if (!isBilling) qualSel.value = '';
            }
        }

        catSel.addEventListener('change', function(){
            const catVal = catSel.value || '';
            filterDocTypeOptionsByCategory(catVal, typeSel, editDocTypeOptions);
            populateSubOptionsByDocType('', subSel);
            syncEditQualificationState();
        });
        typeSel.addEventListener('change', function(){
            populateSubOptionsByDocType(typeSel.value, subSel);
        });
        // ensure qualification state updates when edit modal is shown (populate handler triggers change)
        const editModal = document.getElementById('editCoreModal');
        if (editModal) editModal.addEventListener('show.bs.modal', function(){ syncEditQualificationState(); });
    })();

    // ---------------------------------------------------
    // 9. Edit-core modal population
    // ---------------------------------------------------
    document.querySelectorAll('.btn-edit-core').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const data   = JSON.parse(btn.getAttribute('data-row'));
            const modal  = document.getElementById('editCoreModal');
            if (!modal) return;

            document.getElementById('editCore_docId').value      = data.doc_id           ?? '';
            const catSel = document.getElementById('editCore_catId');
            const dtSel = document.getElementById('editCore_dtId');
            const subSel = document.getElementById('editCore_documentSub');
            const qualSel = document.getElementById('editCore_qualId');

            if (catSel) catSel.value = data.category_id ?? '';
            if (dtSel) {
                filterDocTypeOptionsByCategory(catSel ? catSel.value : '', dtSel, editDocTypeOptions);
                dtSel.value = data.document_type_id ?? '';
            }
            if (subSel) {
                populateSubOptionsByDocType(dtSel ? dtSel.value : '', subSel);
                subSel.value = data.document_sub ?? '';
            }
            document.getElementById('editCore_qualId').value     = data.qualification_id ?? '';
            document.getElementById('editCore_dateSub').value    = data.date_submission  ?? '';
            document.getElementById('editCore_batchNo').value    = data.batch_no         ?? '';
            document.getElementById('editCore_remarks').value    = data.remarks          ?? '';

            // Keep qualification field appropriate for selected category
            if (catSel && qualSel) {
                const catText = catSel.options[catSel.selectedIndex]?.text || '';
                const isBilling = /billing/i.test(catText);
                qualSel.disabled = !isBilling;
                if (!isBilling) qualSel.value = '';
            }

            new bootstrap.Modal(modal).show();
        });
    });

    // ---------------------------------------------------
    // 10. Inline cell editing
    // ---------------------------------------------------
    document.querySelectorAll('.cell-field').forEach(function (cell) {
        cell.addEventListener('click', function (e) {
            if (cell.classList.contains('editing')) return;
            // Don't activate if clicked inside an already-open edit
            activateInlineEdit(cell);
        });
    });

    function activateInlineEdit(cell) {
        const field  = cell.dataset.field;
        const docId  = cell.dataset.docId;
        const type   = cell.dataset.type;   // 'date', 'text', 'textarea'
        const rawVal = cell.dataset.value;
        cell.classList.add('editing');
        const originalHTML = cell.innerHTML;

        // Build input inside a pill-like inline editor
        let input;
        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 2;
        } else {
            input = document.createElement('input');
            input.type = (type === 'date') ? 'date' : 'text';
        }
        input.value     = rawVal || '';
        input.className = 'cell-input';

        const editor = document.createElement('div');
        editor.className = 'inline-editor';

        // Controls (icon buttons)
        const controls = document.createElement('div');
        controls.className = 'cell-edit-controls';

        const saveBtn   = document.createElement('button');
        saveBtn.type    = 'button';
        saveBtn.className = 'cell-save-btn';
        saveBtn.title   = 'Save (Enter)';
        saveBtn.innerHTML = '<i class="bi bi-check-lg"></i>';

        const cancelBtn   = document.createElement('button');
        cancelBtn.type    = 'button';
        cancelBtn.className = 'cell-cancel-btn';
        cancelBtn.title   = 'Cancel (Esc)';
        cancelBtn.innerHTML = '<i class="bi bi-x-lg"></i>';

        controls.appendChild(saveBtn);
        controls.appendChild(cancelBtn);

        editor.appendChild(input);
        editor.appendChild(controls);

        // Render
        cell.innerHTML = '';
        cell.appendChild(editor);
        input.focus();
        if (input.type === 'text' || input.tagName === 'TEXTAREA') input.select();

        // ---- Save logic (same optimistic approach) ----
        function doSave() {
            if (!cell.classList.contains('editing')) return;
            cell.classList.remove('editing');

            const newVal = input.value.trim();

            // Optimistic update UI
            cell.innerHTML = '<span class="cell-saving"><i class="bi bi-arrow-repeat"></i></span>';

            const body = new URLSearchParams({ doc_id: docId, field: field, value: newVal });
            fetch('ajax_save_cell.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
            })
            .then(r => r.json())
            .then(function (data) {
                if (data.ok) {
                    const display = renderCellValue(type, newVal);
                    cell.dataset.value = newVal;
                    cell.innerHTML = `<span class="cell-val">${display}</span>`;

                    if (field === 'received_tesda') {
                        const row = cell.closest('tr');
                        if (row) {
                            const ret = row.querySelector('[data-field="returned_center"]');
                            if (ret) {
                                ret.dataset.value = newVal;
                                ret.innerHTML = renderCellValue('date', newVal);
                                ret.classList.add('cell-updated');
                                setTimeout(() => ret.classList.remove('cell-updated'), 1400);
                            }
                        }
                    }

                    cell.classList.add('cell-saved');
                    setTimeout(() => cell.classList.remove('cell-saved'), 1400);
                } else {
                    cell.innerHTML = originalHTML;
                    showToast('Save failed: ' + (data.error || 'Unknown error'), 'danger');
                }
            })
            .catch(function () {
                cell.innerHTML = originalHTML;
                showToast('Connection error — changes not saved.', 'danger');
            });
        }

        // ---- Cancel ----
        function doCancel() {
            if (!cell.classList.contains('editing')) return;
            cell.classList.remove('editing');
            cell.innerHTML = originalHTML;
            cell.addEventListener('click', handleCellClick);
        }

        saveBtn.addEventListener('click', doSave);
        cancelBtn.addEventListener('click', doCancel);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && type !== 'textarea') { e.preventDefault(); doSave(); }
            if (e.key === 'Escape') doCancel();
        });

        input.addEventListener('blur', function () {
            setTimeout(() => { if (cell.classList.contains('editing')) doSave(); }, 180);
        });
    }

    function handleCellClick() {
        if (!this.classList.contains('editing')) activateInlineEdit(this);
    }

    // Format a saved value for display
    function renderCellValue(type, val) {
        if (!val) return '<span class="empty-cell">—</span>';
        if (type === 'date') {
            // Expecting val like "2026-01-15"
            const parts = val.split('-');
            if (parts.length === 3) {
                return `${parts[1]}/${parts[2]}/${parts[0]}`;
            }
        }
        return escHtml(val);
    }

    // ---------------------------------------------------
    // Remarks dropdown: modern styling + delegated save
    // ---------------------------------------------------
    function updateRemarksClass(el, val) {
        if (!el) return;
        el.classList.remove('remarks-received', 'remarks-returned', 'remarks-empty');
        if (!val) el.classList.add('remarks-empty');
        else if (val === 'received') el.classList.add('remarks-received');
        else if (val === 'returned') el.classList.add('remarks-returned');
        el.setAttribute('data-value', val || '');
    }

    // Initialize present selects with the correct class
    document.querySelectorAll('.remarks-select').forEach(function (el) {
        const cur = el.value || el.getAttribute('data-value') || '';
        updateRemarksClass(el, cur);
    });

    // Delegated change handler with optimistic UI and revert on failure
    document.addEventListener('change', function (e) {
        const el = e.target;
        if (!el || !el.classList.contains('remarks-select')) return;
        const docId = el.dataset.docId;
        const val = el.value || null;
        const oldVal = el.getAttribute('data-value') || null;

        // Optimistically update UI
        updateRemarksClass(el, val);

        const body = new URLSearchParams({ doc_id: docId, field: 'remarks', value: val });
        fetch('ajax_save_cell.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then(r => r.json())
        .then(function (data) {
            if (!data.ok) {
                showToast('Save failed: ' + (data.error || 'Unknown error'), 'danger');
                updateRemarksClass(el, oldVal);
            } else {
                el.setAttribute('data-value', val || '');
                showToast('Remarks updated.', 'success');
            }
        })
        .catch(function () {
            showToast('Connection error — changes not saved.', 'danger');
            updateRemarksClass(el, oldVal);
        });
    });

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ---------------------------------------------------
    // 11. Utility: floating toast notification
    // ---------------------------------------------------
    window.showToast = function (msg, type = 'success') {
        const el = document.createElement('div');
        el.className = `alert alert-${type} alert-dismissible alert-float fade show`;
        el.innerHTML = `${escHtml(msg)}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        document.body.appendChild(el);
        setTimeout(() => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity    = '0';
            setTimeout(() => el.remove(), 500);
        }, 4000);
    };

    // ---------------------------------------------------
    // 12. add_data.php: openEditModal helper
    // ---------------------------------------------------
    window.openEditModal = function (tableKey, itemId, name) {
        const el = document.getElementById('editItemModal');
        if (!el) return;
        document.getElementById('edit_table_key').value = tableKey;
        document.getElementById('edit_item_id').value   = itemId;
        document.getElementById('edit_item_name').value = name;
        new bootstrap.Modal(el).show();
    };

    // ---------------------------------------------------
    // 13. usermanage.php: openEditUser helper
    // ---------------------------------------------------
    window.openEditUser = function (u) {
        const el = document.getElementById('editUserModal');
        if (!el) return;
        document.getElementById('edit_uid').value    = u.id;
        document.getElementById('edit_uname').value  = u.username;
        document.getElementById('edit_uemail').value = u.email;
        new bootstrap.Modal(el).show();
    };

});
