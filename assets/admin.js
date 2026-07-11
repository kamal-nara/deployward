/* Deployward admin SPA - vanilla ES6, no framework, no build step */
/* Reads root and nonce from #deployward-app[data-root][data-nonce] */

(function () {
  'use strict';

  /* -------------------------------------------------------------------------
     Module constants
     ---------------------------------------------------------------------- */

  var LOG_PAGE_SIZE = 20;

  /* -------------------------------------------------------------------------
     Bootstrap
     ---------------------------------------------------------------------- */

  document.addEventListener('DOMContentLoaded', function () {
    const appEl = document.getElementById('deployward-app');
    if (!appEl) { return; }

    const root  = appEl.dataset.root;
    const nonce = appEl.dataset.nonce;

    if (!root || !nonce) { return; }

    const app = new DeploywardApp(appEl, root, nonce);
    app.init();
  });

  /* -------------------------------------------------------------------------
     API helper
     ---------------------------------------------------------------------- */

  function buildApi(root, nonce) {
    return async function api(method, path, body) {
      const opts = {
        method: method,
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json',
        },
      };
      if (body !== undefined) {
        opts.body = JSON.stringify(body);
      }
      const res = await fetch(root + path, opts);
      let data = {};
      try { data = await res.json(); } catch (_) { data = {}; }
      return { status: res.status, data: data };
    };
  }

  /* -------------------------------------------------------------------------
     Main app object
     ---------------------------------------------------------------------- */

  function DeploywardApp(appEl, root, nonce) {
    this.el    = appEl;
    this.api   = buildApi(root, nonce);
    this.tabs  = {};
    this.panels = {};
    this.activeTab = null;
    this.editingDeployment = null;
    this.logTargetId = null;
  }

  DeploywardApp.prototype.init = function () {
    this.theme = (this.el.dataset.theme === 'dark') ? 'dark' : 'light';
    if (this.theme === 'dark') {
      this.el.classList.add('dw-theme-dark');
    }

    clearEl(this.el);
    const header = buildHeader(this);
    this.el.appendChild(header);

    this.toastArea = el('div');
    this.el.appendChild(this.toastArea);

    const tabBar = buildTabBar(this);
    this.el.appendChild(tabBar);

    this.panelWrap = el('div');
    this.el.appendChild(this.panelWrap);

    const hash = location.hash.replace('#', '') || 'deployments';
    this.switchTab(hash);
  };

  DeploywardApp.prototype.switchTab = function (tabId) {
    const validTabs = ['deployments', 'add-deployment', 'activity-log', 'getting-started'];
    const id = validTabs.includes(tabId) ? tabId : 'deployments';

    if (this.activeTab === id && id !== 'deployments' && id !== 'activity-log') { return; }
    this.activeTab = id;
    location.hash = '#' + id;

    Object.keys(this.tabs).forEach(function (k) {
      if (this.tabs[k]) {
        toggleClass(this.tabs[k], 'is-active', k === id);
      }
    }, this);

    clearEl(this.panelWrap);

    if (id === 'deployments')     { renderDeployments(this); }
    if (id === 'add-deployment')  { renderAddDeployment(this); }
    if (id === 'activity-log')    { renderActivityLog(this); }
    if (id === 'getting-started') { renderGettingStarted(this); }
  };

  /* -------------------------------------------------------------------------
     Tab bar
     ---------------------------------------------------------------------- */

  function buildTabBar(app) {
    const bar = el('nav');
    bar.className = 'dw-tabs';

    const tabDefs = [
      { id: 'deployments',     label: 'Deployments' },
      { id: 'add-deployment',  label: 'Add Deployment' },
      { id: 'activity-log',    label: 'Activity Log' },
      { id: 'getting-started', label: 'Getting Started' },
    ];

    tabDefs.forEach(function (def) {
      const btn = el('button');
      btn.type = 'button';
      btn.className = 'dw-tab';
      btn.textContent = def.label;
      btn.setAttribute('aria-label', def.label + ' tab');
      btn.addEventListener('click', function () {
        if (def.id === 'add-deployment') {
          app.editingDeployment = null;
        }
        app.switchTab(def.id);
      });
      app.tabs[def.id] = btn;
      bar.appendChild(btn);
    });

    return bar;
  }

  /* -------------------------------------------------------------------------
     App header with theme toggle
     ---------------------------------------------------------------------- */

  function buildHeader(app) {
    const header = el('div');
    header.className = 'dw-header';

    const brand = el('div');
    brand.className = 'dw-header__brand';

    const logo = el('span');
    logo.className = 'dw-header__logo';
    const logoIcon = el('span');
    logoIcon.className = 'dashicons dashicons-update';
    logoIcon.setAttribute('aria-hidden', 'true');
    logo.appendChild(logoIcon);
    brand.appendChild(logo);

    const brandText = el('div');
    const titleEl = el('div');
    titleEl.className = 'dw-header__title';
    titleEl.textContent = 'Deployward';
    const subEl = el('div');
    subEl.className = 'dw-header__sub';
    subEl.textContent = 'Deploy plugins and themes from GitHub on push';
    brandText.appendChild(titleEl);
    brandText.appendChild(subEl);
    brand.appendChild(brandText);

    header.appendChild(brand);

    const toggle = buildThemeToggle(app);
    header.appendChild(toggle);

    return header;
  }

  function buildThemeToggle(app) {
    const btn = el('button');
    btn.type = 'button';
    btn.className = 'dw-theme-toggle';
    updateThemeToggle(btn, app.theme);

    btn.addEventListener('click', function () {
      const newTheme = app.theme === 'dark' ? 'light' : 'dark';
      const prevTheme = app.theme;

      app.theme = newTheme;
      if (newTheme === 'dark') {
        app.el.classList.add('dw-theme-dark');
      } else {
        app.el.classList.remove('dw-theme-dark');
      }
      updateThemeToggle(btn, newTheme);

      app.api('POST', 'preferences', { theme: newTheme }).then(function (res) {
        if (res.status < 200 || res.status >= 300) {
          app.theme = prevTheme;
          if (prevTheme === 'dark') {
            app.el.classList.add('dw-theme-dark');
          } else {
            app.el.classList.remove('dw-theme-dark');
          }
          updateThemeToggle(btn, prevTheme);
          app.showToast('is-error', 'Could not save theme preference.');
        }
      }).catch(function () {
        app.theme = prevTheme;
        if (prevTheme === 'dark') {
          app.el.classList.add('dw-theme-dark');
        } else {
          app.el.classList.remove('dw-theme-dark');
        }
        updateThemeToggle(btn, prevTheme);
        app.showToast('is-error', 'Could not save theme preference.');
      });
    });

    return btn;
  }

  function updateThemeToggle(btn, theme) {
    clearEl(btn);
    const icon = el('span');
    const labelSpan = el('span');
    if (theme === 'dark') {
      icon.className = 'dashicons dashicons-admin-appearance';
      icon.setAttribute('aria-hidden', 'true');
      labelSpan.textContent = 'Light';
      btn.setAttribute('aria-label', 'Switch to light mode');
    } else {
      icon.className = 'dashicons dashicons-admin-appearance';
      icon.setAttribute('aria-hidden', 'true');
      labelSpan.textContent = 'Dark';
      btn.setAttribute('aria-label', 'Switch to dark mode');
    }
    btn.appendChild(icon);
    btn.appendChild(labelSpan);
  }

  /* -------------------------------------------------------------------------
     Deployments tab
     ---------------------------------------------------------------------- */

  function renderDeployments(app) {
    clearEl(app.panelWrap);

    const panel = el('div');
    panel.className = 'dw-deployments';

    const loading = buildSkeleton();
    panel.appendChild(loading);
    app.panelWrap.appendChild(panel);

    app.api('GET', 'deployments').then(function (res) {
      clearEl(panel);
      if (res.status !== 200) {
        panel.appendChild(buildToastEl('is-error', res.data.error || 'Failed to load deployments.'));
        return;
      }
      const items = (res.data.deployments || []);
      if (items.length === 0) {
        panel.appendChild(buildEmptyState(
          'No deployments yet.',
          'Use the "Add Deployment" tab to connect your first GitHub repository.'
        ));
        return;
      }
      const stats = buildStatsStrip(items);
      app.panelWrap.insertBefore(stats, panel);
      items.forEach(function (d) {
        panel.appendChild(buildDeploymentCard(app, d));
      });
    }).catch(function () {
      clearEl(panel);
      panel.appendChild(buildToastEl('is-error', 'Network error loading deployments.'));
    });
  }

  function buildStatsStrip(items) {
    const strip = el('div');
    strip.className = 'dw-stats';

    const total = items.length;
    const deployed = items.filter(function (d) { return d.last_deployed_sha; }).length;

    function buildStat(label, value) {
      const stat = el('div');
      stat.className = 'dw-stat';
      const labelEl = el('span');
      labelEl.className = 'dw-stat__label';
      labelEl.textContent = label;
      const valueEl = el('span');
      valueEl.className = 'dw-stat__value';
      valueEl.textContent = String(value);
      stat.appendChild(labelEl);
      stat.appendChild(valueEl);
      return stat;
    }

    strip.appendChild(buildStat('Deployments', total));
    strip.appendChild(buildStat('Deployed', deployed));

    return strip;
  }

  function buildDeploymentCard(app, d) {
    const card = el('div');
    card.className = 'dw-deployment';

    const head = el('div');
    head.className = 'dw-deployment__head';

    const repo = el('span');
    repo.className = 'dw-deployment__repo';
    repo.textContent = d.repo;
    head.appendChild(repo);

    const pill = buildPill(d);
    head.appendChild(pill);
    const mode = el('span');
    mode.className = 'dw-pill dw-pill--mode' + (d.auto_deploy ? ' is-auto' : '');
    mode.textContent = d.auto_deploy ? 'Auto - every ' + (d.poll_interval || 5) + ' min' : 'Manual';
    head.appendChild(mode);
    card.appendChild(head);

    const meta = el('p');
    meta.className = 'dw-deployment__meta';
    meta.textContent = d.repo + '@' + d.branch + ' -> ' + d.target_type + '/' + d.target_slug;
    card.appendChild(meta);

    const footer = el('div');
    footer.className = 'dw-deployment__footer';

    const timeEl = el('span');
    timeEl.className = 'dw-deployment__time';
    timeEl.textContent = d.last_deployed_sha
      ? 'Last SHA: ' + d.last_deployed_sha.slice(0, 8)
      : 'Never deployed';
    footer.appendChild(timeEl);

    const actions = el('div');
    actions.className = 'dw-deployment__actions';

    actions.appendChild(buildDeployBtn(app, d, pill));
    actions.appendChild(buildRollbackBtn(app, d, pill));
    actions.appendChild(buildViewLogBtn(app, d));
    actions.appendChild(buildWebhookBtn(app, d, card));
    actions.appendChild(buildEditBtn(app, d));
    actions.appendChild(buildDeleteBtn(app, d, card));

    footer.appendChild(actions);
    card.appendChild(footer);

    return card;
  }

  function buildPill(d) {
    const pill = el('span');
    pill.className = 'dw-pill ' + pillClass(d);
    pill.textContent = pillLabel(d);
    return pill;
  }

  function pillClass(d) {
    if (!d.last_deployed_sha) { return 'is-never'; }
    return 'is-ok';
  }

  function pillLabel(d) {
    if (!d.last_deployed_sha) { return 'Never deployed'; }
    return 'Deployed';
  }

  function buildDeployBtn(app, d, pill) {
    const btn = elBtn('Deploy now', 'dw-btn--primary');
    const icon = el('span');
    icon.className = 'dashicons dashicons-controls-play';
    icon.setAttribute('aria-hidden', 'true');
    btn.insertBefore(icon, btn.firstChild);
    btn.addEventListener('click', function () {
      runAction(app, btn, pill, function () {
        return app.api('POST', 'deployments/' + d.id + '/deploy', { force: false });
      }, function () {
        renderDeployments(app);
      });
    });
    return btn;
  }

  function buildRollbackBtn(app, d, pill) {
    const btn = elBtn('Rollback', 'dw-btn--danger');
    const icon = el('span');
    icon.className = 'dashicons dashicons-undo';
    icon.setAttribute('aria-hidden', 'true');
    btn.insertBefore(icon, btn.firstChild);
    btn.addEventListener('click', function () {
      if (!confirm('Roll back ' + d.repo + '@' + d.branch + ' to the previous version?')) { return; }
      runAction(app, btn, pill, function () {
        return app.api('POST', 'deployments/' + d.id + '/rollback');
      }, function () {
        renderDeployments(app);
      }, 'Rolled back to the previous version.');
    });
    return btn;
  }

  function buildViewLogBtn(app, d) {
    const btn = elIconBtn('View log', 'dashicons-media-text', '');
    btn.addEventListener('click', function () {
      app.logTargetId = d.id;
      app.switchTab('activity-log');
    });
    return btn;
  }

  function buildWebhookBtn(app, d, card) {
    const btn = elIconBtn('Webhook setup', 'dashicons-admin-links', '');

    btn.addEventListener('click', function () {
      const existing = card.querySelector('.dw-webhook');
      if (existing) {
        card.removeChild(existing);
        return;
      }
      setInFlight(btn, true);
      app.api('GET', 'deployments/' + d.id + '/webhook').then(function (res) {
        setInFlight(btn, false);
        if (res.status !== 200) {
          app.showToast('is-error', (res.data && res.data.error) || 'Could not load webhook info.');
          return;
        }
        const panel = buildWebhookPanel(app, d, res.data.secret);
        card.appendChild(panel);
      }).catch(function () {
        setInFlight(btn, false);
        app.showToast('is-error', 'Network error loading webhook info.');
      });
    });

    return btn;
  }

  function buildWebhookPanel(app, d, secret) {
    const root = app.el.dataset.root;
    const payloadUrl = root + 'webhook/' + d.id;

    const panel = el('div');
    panel.className = 'dw-webhook';

    const titleEl = el('p');
    titleEl.className = 'dw-webhook__title';
    titleEl.textContent = 'GitHub Webhook Setup';
    panel.appendChild(titleEl);

    panel.appendChild(buildWebhookRow('Payload URL', payloadUrl));
    panel.appendChild(buildWebhookRow('Secret', secret));

    const fixedRow = el('div');
    fixedRow.className = 'dw-webhook__fixed';

    const ctLine = el('p');
    ctLine.className = 'dw-webhook__hint';
    ctLine.textContent = 'Content type: application/json';
    fixedRow.appendChild(ctLine);

    const evLine = el('p');
    evLine.className = 'dw-webhook__hint';
    evLine.textContent = 'Event: Just the push event';
    fixedRow.appendChild(evLine);

    panel.appendChild(fixedRow);
    return panel;
  }

  function buildWebhookRow(labelText, value) {
    const row = el('div');
    row.className = 'dw-webhook__row';

    const labelEl = el('label');
    labelEl.className = 'dw-label';
    labelEl.textContent = labelText;
    row.appendChild(labelEl);

    const valueWrap = el('div');
    valueWrap.className = 'dw-webhook__value-wrap';

    const valueEl = el('input');
    valueEl.type = 'text';
    valueEl.className = 'dw-webhook__value';
    valueEl.readOnly = true;
    valueEl.value = value;
    valueWrap.appendChild(valueEl);

    const copyBtn = elBtn('Copy', 'dw-copy');
    const copiedSpan = el('span');
    copiedSpan.className = 'dw-copied';
    copiedSpan.textContent = 'Copied';

    copyBtn.addEventListener('click', function () {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(function () {
          showCopied(copyBtn, copiedSpan);
        }).catch(function () {
          fallbackCopy(valueEl, copyBtn, copiedSpan);
        });
      } else {
        fallbackCopy(valueEl, copyBtn, copiedSpan);
      }
    });

    valueWrap.appendChild(copyBtn);
    valueWrap.appendChild(copiedSpan);
    row.appendChild(valueWrap);
    return row;
  }

  function showCopied(copyBtn, copiedSpan) {
    copyBtn.disabled = true;
    copiedSpan.classList.add('is-visible');
    setTimeout(function () {
      copyBtn.disabled = false;
      copiedSpan.classList.remove('is-visible');
    }, 1800);
  }

  function fallbackCopy(valueEl, copyBtn, copiedSpan) {
    valueEl.select();
    try {
      document.execCommand('copy');
      showCopied(copyBtn, copiedSpan);
    } catch (_) { /* silent: user can copy manually from the selected text */ }
  }

  function buildEditBtn(app, d) {
    const btn = elIconBtn('Edit', 'dashicons-edit', '');
    btn.addEventListener('click', function () {
      app.editingDeployment = d;
      app.switchTab('add-deployment');
    });
    return btn;
  }

  function buildDeleteBtn(app, d, card) {
    const btn = elIconBtn('Delete', 'dashicons-trash', 'dw-btn--danger');
    btn.addEventListener('click', function () {
      if (!confirm('Delete deployment for ' + d.repo + '? This cannot be undone.')) { return; }
      setInFlight(btn, true);
      app.api('DELETE', 'deployments/' + d.id).then(function (res) {
        setInFlight(btn, false);
        if (res.status === 200) {
          card.parentNode && card.parentNode.removeChild(card);
          app.showToast('is-ok', 'Deployment deleted.');
        } else {
          app.showToast('is-error', (res.data && res.data.error) || 'Delete failed.');
        }
      }).catch(function () {
        setInFlight(btn, false);
        app.showToast('is-error', 'Network error deleting deployment.');
      });
    });
    return btn;
  }

  function runAction(app, btn, pill, apiCall, onSuccess, successFallback) {
    setInFlight(btn, true);
    setPillDeploying(pill, true);

    apiCall().then(function (res) {
      setInFlight(btn, false);
      setPillDeploying(pill, false);
      if (res.status >= 200 && res.status < 300) {
        const msg = (res.data && res.data.message) || successFallback || 'Done.';
        app.showToast('is-ok', msg);
        onSuccess();
      } else {
        const errMsg = (res.data && (res.data.message || res.data.error)) || 'Done.';
        app.showToast('is-error', errMsg);
      }
    }).catch(function () {
      setInFlight(btn, false);
      setPillDeploying(pill, false);
      app.showToast('is-error', 'Network error.');
    });
  }

  function setPillDeploying(pill, deploying) {
    const prev = pill.dataset.prevClass || '';
    if (deploying) {
      pill.dataset.prevClass = pill.className.replace('dw-pill', '').trim();
      pill.className = 'dw-pill is-deploying';
      pill.textContent = 'Deploying';
    } else {
      pill.className = 'dw-pill ' + (prev || 'is-ok');
      pill.textContent = prev === 'is-never' ? 'Never deployed' : 'Deployed';
    }
  }

  /* -------------------------------------------------------------------------
     Add Deployment tab
     ---------------------------------------------------------------------- */

  function renderAddDeployment(app) {
    const d = app.editingDeployment;
    const isEdit = d !== null && d !== undefined;

    const wrap = el('div');
    wrap.className = 'dw-card';

    const heading = el('h2');
    heading.textContent = isEdit ? 'Edit Deployment' : 'Add Deployment';
    wrap.appendChild(heading);

    const form = el('form');
    form.className = 'dw-form' +
      (isEdit && d.visibility === 'private' ? ' is-private' : '') +
      (isEdit && d.auto_deploy ? ' is-auto' : '');

    const errorArea = el('div');

    /* Repo field */
    const repoField = buildField('repo', 'Repository', true);
    const repoInput = repoField.input;
    repoInput.className = 'dw-input';
    repoInput.placeholder = 'owner/repo or https://github.com/owner/repo';
    repoInput.value = isEdit ? d.repo : '';
    form.appendChild(repoField.wrapper);

    /* Visibility segmented control */
    const visField = el('div');
    visField.className = 'dw-field';
    const visLabel = el('label');
    visLabel.className = 'dw-label';
    visLabel.textContent = 'Visibility';
    visField.appendChild(visLabel);

    const segmented = buildSegmented(form, isEdit ? d.visibility : 'public');
    visField.appendChild(segmented);
    form.appendChild(visField);

    /* Token field (private only) */
    const tokenField = buildField('token', 'GitHub Token', false);
    tokenField.wrapper.className = 'dw-field dw-token-row';
    const tokenInput = tokenField.input;
    tokenInput.className = 'dw-input';
    tokenInput.type = 'password';
    tokenInput.placeholder = isEdit ? 'Leave blank to keep the saved token' : 'ghp_...';
    tokenInput.autocomplete = 'off';
    form.appendChild(tokenField.wrapper);

    /* Branch row */
    const branchField = el('div');
    branchField.className = 'dw-field';
    const branchLabel = el('label');
    branchLabel.className = 'dw-label';
    branchLabel.textContent = 'Branch';
    branchField.appendChild(branchLabel);

    const branchRow = el('div');
    branchRow.className = 'dw-branch-row';

    const branchSelect = el('select');
    branchSelect.className = 'dw-select';
    branchSelect.style.display = 'none';
    const branchFallback = el('input');
    branchFallback.type = 'text';
    branchFallback.className = 'dw-input';
    branchFallback.placeholder = 'e.g. main';
    branchFallback.value = isEdit ? d.branch : '';

    const fetchBtn = elBtn('Fetch branches', '');
    fetchBtn.type = 'button';

    const branchError = el('p');
    branchError.className = 'dw-error';

    fetchBtn.addEventListener('click', function () {
      const repo = repoInput.value.trim();
      const visibility = getVisibility(form);
      const token = tokenInput.value.trim();
      fetchBranches(app, repo, visibility, token, branchSelect, branchFallback, branchError, fetchBtn, isEdit ? d.branch : '');
    });

    branchRow.appendChild(branchFallback);
    branchRow.appendChild(branchSelect);
    branchRow.appendChild(fetchBtn);
    branchField.appendChild(branchRow);
    branchField.appendChild(branchError);
    form.appendChild(branchField);

    /* Target type */
    const typeField = buildField('target_type', 'Target Type', true);
    const typeSelect = el('select');
    typeSelect.className = 'dw-select';
    typeSelect.id = 'dw-target-type';
    ['plugin', 'theme', 'mu-plugin'].forEach(function (v) {
      const opt = el('option');
      opt.value = v;
      opt.textContent = v;
      if (isEdit && d.target_type === v) { opt.selected = true; }
      typeSelect.appendChild(opt);
    });
    typeField.wrapper.replaceChild(typeSelect, typeField.input);
    form.appendChild(typeField.wrapper);

    /* Target slug */
    const slugField = buildField('target_slug', 'Target Slug', false);
    const slugInput = slugField.input;
    slugInput.className = 'dw-input';
    slugInput.placeholder = 'Optional, defaults to the repo name';
    slugInput.value = isEdit ? d.target_slug : '';
    const slugHelp = el('p');
    slugHelp.className = 'dw-help';
    slugHelp.textContent = 'The folder name of the plugin or theme. Leave blank to use the repository name.';
    slugField.wrapper.appendChild(slugHelp);
    form.appendChild(slugField.wrapper);

    /* Auto deploy segmented control */
    const autoField = el('div');
    autoField.className = 'dw-field';
    const autoLabel = el('label');
    autoLabel.className = 'dw-label';
    autoLabel.textContent = 'Auto deploy';
    autoField.appendChild(autoLabel);

    const autoSegmented = buildAutoSegmented(form, isEdit && d.auto_deploy ? 'on' : 'off');
    autoField.appendChild(autoSegmented);

    const autoHelp = el('p');
    autoHelp.className = 'dw-help';
    autoHelp.textContent = 'When on, new commits on the watched branch deploy automatically: instantly via webhook, or checked on the schedule below. When off, nothing deploys until you click Deploy now.';
    autoField.appendChild(autoHelp);
    form.appendChild(autoField);

    /* Poll interval row: visible only when Auto deploy is Automatic */
    const intervalField = el('div');
    intervalField.className = 'dw-field dw-interval-row';
    const intervalLabel = el('label');
    intervalLabel.className = 'dw-label';
    intervalLabel.htmlFor = 'dw-poll-interval';
    intervalLabel.textContent = 'Check every';
    intervalField.appendChild(intervalLabel);

    const intervalSelect = el('select');
    intervalSelect.className = 'dw-select';
    intervalSelect.id = 'dw-poll-interval';
    [5, 15, 30, 60].forEach(function (minutes) {
      const opt = el('option');
      opt.value = String(minutes);
      opt.textContent = minutes + ' minutes';
      if (isEdit && Number(d.poll_interval) === minutes) { opt.selected = true; }
      intervalSelect.appendChild(opt);
    });
    intervalField.appendChild(intervalSelect);
    form.appendChild(intervalField);

    /* Inline error area */
    form.appendChild(errorArea);

    /* Form actions */
    const actions = el('div');
    actions.className = 'dw-form__actions';

    const submitBtn = elBtn(isEdit ? 'Save changes' : 'Save deployment', 'dw-btn--primary');
    submitBtn.type = 'submit';

    const cancelBtn = elBtn('Cancel', '');
    cancelBtn.type = 'button';
    cancelBtn.addEventListener('click', function () {
      app.editingDeployment = null;
      app.switchTab('deployments');
    });

    actions.appendChild(submitBtn);
    actions.appendChild(cancelBtn);
    form.appendChild(actions);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      handleFormSubmit(app, form, repoInput, typeSelect, slugInput, branchSelect, branchFallback, tokenInput, isEdit ? d : null, submitBtn, errorArea);
    });

    wrap.appendChild(form);
    app.panelWrap.appendChild(wrap);
  }

  function buildField(id, labelText, required) {
    const wrapper = el('div');
    wrapper.className = 'dw-field';
    const labelEl = el('label');
    labelEl.className = 'dw-label';
    labelEl.htmlFor = 'dw-' + id;
    labelEl.textContent = labelText;
    if (required) {
      const req = el('span');
      req.className = 'dw-required';
      req.textContent = '*';
      labelEl.appendChild(req);
    }
    wrapper.appendChild(labelEl);
    const input = el('input');
    input.id = 'dw-' + id;
    input.type = 'text';
    wrapper.appendChild(input);
    return { wrapper: wrapper, input: input };
  }

  function buildSegmented(form, initial) {
    const seg = el('div');
    seg.className = 'dw-segmented';

    const opts = [{ value: 'public', label: 'Public' }, { value: 'private', label: 'Private' }];
    opts.forEach(function (opt) {
      const radio = el('input');
      radio.type = 'radio';
      radio.className = 'dw-segmented__option';
      radio.name = 'dw-visibility';
      radio.id = 'dw-vis-' + opt.value;
      radio.value = opt.value;
      if (initial === opt.value) { radio.checked = true; }

      radio.addEventListener('change', function () {
        toggleClass(form, 'is-private', opt.value === 'private');
      });

      const labelEl = el('label');
      labelEl.className = 'dw-segmented__label';
      labelEl.htmlFor = 'dw-vis-' + opt.value;
      labelEl.textContent = opt.label;

      seg.appendChild(radio);
      seg.appendChild(labelEl);
    });

    return seg;
  }

  function getVisibility(form) {
    const checked = form.querySelector('input[name="dw-visibility"]:checked');
    return checked ? checked.value : 'public';
  }

  function buildAutoSegmented(form, initial) {
    const seg = el('div');
    seg.className = 'dw-segmented';

    const opts = [{ value: 'off', label: 'Manual' }, { value: 'on', label: 'Automatic' }];
    opts.forEach(function (opt) {
      const radio = el('input');
      radio.type = 'radio';
      radio.className = 'dw-segmented__option';
      radio.name = 'dw-auto';
      radio.id = 'dw-auto-' + opt.value;
      radio.value = opt.value;
      if (initial === opt.value) { radio.checked = true; }

      radio.addEventListener('change', function () {
        toggleClass(form, 'is-auto', opt.value === 'on');
      });

      const labelEl = el('label');
      labelEl.className = 'dw-segmented__label';
      labelEl.htmlFor = 'dw-auto-' + opt.value;
      labelEl.textContent = opt.label;

      seg.appendChild(radio);
      seg.appendChild(labelEl);
    });

    return seg;
  }

  function getAutoDeploy(form) {
    const checked = form.querySelector('input[name="dw-auto"]:checked');
    return checked ? checked.value === 'on' : false;
  }

  function fetchBranches(app, repo, visibility, token, branchSelect, branchFallback, branchError, fetchBtn, currentBranch) {
    if (!repo) {
      clearEl(branchError);
      branchError.textContent = 'Enter a repository first.';
      return;
    }
    clearEl(branchError);
    setInFlight(fetchBtn, true);

    const body = { repo: repo, visibility: visibility };
    if (visibility === 'private' && token) { body.token = token; }

    app.api('POST', 'branches', body).then(function (res) {
      setInFlight(fetchBtn, false);
      if (res.status === 200 && Array.isArray(res.data.branches)) {
        populateBranchSelect(branchSelect, branchFallback, res.data.branches, currentBranch);
      } else {
        const msg = (res.data && res.data.error) || 'Could not fetch branches.';
        branchError.textContent = msg;
        branchSelect.style.display = 'none';
        branchFallback.style.display = '';
      }
    }).catch(function () {
      setInFlight(fetchBtn, false);
      branchError.textContent = 'Network error fetching branches.';
      branchSelect.style.display = 'none';
      branchFallback.style.display = '';
    });
  }

  function populateBranchSelect(branchSelect, branchFallback, branches, currentBranch) {
    clearEl(branchSelect);
    branches.forEach(function (name) {
      const opt = el('option');
      opt.value = name;
      opt.textContent = name;
      if (name === currentBranch) { opt.selected = true; }
      branchSelect.appendChild(opt);
    });
    branchSelect.style.display = '';
    branchFallback.style.display = 'none';
  }

  function handleFormSubmit(app, form, repoInput, typeSelect, slugInput, branchSelect, branchFallback, tokenInput, editing, submitBtn, errorArea) {
    clearEl(errorArea);

    const repo   = repoInput.value.trim();
    const type   = typeSelect.value;
    const slug   = slugInput.value.trim();
    const vis    = getVisibility(form);
    const token  = tokenInput.value.trim();
    const branch = branchSelect.style.display !== 'none'
      ? branchSelect.value
      : branchFallback.value.trim();
    const auto = getAutoDeploy(form);
    const intervalEl = document.getElementById('dw-poll-interval');
    const pollInterval = intervalEl ? (parseInt(intervalEl.value, 10) || 5) : 5;

    if (slug !== '' && type === 'plugin' && slug === 'deployward') {
      const inlineErr = el('p');
      inlineErr.className = 'dw-error';
      inlineErr.textContent = 'Deployward cannot deploy itself as a target.';
      errorArea.appendChild(inlineErr);
      return;
    }

    const body = {
      repo: repo,
      branch: branch || 'main',
      visibility: vis,
      target_type: type,
      target_slug: slug,
      auto_deploy: auto,
      poll_interval: pollInterval,
    };
    if (editing) { body.id = editing.id; }
    if (token)   { body.token = token; }

    setInFlight(submitBtn, true);

    app.api('POST', 'deployments', body).then(function (res) {
      setInFlight(submitBtn, false);
      if (res.status === 200 || res.status === 201) {
        app.editingDeployment = null;
        app.showToast('is-ok', editing ? 'Deployment updated.' : 'Deployment saved.');
        app.switchTab('deployments');
      } else {
        const msg = (res.data && res.data.error) || 'Save failed.';
        const inlineErr = el('p');
        inlineErr.className = 'dw-error';
        inlineErr.textContent = msg;
        errorArea.appendChild(inlineErr);
      }
    }).catch(function () {
      setInFlight(submitBtn, false);
      const inlineErr = el('p');
      inlineErr.className = 'dw-error';
      inlineErr.textContent = 'Network error saving deployment.';
      errorArea.appendChild(inlineErr);
    });
  }

  /* -------------------------------------------------------------------------
     Activity Log tab
     ---------------------------------------------------------------------- */

  function renderActivityLog(app) {
    const wrap = el('div');
    wrap.className = 'dw-card';

    const heading = el('h2');
    heading.textContent = 'Activity Log';
    wrap.appendChild(heading);

    const selectorRow = el('div');
    selectorRow.className = 'dw-field';
    const selectorLabel = el('label');
    selectorLabel.className = 'dw-label';
    selectorLabel.htmlFor = 'dw-log-select';
    selectorLabel.textContent = 'Deployment';
    selectorRow.appendChild(selectorLabel);

    const depSelect = el('select');
    depSelect.className = 'dw-select';
    depSelect.id = 'dw-log-select';
    const placeholder = el('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose a deployment...';
    depSelect.appendChild(placeholder);
    selectorRow.appendChild(depSelect);
    wrap.appendChild(selectorRow);

    const logArea = el('div');
    wrap.appendChild(logArea);
    app.panelWrap.appendChild(wrap);

    let currentPage = 1;

    app.api('GET', 'deployments').then(function (res) {
      if (res.status !== 200) { return; }
      (res.data.deployments || []).forEach(function (d) {
        const opt = el('option');
        opt.value = d.id;
        opt.textContent = d.repo + ' (' + d.branch + ')';
        depSelect.appendChild(opt);
      });

      if (app.logTargetId) {
        depSelect.value = app.logTargetId;
        app.logTargetId = null;
        loadLog(app, depSelect.value, 1, logArea, currentPage, function (p) { currentPage = p; });
      }
    });

    depSelect.addEventListener('change', function () {
      currentPage = 1;
      clearEl(logArea);
      if (!depSelect.value) { return; }
      loadLog(app, depSelect.value, currentPage, logArea, currentPage, function (p) { currentPage = p; });
    });
  }

  function loadLog(app, depId, page, logArea, currentPage, setPage) {
    if (!depId) { return; }
    clearEl(logArea);
    const loading = buildSkeleton();
    logArea.appendChild(loading);

    app.api('GET', 'deployments/' + depId + '/log?page=' + page).then(function (res) {
      clearEl(logArea);
      if (res.status !== 200) {
        logArea.appendChild(buildToastEl('is-error', (res.data && res.data.error) || 'Failed to load log.'));
        return;
      }
      const entries = res.data.entries || [];
      if (entries.length === 0 && page === 1) {
        logArea.appendChild(buildEmptyState('No log entries yet.', 'Run a deployment to see activity here.'));
        return;
      }

      const logList = el('div');
      logList.className = 'dw-log';
      entries.forEach(function (entry) {
        logList.appendChild(buildLogRow(entry));
      });
      logArea.appendChild(logList);

      const pager = buildPager(page, entries.length, function (newPage) {
        setPage(newPage);
        loadLog(app, depId, newPage, logArea, newPage, setPage);
      });
      logArea.appendChild(pager);
    }).catch(function () {
      clearEl(logArea);
      logArea.appendChild(buildToastEl('is-error', 'Network error loading log.'));
    });
  }

  function buildLogRow(entry) {
    const row = el('div');
    row.className = 'dw-log__row';

    const timeEl = el('span');
    timeEl.className = 'dw-log__time';
    timeEl.textContent = entry.created_at || entry.time || '';
    row.appendChild(timeEl);

    const pill = el('span');
    pill.className = 'dw-pill ' + logStatusPill(entry.status);
    pill.textContent = entry.status || '';
    row.appendChild(pill);

    const shaEl = el('span');
    shaEl.className = 'dw-log__sha';
    shaEl.textContent = entry.sha ? entry.sha.slice(0, 8) : '';
    row.appendChild(shaEl);

    const msgEl = el('span');
    msgEl.className = 'dw-log__message';
    msgEl.textContent = entry.message || '';
    row.appendChild(msgEl);

    return row;
  }

  function logStatusPill(status) {
    if (status === 'success') { return 'is-ok'; }
    if (status === 'failed')  { return 'is-failed'; }
    if (status === 'skipped') { return 'is-never'; }
    return 'is-never';
  }

  function buildPager(page, count, onNavigate) {
    const pager = el('div');
    pager.className = 'dw-log__pager';

    const prevBtn = elBtn('Prev', '');
    prevBtn.disabled = page <= 1;
    prevBtn.addEventListener('click', function () { onNavigate(page - 1); });

    const label = el('span');
    label.className = 'dw-log__pager-label';
    label.textContent = 'Page ' + page;

    const nextBtn = elBtn('Next', '');
    nextBtn.disabled = count < LOG_PAGE_SIZE;
    nextBtn.addEventListener('click', function () { onNavigate(page + 1); });

    pager.appendChild(prevBtn);
    pager.appendChild(label);
    pager.appendChild(nextBtn);
    return pager;
  }

  /* -------------------------------------------------------------------------
     Getting Started tab
     ---------------------------------------------------------------------- */

  function renderGettingStarted(app) {
    const wrap = el('div');
    wrap.className = 'dw-getting-started';

    const heading = el('h2');
    heading.textContent = 'Getting Started';
    wrap.appendChild(heading);

    wrap.appendChild(buildFlow('Public Repository Flow', [
      { title: 'Open Add Deployment', desc: 'Click the "Add Deployment" tab at the top of this page.' },
      { title: 'Enter the repository', desc: 'Paste the public GitHub repository URL or owner/repo slug into the Repository field.' },
      { title: 'Pick a branch', desc: 'Click "Fetch branches" to load available branches, then choose the one you want to deploy from.' },
      { title: 'Choose a target', desc: 'Select target type (plugin, theme, or mu-plugin) and enter the folder name as the target slug.' },
      { title: 'Save and deploy', desc: 'Click "Save deployment", then click "Deploy now" on the Deployments tab to run the first deploy.' },
    ]));

    wrap.appendChild(buildFlow('Private Repository Flow', [
      { title: 'Open Add Deployment', desc: 'Click the "Add Deployment" tab at the top of this page.' },
      { title: 'Enter the repository', desc: 'Paste the private GitHub repository owner/repo slug.' },
      { title: 'Set visibility to Private', desc: 'Click the "Private" button in the segmented Visibility control to reveal the Token field.' },
      {
        title: 'Create a GitHub fine-grained token',
        desc: 'Go to GitHub Settings -> Developer settings -> Fine-grained personal access tokens. Create a token scoped to this repository with Contents: Read-only permission, and paste it in the Token field. It is stored encrypted on your server.',
      },
      { title: 'Pick a branch', desc: 'Click "Fetch branches" (the token will be used to list branches), then choose the branch to deploy.' },
      { title: 'Choose a target', desc: 'Select target type and enter the folder name as the target slug.' },
      { title: 'Save and deploy', desc: 'Click "Save deployment", then click "Deploy now" on the Deployments tab.' },
    ]));

    wrap.appendChild(buildFlow('Automatic deploys are off by default', [
      {
        title: 'Enable auto deploy per deployment',
        desc: 'Every new deployment starts as Manual: nothing deploys until you click Deploy now. Edit a deployment and switch Auto deploy to Automatic to deploy new commits automatically.',
      },
      {
        title: 'Pick how fast changes land',
        desc: 'With a GitHub webhook, pushes deploy within seconds. Without one, Deployward checks the branch on the interval you chose (every 5 to 60 minutes) and deploys any new commit.',
      },
    ]));

    wrap.appendChild(buildFlow('Automatic deploys (webhook)', [
      {
        title: 'Open Webhook setup',
        desc: 'On the Deployments tab, click "Webhook setup" on the deployment you want to auto-deploy.',
      },
      {
        title: 'Copy the Payload URL and Secret',
        desc: 'Click the Copy button next to each value and keep them ready.',
      },
      {
        title: 'Add the webhook in GitHub',
        desc: 'Go to your GitHub repo: Settings -> Webhooks -> Add webhook. Paste the Payload URL, set Content type to application/json, paste the Secret, choose "Just the push event", then click Add webhook.',
      },
      {
        title: 'Push to deploy',
        desc: 'Once Auto deploy is switched to Automatic for this deployment, every push to the watched branch deploys right away. Without a webhook, Deployward instead checks the branch on your chosen interval (5 to 60 minutes).',
      },
    ]));

    app.panelWrap.appendChild(wrap);
  }

  function buildFlow(title, steps) {
    const section = el('section');

    const titleEl = el('h3');
    titleEl.className = 'dw-flow-title';
    titleEl.textContent = title;
    section.appendChild(titleEl);

    const list = el('ol');
    list.className = 'dw-steps';

    steps.forEach(function (step, i) {
      const item = el('li');
      item.className = 'dw-step';

      const num = el('span');
      num.className = 'dw-step__number';
      num.setAttribute('aria-hidden', 'true');
      num.textContent = String(i + 1);

      const body = el('div');
      body.className = 'dw-step__body';

      const stepTitle = el('p');
      stepTitle.className = 'dw-step__title';
      stepTitle.textContent = step.title;

      const stepDesc = el('p');
      stepDesc.className = 'dw-step__desc';
      stepDesc.textContent = step.desc;

      body.appendChild(stepTitle);
      body.appendChild(stepDesc);
      item.appendChild(num);
      item.appendChild(body);
      list.appendChild(item);
    });

    section.appendChild(list);
    return section;
  }

  /* -------------------------------------------------------------------------
     Toast
     ---------------------------------------------------------------------- */

  DeploywardApp.prototype.showToast = function (type, message) {
    const toast = buildToastEl(type, message);
    this.toastArea.appendChild(toast);
    setTimeout(function () {
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    }, 5000);
  };

  function buildToastEl(type, message) {
    const toast = el('div');
    toast.className = 'dw-toast ' + type;
    const msg = el('span');
    msg.textContent = message;
    toast.appendChild(msg);
    const close = el('button');
    close.type = 'button';
    close.className = 'dw-toast__close';
    close.textContent = 'x';
    close.setAttribute('aria-label', 'Dismiss');
    close.addEventListener('click', function () {
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    });
    toast.appendChild(close);
    return toast;
  }

  /* -------------------------------------------------------------------------
     Empty state + loading skeleton
     ---------------------------------------------------------------------- */

  function buildEmptyState(title, desc) {
    const wrap = el('div');
    wrap.className = 'dw-empty';
    const icon = el('span');
    icon.className = 'dw-empty__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = 'deploy';
    wrap.appendChild(icon);
    const t = el('p');
    t.className = 'dw-empty__title';
    t.textContent = title;
    wrap.appendChild(t);
    const d = el('p');
    d.className = 'dw-empty__desc';
    d.textContent = desc;
    wrap.appendChild(d);
    return wrap;
  }

  function buildSkeleton() {
    const wrap = el('div');
    for (let i = 0; i < 3; i++) {
      const line = el('div');
      line.className = 'dw-skeleton';
      line.style.marginBottom = '12px';
      line.style.height = i === 0 ? '40px' : '24px';
      wrap.appendChild(line);
    }
    return wrap;
  }

  /* -------------------------------------------------------------------------
     In-flight state helpers
     ---------------------------------------------------------------------- */

  function setInFlight(btn, active) {
    btn.disabled = active;
    if (active) {
      const spin = el('span');
      spin.className = 'dw-spin';
      spin.setAttribute('data-spin', '1');
      btn.appendChild(spin);
    } else {
      const spin = btn.querySelector('[data-spin]');
      if (spin) { btn.removeChild(spin); }
    }
  }

  /* -------------------------------------------------------------------------
     DOM utilities
     ---------------------------------------------------------------------- */

  function el(tag) { return document.createElement(tag); }

  function elBtn(text, extraClass) {
    const btn = el('button');
    btn.type = 'button';
    btn.className = 'dw-btn' + (extraClass ? ' ' + extraClass : '');
    btn.textContent = text;
    return btn;
  }

  function elIconBtn(label, dashicon, extraClass) {
    const btn = el('button');
    btn.type = 'button';
    btn.className = 'dw-btn dw-btn--icon' + (extraClass ? ' ' + extraClass : '');
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
    const icon = el('span');
    icon.className = 'dashicons ' + dashicon;
    icon.setAttribute('aria-hidden', 'true');
    btn.appendChild(icon);
    return btn;
  }

  function clearEl(node) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
  }

  function toggleClass(node, cls, force) {
    if (force) {
      node.classList.add(cls);
    } else {
      node.classList.remove(cls);
    }
  }

}());
