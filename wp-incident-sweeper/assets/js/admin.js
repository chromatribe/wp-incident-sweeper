/**
 * WP Incident Sweeper — Admin Client Script
 * Zero-dependency, lightweight Vanilla JS with robust XSS defense
 */

document.addEventListener('DOMContentLoaded', function () {
  const root = document.querySelector('.wpis-app');
  if (!root) return;

  const nonce = wpisConfig.nonce;
  const ajaxUrl = wpisConfig.ajaxUrl;
  const adminUrl = wpisConfig.adminUrl;

  let scanData = {
    posts: [],
    users: [],
    summary: { totalPosts: 0, threatPosts: 0, totalUsers: 0, threatUsers: 0 }
  };

  // DOM Elements
  const btnScan = document.getElementById('wpis-btn-scan');
  const btnExportCsv = document.getElementById('wpis-btn-csv');
  const tabBtns = document.querySelectorAll('.wpis-tab-btn');
  const panels = document.querySelectorAll('.wpis-panel');

  // Posts UI
  const postsBody = document.getElementById('wpis-posts-tbody');
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

  // Run Scan
  async function runScan() {
    setLoading(true);
    try {
      const formData = new FormData();
      formData.append('action', 'wpis_run_scan');
      formData.append('_wpnonce', nonce);

      const res = await fetch(ajaxUrl, { method: 'POST', body: formData });
      const json = await res.json();

      if (!json.success) {
        alert('スキャンに失敗しました: ' + (json.data?.message || '不明なエラー'));
        return;
      }

      scanData = json.data;
      renderAll();
    } catch (err) {
      console.error(err);
      alert('スキャン中に通信エラーが発生しました。');
    } finally {
      setLoading(false);
    }
  }

  function setLoading(isLoading) {
    if (isLoading) {
      btnScan.disabled = true;
      btnScan.textContent = 'スキャン中...';
      postsBody.innerHTML = `<tr><td colspan="8" class="wpis-loading-state"><div class="wpis-spinner"></div><div>サイト内を高速クロール中（全投稿・固定ページ・ユーザー）...</div></td></tr>`;
      usersBody.innerHTML = `<tr><td colspan="7" class="wpis-loading-state"><div class="wpis-spinner"></div><div>ユーザーをスキャン中...</div></td></tr>`;
    } else {
      btnScan.disabled = false;
      btnScan.textContent = '再スキャン実行';
    }
  }

  // Render Everything
  function renderAll() {
    // Update Metrics
    metricTotalPosts.textContent = scanData.summary.totalPosts;
    metricThreatPosts.textContent = scanData.summary.threatPosts;
    metricTotalUsers.textContent = scanData.summary.totalUsers;
    metricThreatUsers.textContent = scanData.summary.threatUsers;

    document.getElementById('wpis-badge-posts-threat').textContent = scanData.summary.threatPosts;
    document.getElementById('wpis-badge-users-threat').textContent = scanData.summary.threatUsers;

    renderPosts();
    renderUsers();
  }

  // Render Posts Table
  function renderPosts() {
    const filterVal = postsFilter.value;
    const query = (postsSearch.value || '').toLowerCase().trim();

    const filtered = scanData.posts.filter(item => {
      if (filterVal === 'threat_high' && item.severity !== 'high') return false;
      if (filterVal === 'threat_all' && item.severity === 'clean') return false;
      if (filterVal === 'draft_trash' && !['draft', 'trash', 'pending', 'private'].includes(item.status)) return false;

      if (query) {
        const text = `${item.title} ${item.author_name} ${item.reasons.join(' ')}`.toLowerCase();
        if (!text.includes(query)) return false;
      }
      return true;
    });

    if (filtered.length === 0) {
      postsBody.innerHTML = `<tr><td colspan="8" class="wpis-empty-state">該当するコンテンツはありません（安全です）</td></tr>`;
      return;
    }

    postsBody.innerHTML = filtered.map(item => {
      let severityBadge = '<span class="wpis-badge wpis-badge-success">正常</span>';
      let rowClass = '';
      if (item.severity === 'high') {
        severityBadge = '<span class="wpis-badge wpis-badge-danger">🚨 危険（即削除推奨）</span>';
        rowClass = 'is-threat-danger';
      } else if (item.severity === 'medium') {
        severityBadge = '<span class="wpis-badge wpis-badge-warning">⚠️ 警告（要確認）</span>';
        rowClass = 'is-threat-warning';
      } else if (item.severity === 'low') {
        severityBadge = '<span class="wpis-badge wpis-badge-muted">ℹ️ 下書き/ゴミ箱</span>';
      }

      const statusMap = {
        publish: '<span class="wpis-badge wpis-badge-publish">公開</span>',
        draft: '<span class="wpis-badge wpis-badge-draft">下書き</span>',
        trash: '<span class="wpis-badge wpis-badge-trash">ゴミ箱</span>',
        pending: '<span class="wpis-badge wpis-badge-warning">保留</span>',
        private: '<span class="wpis-badge wpis-badge-muted">非公開</span>'
      };
      const statusBadge = statusMap[item.status] || `<span class="wpis-badge wpis-badge-muted">${escapeHtml(item.status)}</span>`;

      const reasonsHtml = item.reasons.length > 0
        ? item.reasons.map(r => `<span class="wpis-reason-tag">${escapeHtml(r)}</span>`).join(' ')
        : '<span style="color:#94a3b8;">-</span>';

      const safeId = parseInt(item.id, 10);
      const editUrl = `${adminUrl}post.php?post=${safeId}&action=edit`;

      return `
        <tr class="${rowClass}" data-id="${safeId}">
          <td style="text-align:center;"><input type="checkbox" class="wpis-post-check" value="${safeId}" data-severity="${escapeHtml(item.severity)}"></td>
          <td>${severityBadge}</td>
          <td>
            <strong><a href="${editUrl}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline;">${escapeHtml(item.title || '(タイトルなし)')}</a></strong>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px;">ID: ${safeId} | スラッグ: ${escapeHtml(item.slug || '-')}</div>
          </td>
          <td><span class="wpis-badge wpis-badge-muted">${escapeHtml(item.post_type)}</span></td>
          <td>${statusBadge}</td>
          <td><span class="${item.author_is_threat ? 'wpis-badge wpis-badge-danger' : ''}">${escapeHtml(item.author_name)}</span></td>
          <td style="white-space:nowrap;font-size:12px;">${escapeHtml(item.modified_date)}</td>
          <td>${reasonsHtml}</td>
        </tr>
      `;
    }).join('');

    bindPostCheckEvents();
  }

  function bindPostCheckEvents() {
    const checks = postsBody.querySelectorAll('.wpis-post-check');
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
    btnTrashSelected.textContent = count > 0 ? `選択した${count}件をゴミ箱へ` : '選択した項目をゴミ箱へ';
  }

  postsFilter.addEventListener('change', renderPosts);
  postsSearch.addEventListener('input', renderPosts);

  postsCheckAll.addEventListener('change', () => {
    const checks = postsBody.querySelectorAll('.wpis-post-check');
    checks.forEach(c => c.checked = postsCheckAll.checked);
    updatePostActionButtons();
  });

  btnSelectThreats.addEventListener('click', () => {
    const checks = postsBody.querySelectorAll('.wpis-post-check');
    checks.forEach(c => {
      c.checked = (c.dataset.severity === 'high' || c.dataset.severity === 'medium');
    });
    updatePostActionButtons();
  });

  async function executePostBulkAction(isPermanent) {
    const selected = Array.from(postsBody.querySelectorAll('.wpis-post-check:checked'))
      .map(c => parseInt(c.value, 10))
      .filter(id => !isNaN(id) && id > 0);

    if (selected.length === 0) return;

    const actionText = isPermanent ? '完全に削除（復元不可）' : 'ゴミ箱へ移動';
    if (!confirm(`選択された ${selected.length} 件の投稿・固定ページを【${actionText}】してもよろしいですか？`)) {
      return;
    }

    try {
      const formData = new FormData();
      formData.append('action', 'wpis_delete_posts');
      formData.append('_wpnonce', nonce);
      formData.append('post_ids', JSON.stringify(selected));
      formData.append('force_delete', isPermanent ? '1' : '0');

      const res = await fetch(ajaxUrl, { method: 'POST', body: formData });
      const json = await res.json();

      if (!json.success) {
        alert('削除処理に失敗しました: ' + (json.data?.message || '不明なエラー'));
        return;
      }

      alert(`${json.data.deleted_count} 件の処理が完了しました。`);
      runScan();
    } catch (err) {
      console.error(err);
      alert('削除処理中に通信エラーが発生しました。');
    }
  }

  btnDeleteSelected.addEventListener('click', () => executePostBulkAction(true));
  btnTrashSelected.addEventListener('click', () => executePostBulkAction(false));

  // Render Users Table
  function renderUsers() {
    if (scanData.users.length === 0) {
      usersBody.innerHTML = `<tr><td colspan="7" class="wpis-empty-state">ユーザーはいません</td></tr>`;
      return;
    }

    usersBody.innerHTML = scanData.users.map(u => {
      let badge = '<span class="wpis-badge wpis-badge-success">正常</span>';
      let rowClass = '';
      if (u.is_threat) {
        badge = '<span class="wpis-badge wpis-badge-danger">🚨 不審（即削除推奨）</span>';
        rowClass = 'is-threat-danger';
      }

      const safeId = parseInt(u.id, 10);

      return `
        <tr class="${rowClass}" data-id="${safeId}">
          <td style="text-align:center;"><input type="checkbox" class="wpis-user-check" value="${safeId}" data-threat="${u.is_threat}"></td>
          <td>${badge}</td>
          <td><strong>${escapeHtml(u.login)}</strong> (ID: ${safeId})</td>
          <td>${escapeHtml(u.email)}</td>
          <td><span class="wpis-badge wpis-badge-muted">${escapeHtml(u.roles.join(', '))}</span></td>
          <td>${escapeHtml(u.registered)}</td>
          <td>${u.threat_reasons.map(r => `<span class="wpis-reason-tag">${escapeHtml(r)}</span>`).join(' ') || '-'}</td>
        </tr>
      `;
    }).join('');

    bindUserCheckEvents();
  }

  function bindUserCheckEvents() {
    const checks = usersBody.querySelectorAll('.wpis-user-check');
    checks.forEach(c => c.addEventListener('change', updateUserActionButtons));
    updateUserActionButtons();
  }

  function updateUserActionButtons() {
    const selected = usersBody.querySelectorAll('.wpis-user-check:checked');
    btnDeleteUsers.disabled = selected.length === 0;
    btnDeleteUsers.textContent = selected.length > 0 ? `選択した${selected.length}名のユーザーを完全削除` : '選択したユーザーを完全削除';
  }

  usersCheckAll.addEventListener('change', () => {
    const checks = usersBody.querySelectorAll('.wpis-user-check');
    checks.forEach(c => c.checked = usersCheckAll.checked);
    updateUserActionButtons();
  });

  // Delete Users
  btnDeleteUsers.addEventListener('click', async () => {
    const selected = Array.from(usersBody.querySelectorAll('.wpis-user-check:checked'))
      .map(c => parseInt(c.value, 10))
      .filter(id => !isNaN(id) && id > 0);

    if (selected.length === 0) return;

    if (!confirm(`【警告】選択された ${selected.length} 名のユーザーを削除します。\n※該当ユーザーが作成した投稿・下書き等のコンテンツもすべて同時に完全削除されます。\n\n本当に実行しますか？`)) {
      return;
    }

    try {
      const formData = new FormData();
      formData.append('action', 'wpis_delete_users');
      formData.append('_wpnonce', nonce);
      formData.append('user_ids', JSON.stringify(selected));

      const res = await fetch(ajaxUrl, { method: 'POST', body: formData });
      const json = await res.json();

      if (!json.success) {
        alert('ユーザー削除に失敗しました: ' + (json.data?.message || '不明なエラー'));
        return;
      }

      alert(`${json.data.deleted_count} 名のユーザーをコンテンツとともに削除しました。`);
      runScan();
    } catch (err) {
      console.error(err);
      alert('ユーザー削除中に通信エラーが発生しました。');
    }
  });

  // Export CSV
  btnExportCsv.addEventListener('click', () => {
    if (scanData.posts.length === 0 && scanData.users.length === 0) {
      alert('エクスポートするスキャンデータがありません。先にスキャンを実行してください。');
      return;
    }

    let csv = '\uFEFF'; // UTF-8 BOM
    csv += 'タイプ,ID,タイトル/ログイン名,ステータス/ロール,作成者/メール,作成・登録日,判定,検知理由\n';

    scanData.posts.forEach(p => {
      csv += `投稿/固定ページ,${p.id},"${cleanCsv(p.title)}",${p.status},"${cleanCsv(p.author_name)}",${p.modified_date},${p.severity},"${cleanCsv(p.reasons.join('; '))}"\n`;
    });

    scanData.users.forEach(u => {
      csv += `ユーザー,${u.id},"${cleanCsv(u.login)}","${u.roles.join(';')}",${u.email},${u.registered},${u.is_threat ? 'danger' : 'clean'},"${cleanCsv(u.threat_reasons.join('; '))}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `incident_scan_report_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  });

  function cleanCsv(str) {
    if (!str) return '';
    return String(str).replace(/"/g, '""');
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

  // Scan on mount
  btnScan.addEventListener('click', runScan);
  runScan();
});
