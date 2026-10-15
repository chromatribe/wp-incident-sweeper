/**
 * WP Incident Sweeper — Admin Client Script
 * Full Single-site & Multisite adaptive UI with strict security controls
 */

document.addEventListener('DOMContentLoaded', function () {
  const root = document.querySelector('.wpis-app');
  if (!root) return;

  const nonce = wpisConfig.nonce;
  const ajaxUrl = wpisConfig.ajaxUrl;
  const adminUrl = wpisConfig.adminUrl;
  // ネットワーク管理画面（全傘下サイト横断モード）かどうかの厳密判定
  const isNetwork = (root.dataset.isNetwork === '1') || (!!wpisConfig.isNetwork);

  // i18n ローカライゼーションヘルパー
  const i18n = (window.wpisConfig && window.wpisConfig.i18n) || {};
  function __(key, fallback) {
    return (i18n && i18n[key] !== undefined) ? i18n[key] : fallback;
  }
  function sprintf(format, ...args) {
    let i = 0;
    return format.replace(/%[sd]/g, () => (args[i++] !== undefined ? args[i - 1] : ''));
  }

  let scanData = {
    posts: [],
    users: [],
    sites: [],
    summary: { totalSites: 1, totalPosts: 0, threatPosts: 0, totalUsers: 0, threatUsers: 0 }
  };

  // DOM Elements
  const btnScan = document.getElementById('wpis-btn-scan');
  const btnExportCsv = document.getElementById('wpis-btn-csv');
  const btnSyncKw = document.getElementById('wpis-btn-sync-kw');
  const btnMyKw = document.getElementById('wpis-btn-my-kw');
  const modalMyKw = document.getElementById('wpis-my-kw-modal');
  const btnCloseMyKw = document.getElementById('wpis-btn-close-my-kw');
  const btnCancelMyKw = document.getElementById('wpis-btn-cancel-my-kw');
  const btnSaveMyKw = document.getElementById('wpis-btn-save-my-kw');
  const inputMyKw = document.getElementById('wpis-my-kw-input');
  const tabBtns = document.querySelectorAll('.wpis-tab-btn');
  const panels = document.querySelectorAll('.wpis-panel');

  // Posts UI
  const postsBody = document.getElementById('wpis-posts-tbody');
  const postsSiteFilter = document.getElementById('wpis-posts-site-filter');
  const postsFilter = document.getElementById('wpis-posts-filter');
  const postsSearch = document.getElementById('wpis-posts-search');
  const postsCheckAll = document.getElementById('wpis-posts-check-all');
  const btnSelectThreats = document.getElementById('wpis-btn-select-threats');
  const btnDeleteSelected = document.getElementById('wpis-btn-delete-selected');
  const btnTrashSelected = document.getElementById('wpis-btn-trash-selected');

  // Users UI
  const usersBody = document.getElementById('wpis-users-tbody');
  const usersCheckAll = document.getElementById('wpis-users-check-all');
  const btnDeleteUsers = document.getElementById('wpis-btn-delete-users');

  // Metrics
  const metricTotalSites = document.getElementById('wpis-metric-total-sites');
  const metricTotalPosts = document.getElementById('wpis-metric-total-posts');
  const metricThreatPosts = document.getElementById('wpis-metric-threat-posts');
  const metricTotalUsers = document.getElementById('wpis-metric-total-users');
  const metricThreatUsers = document.getElementById('wpis-metric-threat-users');

  // Tab Switching
  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;
      tabBtns.forEach(b => b.classList.remove('is-active'));
      panels.forEach(p => p.classList.remove('is-active'));

      btn.classList.add('is-active');
      const targetPanel = document.getElementById(`wpis-panel-${target}`);
      if (targetPanel) targetPanel.classList.add('is-active');
    });
  });

  // Safe Ajax Request with HTML/Fatal Error detection
  async function safeFetchJson(formData) {
    const res = await fetch(ajaxUrl, { method: 'POST', body: formData });
    const rawText = await res.text();
    let json;
    try {
      json = JSON.parse(rawText);
    } catch (parseErr) {
      console.error('Non-JSON response:', rawText);
      const snippet = rawText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
      throw new Error(`サーバー通信エラー (HTTP ${res.status} ${res.statusText}):\n${snippet || '空または不正な応答'}`);
    }
    return json;
  }

  // Run Scan
  async function runScan() {
    setLoading(true);
    try {
      const formData = new FormData();
      formData.append('action', 'wpis_run_scan');
      formData.append('_wpnonce', nonce);
      formData.append('is_network', isNetwork ? '1' : '0');

      const json = await safeFetchJson(formData);

      if (!json.success) {
        alert('スキャンに失敗しました:\n' + (json.data?.message || '不明なエラー'));
        return;
      }

      scanData = json.data;
      renderAll();
    } catch (err) {
      console.error(err);
      alert('スキャン中にエラーが発生しました:\n' + (err.message || err));
    } finally {
      setLoading(false);
    }
  }

  function setLoading(isLoading) {
    const colSpan = isNetwork ? 9 : 8;
    if (isLoading) {
      btnScan.disabled = true;
      btnScan.innerHTML = '<span class="wpis-spinner" style="display:inline-block;width:13px;height:13px;margin-right:6px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:wpis-spin 0.8s linear infinite;vertical-align:-0.15em;"></span> ' + escapeHtml(isNetwork ? __( 'scan_running_network', '全傘下サイトを巡回クロール中...' ) : __( 'scan_running', 'スキャン中...' ));
      postsBody.innerHTML = `<tr><td colspan="${colSpan}" class="wpis-loading-state"><div class="wpis-spinner"></div><div>${isNetwork ? __( 'scanning_network', '全傘下サイトの全コンテンツ・ユーザーを横断クロール中...' ) : __( 'scanning_content', 'サイト内を高速クロール中（全投稿・固定ページ・ユーザー）...' )}</div></td></tr>`;
      usersBody.innerHTML = `<tr><td colspan="7" class="wpis-loading-state"><div class="wpis-spinner"></div><div>${__( 'scanning_users', 'ユーザーをスキャン中...' )}</div></td></tr>`;
    } else {
      btnScan.disabled = false;
      btnScan.innerHTML = '<svg class="wpis-lucide" viewBox="0 0 24 24" width="15" height="15"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg> ' + escapeHtml(isNetwork ? __( 'rescan_network', '全サイト横断スキャン実行' ) : __( 'rescan', '再スキャン実行' ));
    }
  }

  function renderAll() {
    if (isNetwork && metricTotalSites && scanData.summary.totalSites !== undefined) {
      metricTotalSites.textContent = sprintf( __( 'sites_count_suffix', '%d サイト' ), scanData.summary.totalSites );
    }
    metricTotalPosts.textContent = scanData.summary.totalPosts;
    metricThreatPosts.textContent = scanData.summary.threatPosts;
    metricTotalUsers.textContent = scanData.summary.totalUsers;
    metricThreatUsers.textContent = scanData.summary.threatUsers;

    document.getElementById('wpis-badge-posts-threat').textContent = scanData.summary.threatPosts;
    document.getElementById('wpis-badge-users-threat').textContent = scanData.summary.threatUsers;

    if (scanData.signatures) {
      updateSignatureDisplay(
        scanData.signatures.version,
        scanData.signatures.high_count,
        scanData.signatures.med_count,
        scanData.signatures.updated_at
      );
    }

    if (scanData.my_keywords_count !== undefined) {
      updateMyKeywordsCount(scanData.my_keywords_count);
    }

    // ネットワーク管理画面時のみサイト別フィルターを動的生成
    if (isNetwork && postsSiteFilter && scanData.sites && scanData.sites.length > 0) {
      const currentVal = postsSiteFilter.value;
      postsSiteFilter.innerHTML = '<option value="all">全傘下サイト</option>' +
        scanData.sites.map(s => `<option value="${s.id}">${escapeHtml(s.name)} (ID: ${s.id})</option>`).join('');
      postsSiteFilter.value = currentVal || 'all';
    }

    renderPosts();
    renderUsers();
  }

  // Render Posts Table
  function renderPosts() {
    const colSpan = isNetwork ? 9 : 8;
    const filterVal = postsFilter.value;
    const siteVal = (isNetwork && postsSiteFilter) ? postsSiteFilter.value : 'all';
    const query = (postsSearch.value || '').toLowerCase().trim();

    const filtered = scanData.posts.filter(item => {
      if (isNetwork && siteVal !== 'all' && String(item.blog_id) !== siteVal) return false;
      if (filterVal === 'threat_high' && item.severity !== 'high') return false;
      if (filterVal === 'threat_all' && item.severity === 'clean') return false;
      if (filterVal === 'draft_trash' && !['draft', 'trash', 'pending', 'private'].includes(item.status)) return false;

      if (query) {
        const text = `${item.title} ${item.blog_name || ''} ${item.author_name} ${item.reasons.join(' ')}`.toLowerCase();
        if (!text.includes(query)) return false;
      }
      return true;
    });

    if (filtered.length === 0) {
      postsBody.innerHTML = `<tr><td colspan="${colSpan}" class="wpis-empty-state">${escapeHtml(__( 'no_threats_found', '該当するコンテンツはありません（安全です）' ))}</td></tr>`;
      return;
    }

    postsBody.innerHTML = filtered.map(item => {
      let severityBadge = `<span class="wpis-badge wpis-badge-success">${escapeHtml(__( 'normal', '正常' ))}</span>`;
      let rowClass = '';
      if (item.severity === 'high') {
        severityBadge = `<span class="wpis-badge wpis-badge-danger">${escapeHtml(__( 'threat_danger', '🚨 危険（即削除推奨）' ))}</span>`;
        rowClass = 'is-threat-danger';
      } else if (item.severity === 'medium') {
        severityBadge = `<span class="wpis-badge wpis-badge-warning">${escapeHtml(__( 'threat_warning', '⚠️ 警告（要確認）' ))}</span>`;
        rowClass = 'is-threat-warning';
      } else if (item.severity === 'low') {
        severityBadge = `<span class="wpis-badge wpis-badge-muted">${escapeHtml(__( 'threat_muted', 'ℹ️ 下書き/ゴミ箱' ))}</span>`;
      }

      if (item.is_protected) {
        severityBadge = `<span class="wpis-badge wpis-badge-muted" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">🔒 ${escapeHtml(__( 'protected_notice', 'システム重要ページのため削除保護' ))}</span>`;
      }

      const statusMap = {
        publish: '<span class="wpis-badge wpis-badge-publish">公開</span>',
        draft: '<span class="wpis-badge wpis-badge-draft">下書き</span>',
        trash: '<span class="wpis-badge wpis-badge-trash">ゴミ箱</span>',
        pending: '<span class="wpis-badge wpis-badge-warning">保留</span>',
        private: '<span class="wpis-badge wpis-badge-muted">非公開</span>'
      };
      const statusBadge = statusMap[item.status] || `<span class="wpis-badge wpis-badge-muted">${escapeHtml(item.status)}</span>`;

      // 視認性の高い検知理由ピルバッジの生成
      const reasonsBadgesHtml = item.reasons.length > 0
        ? item.reasons.map(r => {
            let bg = '#fee2e2', color = '#991b1b', border = '#fecaca'; // danger
            if (r.startsWith('🎯')) {
              bg = '#faf5ff'; color = '#6b21a8'; border = '#e9d5ff'; // My KW (purple)
            } else if (r.startsWith('⚠️')) {
              bg = '#fef3c7'; color = '#92400e'; border = '#fde68a'; // warning
            } else if (r.startsWith('🔒')) {
              bg = '#e0f2fe'; color = '#0369a1'; border = '#bae6fd'; // info
            } else if (r.startsWith('ℹ️') || r.startsWith('非公開')) {
              bg = '#f1f5f9'; color = '#475569'; border = '#e2e8f0'; // muted
            }
            return `<span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;margin:2px 4px 2px 0;background:${bg};color:${color};border:1px solid ${border};line-height:1.4;">${escapeHtml(r)}</span>`;
          }).join('')
        : '<span style="color:#94a3b8;font-size:12px;">-</span>';

      const safeId = parseInt(item.id, 10);
      const safeBlogId = parseInt(item.blog_id || 1, 10);
      const rawEditUrl = item.edit_url || `${adminUrl}post.php?post=${safeId}&action=edit`;
      const safeEditUrl = (/^https?:\/\//i.test(rawEditUrl) || rawEditUrl.startsWith('/'))
        ? escapeHtml(rawEditUrl)
        : '#';

      const checkboxDisabled = item.is_protected ? 'disabled title="システム保護対象のため選択できません"' : '';

      // ネットワーク管理画面時のみ「対象サイト」セルを表示
      const siteCell = isNetwork
        ? `<td style="font-size:12px;font-weight:600;color:#0369a1;"><span class="wpis-badge wpis-badge-muted">${escapeHtml(item.blog_name || 'サイト:' + safeBlogId)}</span></td>`
        : '';

      return `
        <tr class="${rowClass}" data-id="${safeId}" data-blog-id="${safeBlogId}">
          <td style="text-align:center;">
            <input type="checkbox" class="wpis-post-check" value="${safeId}" data-blog-id="${safeBlogId}" data-severity="${escapeHtml(item.severity)}" data-status="${escapeHtml(item.status)}" ${checkboxDisabled}>
          </td>
          <td>${severityBadge}</td>
          ${siteCell}
          <td>
            <strong><a href="${safeEditUrl}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;font-size:13px;">${escapeHtml(item.title || '(タイトルなし)')}</a></strong>
            <div style="font-size:11px;color:#94a3b8;margin:2px 0 4px 0;">ID: ${safeId} | スラッグ: ${escapeHtml(item.slug || '-')}</div>
            <div class="wpis-title-reasons" style="display:flex;flex-wrap:wrap;align-items:center;margin-top:4px;">
              ${reasonsBadgesHtml}
            </div>
          </td>
          <td><span class="wpis-badge wpis-badge-muted">${escapeHtml(item.post_type)}</span></td>
          <td>${statusBadge}</td>
          <td><span class="${item.author_is_threat ? 'wpis-badge wpis-badge-danger' : ''}">${escapeHtml(item.author_name)}</span></td>
          <td style="white-space:nowrap;font-size:12px;">${escapeHtml(item.modified_date)}</td>
          <td>${reasonsBadgesHtml}</td>
        </tr>
      `;
    }).join('');

    bindPostCheckEvents();
  }

  function bindPostCheckEvents() {
    const checks = postsBody.querySelectorAll('.wpis-post-check:not([disabled])');
    checks.forEach(c => {
      c.addEventListener('change', updatePostActionButtons);
    });
    updatePostActionButtons();
  }

  function updatePostActionButtons() {
    const selected = postsBody.querySelectorAll('.wpis-post-check:checked');
    const count = selected.length;
    btnDeleteSelected.disabled = count === 0;
    btnTrashSelected.disabled = count === 0;
    btnDeleteSelected.textContent = count > 0 ? `選択した${count}件を完全削除` : '選択した項目を完全削除';
    btnTrashSelected.textContent = count > 0 ? `選択した${count}件をゴミ箱へ（安全）` : '選択した項目をゴミ箱へ（安全）';
  }

  if (postsSiteFilter) postsSiteFilter.addEventListener('change', renderPosts);
  postsFilter.addEventListener('change', renderPosts);
  postsSearch.addEventListener('input', renderPosts);

  postsCheckAll.addEventListener('change', () => {
    const checks = postsBody.querySelectorAll('.wpis-post-check:not([disabled])');
    checks.forEach(c => c.checked = postsCheckAll.checked);
    updatePostActionButtons();
  });

  btnSelectThreats.addEventListener('click', () => {
    const checks = postsBody.querySelectorAll('.wpis-post-check:not([disabled])');
    checks.forEach(c => {
      c.checked = (c.dataset.severity === 'high');
    });
    updatePostActionButtons();
  });

  // Execute Post Bulk Action
  async function executePostBulkAction(isPermanent) {
    const selected = Array.from(postsBody.querySelectorAll('.wpis-post-check:checked'))
      .map(c => ({
        id: parseInt(c.value, 10),
        blog_id: parseInt(c.dataset.blogId || 1, 10)
      }))
      .filter(item => !isNaN(item.id) && item.id > 0);

    if (selected.length === 0) return;

    if (isPermanent) {
      const defaultMsg = `【安全確認: 記事の完全削除】\n` +
                  `選択された %d 件のコンテンツをデータベースから【完全に消去】します。\n\n` +
                  `※この操作は元に戻せません。公開中の正規記事は自動的にスキップまたはゴミ箱へ退避されます。\n\n` +
                  `本当に実行しますか？`;
      const msg = sprintf( __( 'confirm_delete_posts', defaultMsg ), selected.length );
      if (!confirm(msg)) return;
    } else {
      const defaultTrash = `【確認: ゴミ箱への移動】\n選択された %d 件のコンテンツをゴミ箱へ移動します。\n（後から復元可能です）\n\n実行しますか？`;
      const msg = sprintf( __( 'confirm_trash_posts', defaultTrash ), selected.length );
      if (!confirm(msg)) {
        return;
      }
    }

    try {
      const formData = new FormData();
      formData.append('action', 'wpis_delete_posts');
      formData.append('_wpnonce', nonce);
      formData.append('items', JSON.stringify(selected));
      formData.append('force_delete', isPermanent ? '1' : '0');

      const json = await safeFetchJson(formData);

      if (!json.success) {
        alert('削除処理に失敗しました:\n' + (json.data?.message || '不明なエラー'));
        return;
      }

      let resMsg = `${json.data.deleted_count} 件の処理が完了しました。`;
      if (json.data.skipped_count > 0) {
        resMsg += `\n（※保護対象のシステムページ等 ${json.data.skipped_count} 件は安全のため自動スキップされました）`;
      }
      alert(resMsg);
      runScan();
    } catch (err) {
      console.error(err);
      alert('削除処理中にエラーが発生しました:\n' + (err.message || err));
    }
  }

  btnDeleteSelected.addEventListener('click', () => executePostBulkAction(true));
  btnTrashSelected.addEventListener('click', () => executePostBulkAction(false));

  // Render Users Table
  function renderUsers() {
    if (scanData.users.length === 0) {
      usersBody.innerHTML = `<tr><td colspan="7" class="wpis-empty-state">${escapeHtml(__( 'no_users_found', 'ユーザーはいません' ))}</td></tr>`;
      return;
    }

    usersBody.innerHTML = scanData.users.map(u => {
      let badge = `<span class="wpis-badge wpis-badge-success">${escapeHtml(__( 'normal', '正常' ))}</span>`;
      let rowClass = '';
      if (u.is_threat) {
        badge = `<span class="wpis-badge wpis-badge-danger">${escapeHtml(__( 'user_threat', '🚨 不審（即削除推奨）' ))}</span>`;
        rowClass = 'is-threat-danger';
      }

      const safeId = parseInt(u.id, 10);
      const isDisabled = (safeId === 1) ? 'disabled title="初期マスター管理者のため保護されています"' : '';

      const userReasonsHtml = u.threat_reasons.length > 0
        ? u.threat_reasons.map(r => {
            let bg = '#fee2e2', color = '#991b1b', border = '#fecaca';
            if (r.startsWith('🎯')) {
              bg = '#faf5ff'; color = '#6b21a8'; border = '#e9d5ff';
            }
            return `<span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;margin:2px 4px 2px 0;background:${bg};color:${color};border:1px solid ${border};line-height:1.4;">${escapeHtml(r)}</span>`;
          }).join('')
        : '<span style="color:#94a3b8;font-size:12px;">-</span>';

      return `
        <tr class="${rowClass}" data-id="${safeId}">
          <td style="text-align:center;">
            <input type="checkbox" class="wpis-user-check" value="${safeId}" data-threat="${u.is_threat}" ${isDisabled}>
          </td>
          <td>${badge}</td>
          <td>
            <strong>${escapeHtml(u.login)}</strong> (ID: ${safeId})
            <div style="margin-top:4px;display:flex;flex-wrap:wrap;">
              ${userReasonsHtml}
            </div>
          </td>
          <td>${escapeHtml(u.email)}</td>
          <td><span class="wpis-badge wpis-badge-muted">${escapeHtml(u.roles.join(', '))}</span></td>
          <td>${escapeHtml(u.registered)}</td>
          <td>${userReasonsHtml}</td>
        </tr>
      `;
    }).join('');

    bindUserCheckEvents();
  }

  function bindUserCheckEvents() {
    const checks = usersBody.querySelectorAll('.wpis-user-check:not([disabled])');
    checks.forEach(c => c.addEventListener('change', updateUserActionButtons));
    updateUserActionButtons();
  }

  function updateUserActionButtons() {
    const selected = usersBody.querySelectorAll('.wpis-user-check:checked');
    if (btnDeleteUsers) {
      btnDeleteUsers.disabled = selected.length === 0;
      btnDeleteUsers.textContent = selected.length > 0
        ? sprintf( __( 'delete_users_btn', '選択した%d名の不審ユーザーを削除' ), selected.length )
        : __( 'delete_users_btn_empty', '選択した不審ユーザーを削除' );
    }
  }

  usersCheckAll.addEventListener('change', () => {
    const checks = usersBody.querySelectorAll('.wpis-user-check:not([disabled])');
    checks.forEach(c => c.checked = usersCheckAll.checked);
    updateUserActionButtons();
  });

  // Delete Users with absolute safety
  if (btnDeleteUsers) {
    btnDeleteUsers.addEventListener('click', async () => {
    const selected = Array.from(usersBody.querySelectorAll('.wpis-user-check:checked'))
      .map(c => parseInt(c.value, 10))
      .filter(id => !isNaN(id) && id > 0);

    if (selected.length === 0) return;

    const defaultUserConfirm = `【安全確認: 不審ユーザーの削除】\n` +
                `選択された %d 名の不審ユーザーを削除します。\n\n` +
                `🛡️ 巻き込み防止ガード:\n` +
                `・不正な下書き・ゴミ箱記事のみが完全消去されます。\n` +
                `・公開中の正規記事は安全に保護されます。\n\n` +
                `実行しますか？`;
    const msg = sprintf( __( 'confirm_delete_users', defaultUserConfirm ), selected.length );

    if (!confirm(msg)) return;

    try {
      const formData = new FormData();
      formData.append('action', 'wpis_delete_users');
      formData.append('_wpnonce', nonce);
      formData.append('user_ids', JSON.stringify(selected));

      const json = await safeFetchJson(formData);

      if (!json.success) {
        alert('ユーザー削除に失敗しました:\n' + (json.data?.message || '不明なエラー'));
        return;
      }

      let resMsg = `${json.data.deleted_count} 名のユーザーの削除が完了しました。`;
      if (json.data.skipped_count > 0) {
        resMsg += `\n（※正規ユーザーや自身のアカウント ${json.data.skipped_count} 名は安全のため自動スキップされました）`;
      }
      alert(resMsg);
      runScan();
    } catch (err) {
      console.error(err);
      alert('ユーザー削除中にエラーが発生しました:\n' + (err.message || err));
    }
  });
  }

  // Export CSV (with CSV Injection protection)
  btnExportCsv.addEventListener('click', () => {
    if (scanData.posts.length === 0 && scanData.users.length === 0) {
      alert( __( 'no_export_data', 'エクスポートするスキャンデータがありません。先にスキャンを実行してください。' ) );
      return;
    }

    let csv = '\uFEFF';
    if (isNetwork) {
      csv += 'タイプ,対象サイト,ID,タイトル/ログイン名,ステータス/ロール,作成者/メール,作成・登録日,判定,検知理由\n';
    } else {
      csv += 'タイプ,ID,タイトル/ログイン名,ステータス/ロール,作成者/メール,作成・登録日,判定,検知理由\n';
    }

    scanData.posts.forEach(p => {
      const siteCol = isNetwork ? `"${cleanCsv(p.blog_name || '')}",` : '';
      csv += `投稿/固定ページ,${siteCol}${p.id},"${cleanCsv(p.title)}",${p.status},"${cleanCsv(p.author_name)}",${p.modified_date},${p.severity},"${cleanCsv(p.reasons.join('; '))}"\n`;
    });

    scanData.users.forEach(u => {
      const siteCol = isNetwork ? `"ネットワーク全体",` : '';
      csv += `ユーザー,${siteCol}${u.id},"${cleanCsv(u.login)}","${u.roles.join(';')}",${cleanCsv(u.email)},${u.registered},${u.is_threat ? 'danger' : 'clean'},"${cleanCsv(u.threat_reasons.join('; '))}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `incident_scan_report_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  });

  // CSVインジェクション防止（=, +, -, @ で始まる数式実行脆弱性を完全防御）
  function cleanCsv(val) {
    if (val === null || val === undefined) return '';
    let str = String(val).replace(/"/g, '""');
    // セル先頭が数式トリガー記号の場合、シングルクォートで無害化
    if (/^[=+\-@\t\r]/.test(str)) {
      str = "'" + str;
    }
    return str;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // GitHub検知シグネチャ同期
  if (btnSyncKw) {
    btnSyncKw.addEventListener('click', async () => {
      const originalText = btnSyncKw.innerHTML;
      btnSyncKw.disabled = true;
      btnSyncKw.innerHTML = '<span class="wpis-spinner" style="display:inline-block;width:14px;height:14px;margin-right:6px;border:2px solid #cbd5e1;border-top-color:#0284c7;border-radius:50%;animation:wpis-spin 0.8s linear infinite;"></span> 同期中...';

      try {
        const formData = new FormData();
        formData.append('action', 'wpis_sync_signatures');
        formData.append('_wpnonce', nonce);

        const json = await safeFetchJson(formData);
        if (!json.success) {
          alert('キーワード更新に失敗しました:\n' + (json.data?.message || '不明なエラー'));
          return;
        }

        const data = json.data;
        updateSignatureDisplay(data.version, data.counts.high, data.counts.medium, data.updated_at);
        alert(`✅ ${data.message}\nバージョン: ${data.version}\n危険KW: ${data.counts.high}件 / 警告KW: ${data.counts.medium}件`);
        // 最新キーワードで即時再スキャン
        runScan();
      } catch (err) {
        console.error(err);
        alert('キーワード同期通信エラー:\n' + (err.message || err));
      } finally {
        btnSyncKw.disabled = false;
        btnSyncKw.innerHTML = originalText;
      }
    });
  }

  function updateSignatureDisplay(version, highCount, medCount, updatedAt) {
    const elVer = document.getElementById('wpis-sig-version');
    const elHigh = document.getElementById('wpis-sig-high');
    const elMed = document.getElementById('wpis-sig-med');
    const elDate = document.getElementById('wpis-sig-date');
    if (elVer && version) elVer.textContent = version;
    if (elHigh && highCount !== undefined) elHigh.textContent = highCount;
    if (elMed && medCount !== undefined) elMed.textContent = medCount;
    if (elDate && updatedAt) elDate.textContent = updatedAt;
  }

  // My KW Modal & Save Logic
  if (btnMyKw && modalMyKw) {
    btnMyKw.addEventListener('click', () => {
      modalMyKw.style.display = 'flex';
      if (inputMyKw) inputMyKw.focus();
    });

    if (btnCloseMyKw) {
      btnCloseMyKw.addEventListener('click', () => {
        modalMyKw.style.display = 'none';
      });
    }

    if (btnCancelMyKw) {
      btnCancelMyKw.addEventListener('click', () => {
        modalMyKw.style.display = 'none';
      });
    }

    modalMyKw.addEventListener('click', (e) => {
      if (e.target === modalMyKw) {
        modalMyKw.style.display = 'none';
      }
    });

    if (btnSaveMyKw) {
      btnSaveMyKw.addEventListener('click', async () => {
        const originalText = btnSaveMyKw.innerHTML;
        btnSaveMyKw.disabled = true;
        btnSaveMyKw.innerHTML = '<span class="wpis-spinner" style="display:inline-block;width:14px;height:14px;margin-right:6px;border:2px solid #cbd5e1;border-top-color:#0284c7;border-radius:50%;animation:wpis-spin 0.8s linear infinite;"></span> 保存中...';

        try {
          const formData = new FormData();
          formData.append('action', 'wpis_save_my_keywords');
          formData.append('_wpnonce', nonce);
          formData.append('keywords', inputMyKw ? inputMyKw.value : '');

          const json = await safeFetchJson(formData);
          if (!json.success) {
            alert('マイKWの保存に失敗しました:\n' + (json.data?.message || '不明なエラー'));
            return;
          }

          const count = json.data?.count || 0;
          updateMyKeywordsCount(count);
          modalMyKw.style.display = 'none';
          alert(`✅ ${json.data?.message || 'マイKWを保存しました！'}（登録数: ${count}件）\n最新のキーワード設定で再スキャンを実行します。`);
          runScan();
        } catch (err) {
          console.error(err);
          alert('通信エラーが発生しました:\n' + (err.message || err));
        } finally {
          btnSaveMyKw.disabled = false;
          btnSaveMyKw.innerHTML = originalText;
        }
      });
    }
  }

  function updateMyKeywordsCount(count) {
    const badge = document.getElementById('wpis-badge-my-kw');
    const btnCount = document.getElementById('wpis-my-kw-btn-count');
    if (badge) badge.textContent = count;
    if (btnCount) btnCount.textContent = count;
  }

  btnScan.addEventListener('click', runScan);
  runScan();
});
