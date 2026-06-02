(function(){
  // ── Expand / collapse long field values ─────────────────────────────────────
  document.querySelectorAll('.cf7w-expand-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var val = btn.closest('.cf7w-sub-val');
      val.querySelector('.cf7w-short').style.display = 'none';
      val.querySelector('.cf7w-full').style.display  = '';
    });
  });
  document.querySelectorAll('.cf7w-collapse-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var val = btn.closest('.cf7w-sub-val');
      val.querySelector('.cf7w-full').style.display  = 'none';
      val.querySelector('.cf7w-short').style.display = '';
    });
  });

  // ── Individual row delete ────────────────────────────────────────────────────
  document.querySelectorAll('.cf7w-delete-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id  = btn.getAttribute('data-id');
      var row = btn.closest('tr');

      if ( ! confirm( CF7W_Admin.i18n.confirm_delete_single ) ) {
        return;
      }

      btn.disabled    = true;
      btn.textContent = CF7W_Admin.i18n.deleting;

      var body = new URLSearchParams({
        action: 'cf7w_delete_submission',
        nonce:  CF7W_Admin.nonce,
        id:     id
      });

      fetch( ajaxurl, { method: 'POST', body: body } )
        .then( function(r){ return r.json(); } )
        .then( function(data){
          if ( data.success ) {
            // Fade the row out then remove it from the DOM
            row.style.transition = 'opacity 0.3s';
            row.style.opacity    = '0';
            setTimeout( function(){ row.remove(); }, 320 );
          } else {
            btn.disabled    = false;
            btn.textContent = CF7W_Admin.i18n.delete_label;
            alert( data.data.message || CF7W_Admin.i18n.delete_failed );
          }
        })
        .catch( function(){
          btn.disabled    = false;
          btn.textContent = CF7W_Admin.i18n.delete_label;
          alert( CF7W_Admin.i18n.network_error );
        });
    });
  });


  if ( CF7W_Admin.is_premium ) {
  // ── [PREMIUM] Batch select + ZIP export ─────────────────────────────────────
  var selectAllTop    = document.getElementById('cf7w-select-all');
  var selectAllHeader = document.querySelector('.cf7w-select-all-header');
  var rowCheckboxes   = document.querySelectorAll('.cf7w-row-select');

  function updateSelectedCount() {
    var count = document.querySelectorAll('.cf7w-row-select:checked').length;

    // Update all count spans (handles multiple buttons with same ID)
    document.querySelectorAll('#cf7w-selected-count').forEach(function(el){
      el.textContent = count;
    });

    // Enable / disable all batch buttons
    document.querySelectorAll('#cf7w-batch-download').forEach(function(btn){
      btn.disabled = count === 0;
    });
	
	// Enable / disable all batch buttons
    document.querySelectorAll('#cf7w-batch-delete').forEach(function(btn){
      btn.disabled = count === 0;
    });

    // Sync select-all checkbox state
    var allChecked  = rowCheckboxes.length > 0 && count === rowCheckboxes.length;
    var someChecked = count > 0 && count < rowCheckboxes.length;
    if (selectAllTop) {
      selectAllTop.checked       = allChecked;
      selectAllTop.indeterminate = someChecked;
    }
    if (selectAllHeader) {
      selectAllHeader.checked       = allChecked;
      selectAllHeader.indeterminate = someChecked;
    }
  }

  function toggleAll(checked) {
    rowCheckboxes.forEach(function(cb){ cb.checked = checked; });
    updateSelectedCount();
  }

  if (selectAllTop)    selectAllTop.addEventListener('change',    function(){ toggleAll(this.checked); });
  if (selectAllHeader) selectAllHeader.addEventListener('change', function(){ toggleAll(this.checked); });
  rowCheckboxes.forEach(function(cb){ cb.addEventListener('change', updateSelectedCount); });

  // Batch ZIP export click handler
  var batchDownloadBtn = document.getElementById('cf7w-batch-download');
  if (batchDownloadBtn) {
    batchDownloadBtn.addEventListener('click', function(){
    var checkedBoxes = document.querySelectorAll('.cf7w-row-select:checked');
    if (checkedBoxes.length === 0) {
        alert(CF7W_Admin.i18n.select_first);
        return;
    }

    var form = document.createElement('form');
    form.method = 'post';
    form.action = CF7W_Admin.admin_post_url;

    // Action field
    var actionInp = document.createElement('input');
    actionInp.type  = 'hidden';
    actionInp.name  = 'action';
    actionInp.value = 'cf7w_bulk_export';
    form.appendChild(actionInp);

    // Nonce field
    var nonceInp = document.createElement('input');
    nonceInp.type  = 'hidden';
    nonceInp.name  = '_wpnonce';
    nonceInp.value = CF7W_Admin.bulk_export_nonce;
    form.appendChild(nonceInp);

    // ID fields — one input per selected checkbox
    var idCount = 0;
    checkedBoxes.forEach(function(cb){
        var idInp = document.createElement('input');
        idInp.type  = 'hidden';
        idInp.name  = 'cf7w_ids[]';
        idInp.value = cb.value;
        form.appendChild(idInp);
        idCount++;
    });

    // Append form to body BEFORE submit so inputs are part of the DOM
    document.body.appendChild(form);
    // Use requestAnimationFrame to ensure DOM is fully settled before submit
    requestAnimationFrame(function(){
      form.submit();
    });
});
  }
  
  // ── Batch delete ─────────────────────────────────────────────────────────────
  var batchDeleteBtn = document.getElementById('cf7w-batch-delete');
  if (batchDeleteBtn) {
    batchDeleteBtn.addEventListener('click', function(){
      var checkedBoxes = document.querySelectorAll('.cf7w-row-select:checked');
      if (checkedBoxes.length === 0) {
        alert(CF7W_Admin.i18n.select_first);
        return;
      }
      if ( ! confirm( CF7W_Admin.i18n.confirm_delete_batch ) ) return;

      var ids = [];
      checkedBoxes.forEach(function(cb){ ids.push(cb.value); });

      batchDeleteBtn.disabled    = true;
      batchDeleteBtn.textContent = CF7W_Admin.i18n.deleting;

      var body = new URLSearchParams({
        action: 'cf7w_batch_delete_submissions',
        nonce:  CF7W_Admin.nonce
      });
      ids.forEach(function(id){ body.append('ids[]', id); });

      fetch( ajaxurl, { method: 'POST', body: body } )
        .then( function(r){ return r.json(); } )
        .then( function(data){
          if ( data.success ) {
            // Remove each deleted row from the DOM
            data.data.deleted_ids.forEach(function(id){
              var row = document.querySelector('tr[data-id="' + id + '"]');
              if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity    = '0';
                setTimeout(function(){ row.remove(); updateSelectedCount(); }, 320);
              }
            });
            batchDeleteBtn.textContent = CF7W_Admin.i18n.delete_selected_label;
          } else {
            batchDeleteBtn.disabled    = false;
            batchDeleteBtn.textContent = CF7W_Admin.i18n.delete_selected_label;
            alert( data.data.message || CF7W_Admin.i18n.delete_failed );
          }
        })
        .catch(function(){
          batchDeleteBtn.disabled    = false;
          batchDeleteBtn.textContent = CF7W_Admin.i18n.delete_selected_label;
          alert(CF7W_Admin.i18n.network_error);
        });
    });
  }

  updateSelectedCount();
  }

}());