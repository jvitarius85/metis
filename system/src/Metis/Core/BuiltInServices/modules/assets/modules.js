(function () {
  if (window.__metisModulesStoreActionsInitialized) {
    return;
  }

  function modulesRoot() {
    return document.querySelector('[data-settings-live-root="modules"]');
  }

  function modulesFeedbackTarget() {
    var root = modulesRoot();
    return root ? root.querySelector('[data-settings-live-feedback]') : null;
  }

  function setModulesFeedback(message, variant) {
    var target = modulesFeedbackTarget();
    if (!target) return;
    if (!message) {
      target.innerHTML = '';
      return;
    }

    var tone = variant === 'error'
      ? ' style="color:#b91c1c;"'
      : (variant === 'warning' ? ' style="color:#92400e;"' : '');
    target.innerHTML = '<p class="metis-help"' + tone + '>' + escapeHtml(String(message)) + '</p>';
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>\"']/g, function (ch) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[ch];
    });
  }

  function showToast(type, message, options) {
    if (window.Metis && Metis.util && typeof Metis.util.notify === 'function') {
      return Metis.util.notify(message, type, options);
    }
  }

  function confirmAction(message, options) {
    if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
      return Metis.confirm.open(Object.assign({ message: message }, options || {}));
    }
    showToast('error', 'Confirmation dialog unavailable.');
    return Promise.resolve(false);
  }

  function actionNonce(action) {
    return window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function'
      ? Metis.ajax.nonceFor(action, (window.metisAjax && window.metisAjax.nonce) || '')
      : ((window.metisAjax && window.metisAjax.nonce) || '');
  }

  function postAction(action, body) {
    body.append('action', action);
    body.append('nonce', (window.metisAjax && window.metisAjax.nonce) || '');
    body.append('metis_action_nonce', actionNonce(action));
    return Metis.request.postForm(window.metisAjax || null, action, body, 'Modules AJAX not configured.');
  }

  function beginAsyncButton(button, loadingLabel) {
    var originalText = String(button.textContent || '').trim();
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    if (button.classList.contains('metis-module-action') || button.classList.contains('metis-module-refresh')) {
      button.classList.add('is-loading');
    } else if (loadingLabel) {
      button.textContent = loadingLabel;
    }
    return function endAsyncButton() {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.classList.remove('is-loading');
      if (!(button.classList.contains('metis-module-action') || button.classList.contains('metis-module-refresh')) && loadingLabel) {
        button.textContent = originalText;
      }
    };
  }

  function refreshModulesRoot() {
    var currentRoot = modulesRoot();
    if (!currentRoot) {
      return Promise.resolve();
    }

    var refreshUrl = new URL(window.location.href, window.location.origin);
    refreshUrl.searchParams.set('_settings_refresh', String(Date.now()));

    return fetch(refreshUrl.toString(), {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Unable to refresh the page state.');
      }
      return response.text();
    }).then(function (html) {
      var parser = new DOMParser();
      var doc = parser.parseFromString(html, 'text/html');
      var replacement = doc.querySelector('[data-settings-live-root="modules"]');
      if (!replacement || !currentRoot.parentNode) {
        throw new Error('Updated modules content was not available.');
      }
      currentRoot.replaceWith(replacement);
      bindActions(document);
    });
  }

  function moduleActionPrompt(kind, label) {
    if (kind === 'update') {
      return { message: 'Update ' + label + ' now?', title: 'Update Module', confirmLabel: 'Update' };
    }
    if (kind === 'reinstall') {
      return { message: 'Reinstall ' + label + ' now?', title: 'Reinstall Module', confirmLabel: 'Reinstall' };
    }
    if (kind === 'uninstall') {
      return { message: 'Uninstall ' + label + ' now?', title: 'Uninstall Module', confirmLabel: 'Uninstall' };
    }
    return { message: 'Install ' + label + ' now?', title: 'Install Module', confirmLabel: 'Install' };
  }

  function bindActions(scope) {
    (scope || document).querySelectorAll('[data-release-check-updates]').forEach(function (button) {
      if (button.dataset.metisBound === '1') return;
      button.dataset.metisBound = '1';
      button.addEventListener('click', function () {
        var endLoading = beginAsyncButton(button, 'Refreshing...');
        setModulesFeedback('Refreshing module registry and update metadata...', '');
        postAction('metis_release_check_updates', new FormData()).then(function (data) {
          return refreshModulesRoot().then(function () {
            setModulesFeedback('', '');
            showToast('success', String((data && data.message) || 'Module Store refreshed.'));
          });
        }).catch(function (error) {
          setModulesFeedback(error && error.message ? error.message : 'Refresh failed.', 'error');
          showToast('error', error && error.message ? error.message : 'Refresh failed.');
        }).finally(function () {
          endLoading();
        });
      });
    });

    (scope || document).querySelectorAll('[data-module-install-id]').forEach(function (button) {
      if (button.dataset.metisBound === '1') return;
      button.dataset.metisBound = '1';
      button.addEventListener('click', function () {
        var moduleId = String(button.getAttribute('data-module-install-id') || '').trim();
        var moduleName = String(button.getAttribute('data-module-install-name') || moduleId).trim();
        var moduleVersion = String(button.getAttribute('data-module-install-version') || '').trim();
        var actionKind = String(button.getAttribute('data-module-action-kind') || 'install').trim() || 'install';
        if (!moduleId) return;

        var label = moduleVersion ? (moduleName + ' ' + moduleVersion) : moduleName;
        var prompt = moduleActionPrompt(actionKind, label);

        confirmAction(prompt.message, {
          title: prompt.title,
          confirmLabel: prompt.confirmLabel
        }).then(function (confirmed) {
          if (!confirmed) return;

          var endLoading = beginAsyncButton(button);
          setModulesFeedback(prompt.confirmLabel + 'ing ' + label + '...', '');

          var body = new FormData();
          body.append('module_id', moduleId);
          var action = actionKind === 'uninstall'
            ? 'metis_module_uninstall_now'
            : 'metis_module_install_now';

          postAction(action, body).then(function (data) {
            return refreshModulesRoot().then(function () {
              setModulesFeedback('', '');
              showToast('success', String((data && data.message) || 'Module updated.'));
            });
          }).catch(function (error) {
            setModulesFeedback(error && error.message ? error.message : 'Module action failed.', 'error');
            showToast('error', error && error.message ? error.message : 'Module action failed.');
          }).finally(function () {
            endLoading();
          });
        });
      });
    });

    (scope || document).querySelectorAll('[data-module-update-all]').forEach(function (button) {
      if (button.dataset.metisBound === '1') return;
      button.dataset.metisBound = '1';
      button.addEventListener('click', function () {
        confirmAction('Install every available module update now?', {
          title: 'Update All Modules',
          confirmLabel: 'Update All'
        }).then(function (confirmed) {
          if (!confirmed) return;

          var endLoading = beginAsyncButton(button);
          setModulesFeedback('Installing available module updates...', '');
          postAction('metis_module_install_all_updates', new FormData()).then(function (data) {
            return refreshModulesRoot().then(function () {
              setModulesFeedback('', '');
              showToast('success', String((data && data.message) || 'Module updates applied.'));
            });
          }).catch(function (error) {
            setModulesFeedback(error && error.message ? error.message : 'Module updates failed.', 'error');
            showToast('error', error && error.message ? error.message : 'Module updates failed.');
          }).finally(function () {
            endLoading();
          });
        });
      });
    });
  }

  function initModulesStoreActions() {
    if (window.__metisModulesStoreActionsInitialized) {
      return;
    }
    window.__metisModulesStoreActionsInitialized = true;
    bindActions(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initModulesStoreActions, { once: true });
  } else {
    initModulesStoreActions();
  }
}());
