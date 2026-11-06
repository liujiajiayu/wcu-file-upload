(() => {
  'use strict';

  const boot = () => {
    document.querySelectorAll('.wcu-uploader:not([data-wcu-ready])').forEach(initUploader);
  };

  const initUploader = (root) => {
    root.dataset.wcuReady = '1';
    const zone = root.querySelector('.wcu-dropzone');
    const input = root.querySelector('.wcu-file-input');
    const list = root.querySelector('.wcu-file-list');
    const tokenInput = root.querySelector('.wcu-upload-tokens');
    const errorBox = root.querySelector('.wcu-error');
    const form = root.closest('form.cart');
    const addButton = form ? form.querySelector('.single_add_to_cart_button, button[type="submit"]') : null;
    const items = new Map();
    let pending = 0;
    const setBusy = () => {
      if (!addButton) return;
      addButton.disabled = pending > 0;
      addButton.setAttribute('aria-busy', pending > 0 ? 'true' : 'false');
    };

    const showError = (message) => {
      errorBox.textContent = message || wcuUpload.messages.failed;
      errorBox.hidden = false;
    };
    const clearError = () => {
      errorBox.textContent = '';
      errorBox.hidden = true;
    };
    const syncTokens = () => {
      tokenInput.value = Array.from(items.values()).map((item) => item.token).filter(Boolean).join(',');
    };
    const extensionOf = (name) => {
      const pos = name.lastIndexOf('.');
      return pos === -1 ? '' : name.slice(pos + 1).toLowerCase();
    };

    const addRow = (file) => {
      const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
      const li = document.createElement('li');
      li.className = 'wcu-file-row is-uploading';

      const details = document.createElement('div');
      details.className = 'wcu-file-details';
      const name = document.createElement('span');
      name.className = 'wcu-file-name';
      name.textContent = file.name;
      const size = document.createElement('small');
      size.textContent = formatBytes(file.size);
      details.append(name, size);

      const status = document.createElement('span');
      status.className = 'wcu-file-status';
      status.textContent = wcuUpload.messages.uploading;
      li.append(details, status);
      list.append(li);
      items.set(id, { token: '', row: li });
      return { id, li, status };
    };

    const uploadFile = async (file) => {
      clearError();
      const ext = extensionOf(file.name);
      if (!wcuUpload.extensions.includes(ext)) {
        showError(`${file.name}: ${wcuUpload.messages.badType}`);
        return;
      }
      if (file.size < 1 || file.size > Number(wcuUpload.maxBytes)) {
        showError(`${file.name}: ${wcuUpload.messages.tooLarge}`);
        return;
      }
      const activeCount = Array.from(items.values()).filter((item) => item.token || item.row.classList.contains('is-uploading')).length;
      if (activeCount >= Number(wcuUpload.maxFiles)) {
        showError(wcuUpload.messages.tooMany);
        return;
      }

      const { id, li, status } = addRow(file);
      pending += 1;
      setBusy();
      const body = new FormData();
      body.append('action', 'wcu_upload');
      body.append('nonce', wcuUpload.nonce);
      body.append('product_id', wcuUpload.productId);
      body.append('file', file, file.name);

      try {
        const response = await fetch(wcuUpload.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
        const json = await response.json();
        if (!response.ok || !json.success) {
          throw new Error(json?.data?.message || wcuUpload.messages.failed);
        }
        items.get(id).token = json.data.token;
        li.classList.remove('is-uploading');
        li.classList.add('is-success');
        status.textContent = `✓ ${wcuUpload.messages.uploaded}`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'wcu-remove';
        remove.textContent = wcuUpload.messages.remove;
        remove.addEventListener('click', () => removeUpload(id));
        li.append(remove);
        syncTokens();
      } catch (error) {
        items.delete(id);
        li.classList.remove('is-uploading');
        li.classList.add('is-error');
        status.textContent = `✗ ${error.message}`;
        showError(error.message);
      } finally {
        pending -= 1;
        setBusy();
      }
    };

    const removeUpload = async (id) => {
      const item = items.get(id);
      if (!item || !item.token) return;
      item.row.classList.add('is-removing');
      const body = new URLSearchParams({
        action: 'wcu_remove_upload',
        nonce: wcuUpload.nonce,
        token: item.token
      });
      try {
        const response = await fetch(wcuUpload.ajaxUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString()
        });
        const json = await response.json();
        if (!response.ok || !json.success) throw new Error(json?.data?.message || wcuUpload.messages.failed);
        item.row.remove();
        items.delete(id);
        syncTokens();
        clearError();
      } catch (error) {
        item.row.classList.remove('is-removing');
        showError(error.message);
      }
    };

    const chooseFiles = (files) => {
      const selected = Array.from(files || []);
      if (selected.length + items.size > Number(wcuUpload.maxFiles)) {
        showError(wcuUpload.messages.tooMany);
        return;
      }
      selected.forEach(uploadFile);
      input.value = '';
    };

    zone.addEventListener('click', () => input.click());
    zone.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        input.click();
      }
    });
    input.addEventListener('change', () => chooseFiles(input.files));
    ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (event) => {
      event.preventDefault();
      zone.classList.add('is-dragging');
    }));
    ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, (event) => {
      event.preventDefault();
      zone.classList.remove('is-dragging');
    }));
    zone.addEventListener('drop', (event) => chooseFiles(event.dataTransfer.files));

    if (form) {
      form.addEventListener('submit', (event) => {
        if (pending > 0) {
          event.preventDefault();
          showError(wcuUpload.messages.uploading);
          return;
        }
        if (root.dataset.required === '1' && !tokenInput.value) {
          event.preventDefault();
          showError(wcuUpload.messages.required);
        }
      }, true);
    }
  };

  const formatBytes = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1048576).toFixed(1)} MB`;
  };

  document.addEventListener('DOMContentLoaded', boot);
  window.addEventListener('elementor/frontend/init', boot);
})();
