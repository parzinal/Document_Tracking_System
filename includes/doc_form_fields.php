<?php
// =========================================================
// Shared document form fields — used by Add & Edit modals
// Requires: $categories, $documentTypes, $qualifications
// =========================================================
?>
<style>
.form-section-title {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--accent, #1a3a5c);
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--accent-light, #e8f1fb);
}
.form-section-title i { font-size: 0.85rem; }

.doc-form-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6b7a8c;
    margin-bottom: 5px;
    display: block;
}

.doc-form-control {
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 0.88rem;
    background: #f7f9fc;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    width: 100%;
}
.doc-form-control:focus {
    border-color: var(--accent, #3584e4);
    box-shadow: 0 0 0 3px var(--accent-light, rgba(53,132,228,0.12));
    background: #fff;
    outline: none;
}

.doc-field-wrap { display: flex; flex-direction: column; }
</style>

<!-- ─────────────────────────────────────────────────────── -->
<!-- SECTION 1: Document Info                              -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="mb-4">
    <div class="form-section-title">
        <i class="bi bi-folder2-open"></i> Document Information
    </div>
    <div class="row g-3">
        <div class="col-md-4 doc-field-wrap">
            <label class="doc-form-label">Documents / Category</label>
            <select name="category_id" class="doc-form-control form-select">
                <option value="">— Select Category —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 doc-field-wrap">
            <label class="doc-form-label">Qualification</label>
            <select name="qualification_id" class="doc-form-control form-select">
                <option value="">— Select Qualification —</option>
                <?php foreach ($qualifications as $q): ?>
                    <option value="<?= $q['id'] ?>"><?= htmlspecialchars($q['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 doc-field-wrap">
            <label class="doc-form-label">Batch / Document Type</label>
            <select name="document_type_id" class="doc-form-control form-select">
                <option value="">— Select Document Type —</option>
                <?php foreach ($documentTypes as $dt): ?>
                    <option value="<?= $dt['id'] ?>"><?= htmlspecialchars($dt['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 doc-field-wrap">
            <label class="doc-form-label">Document Sub</label>
            <select name="document_sub" id="form_document_sub" class="doc-form-control form-select">
                <option value="">— Select Sub-Document —</option>
            </select>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────── -->
<!-- SECTION 2: Tracking Timeline                           -->
<!-- ─────────────────────────────────────────────────────── -->
<div class="mb-4">
    <div class="form-section-title">
        <i class="bi bi-calendar3"></i> Tracking &amp; Timeline
    </div>
    <div class="row g-3">
        <div class="col-md-4 col-sm-6 doc-field-wrap">
            <label class="doc-form-label">Date of Submission</label>
            <input type="date" name="date_submission" class="doc-form-control">
        </div>
        <div class="col-md-4 col-sm-6 doc-field-wrap">
            <label class="doc-form-label">Received by TESDA</label>
            <input type="date" name="received_tesda" class="doc-form-control">
        </div>
        <div class="col-md-4 col-sm-6 doc-field-wrap">
            <label class="doc-form-label">Staff Received</label>
            <input type="text" name="staff_received" class="doc-form-control" placeholder="Staff name">
        </div>
        <div class="col-md-4 col-sm-6 doc-field-wrap">
            <label class="doc-form-label">Date of Assessment</label>
            <input type="date" name="date_assessment" class="doc-form-control">
        </div>
        <div class="col-md-4 col-sm-6 doc-field-wrap">
            <label class="doc-form-label">Assessor Name</label>
            <input type="text" name="assessor_name" class="doc-form-control" placeholder="Assessor name">
        </div>
        <div class="col-md-4 col-sm-6 doc-field-wrap">
            <label class="doc-form-label">TESDA Released</label>
            <input type="date" name="tesda_released" class="doc-form-control">
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────── -->
<!-- SECTION 3: Remarks & Attachment                        -->
<!-- ─────────────────────────────────────────────────────── -->
<div>
    <div class="form-section-title">
        <i class="bi bi-paperclip"></i> Remarks &amp; Attachment
    </div>
    <div class="row g-3">
        <div class="col-md-8 doc-field-wrap">
            <label class="doc-form-label">Remarks</label>
            <textarea name="remarks" class="doc-form-control" rows="3" placeholder="Optional remarks…" style="resize:vertical;"></textarea>
        </div>
        <div class="col-md-4 doc-field-wrap">
            <label class="doc-form-label">Upload Image / Document</label>
            <div class="upload-zone" id="uploadZone" onclick="document.getElementById('docImageInput').click()" style="border:2px dashed #d0d7e2;border-radius:10px;padding:20px 12px;text-align:center;cursor:pointer;background:#f7f9fc;transition:border-color 0.2s,background 0.2s;">
                <i class="bi bi-cloud-arrow-up" style="font-size:1.6rem;color:#8899aa;"></i>
                <p class="mb-0 mt-1" style="font-size:0.78rem;color:#8899aa;" id="uploadLabel">Click to upload<br><span style="font-size:0.7rem;">JPG, PNG, PDF · Max 5 MB</span></p>
            </div>
            <input type="file" name="doc_image" id="docImageInput" accept="image/*,.pdf" class="d-none">
        </div>
    </div>
</div>

<script>
(function(){
    const input  = document.getElementById('docImageInput');
    const zone   = document.getElementById('uploadZone');
    const label  = document.getElementById('uploadLabel');
    if (!input) return;
    input.addEventListener('change', function(){
        if (this.files.length) {
            label.innerHTML = '<strong style="color:#1a3a5c">' + this.files[0].name + '</strong><br><span style="font-size:0.7rem;color:#8899aa;">' + (this.files[0].size/1024).toFixed(1) + ' KB</span>';
            zone.style.borderColor = 'var(--accent, #3584e4)';
            zone.style.background  = 'var(--accent-light, #e8f1fb)';
        }
    });
    zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.style.borderColor='var(--accent,#3584e4)'; });
    zone.addEventListener('dragleave', function(){ zone.style.borderColor='#d0d7e2'; });
    zone.addEventListener('drop', function(e){
        e.preventDefault();
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            input.dispatchEvent(new Event('change'));
        }
    });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const mapping = {
        'billing': {
            'training cost': ['INITIAL','REMAINING'],
            'training support fund': ['INITIAL','REMAINING']
        },
        'request': {},
        'transmittal letter': {},
        'report': {}
    };
    function norm(s){ return String(s||'').trim().toLowerCase(); }
    const cat = document.querySelector('select[name="category_id"]');
    const dt  = document.querySelector('select[name="document_type_id"]');
    const sub = document.getElementById('form_document_sub');
    if (!dt || !sub) return;
    dt.addEventListener('change', function(){
        const catText = cat ? (cat.options[cat.selectedIndex]?.text || '') : '';
        const dtText = dt.options[dt.selectedIndex]?.text || '';
        const subs = (mapping[norm(catText)] && mapping[norm(catText)][norm(dtText)]) ? mapping[norm(catText)][norm(dtText)] : [];
        sub.innerHTML = '<option value="">— Select Sub-Document —</option>';
        subs.forEach(s=>{ const o=document.createElement('option'); o.value=s; o.textContent=s; sub.appendChild(o); });
    });
});
</script>
